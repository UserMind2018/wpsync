package push

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/sitelock"
	"github.com/usermind/wpsync/internal/sites"
	"github.com/usermind/wpsync/internal/staging"
)

// MinAgent is the first agent version with the push endpoints.
const MinAgent = "0.4.0"

// Targets of a push (Spec 2b 5.8). The agent takes only the word; the directory is its own.
const (
	TargetLive    = "live"
	TargetStaging = "staging"
)

// Options for push, rollback and the push log.
type Options struct {
	Site      sites.Site
	Secret    string
	SitesRoot string

	Units              []string // empty: every unit with local changes
	Force              bool     // overwrite although the server changed since the last pull
	Yes                bool     // do not ask before pushing
	AllowVersionChange bool     // with Yes: accept a changed plugin or theme version
	DryRun             bool     // only show the plan

	// Target: live or staging. Empty means live for a push; for a rollback it means "not named":
	// without a push ID the newest live push, with one the target of that push (V10).
	Target string
	Event  func(name string, data any) // --json: plan, upload, commit, health – in this order
	Report *Result                     // filled when Run or Rollback returns, also with an error

	Confirm func(string) bool // asks the user; nil without a terminal and with --json
	Out     io.Writer

	// For tests; zero values are replaced in defaults().
	HTTP       *http.Client                        // health check and rescue.php
	Client     *agentapi.Client                    // signed agent requests
	Sleep      func(time.Duration)                 // pauses of the health check
	Commit     func(siteDir, message string) error // internal git
	ChunkBytes int                                 // raw bytes per upload request
	// Access redeems a login link of the staging copy; nil: staging.Access.
	Access func(c *agentapi.Client, hc *http.Client, siteURL string) (base string, cookie *http.Cookie, err error)
}

// Result is the data of push --json and rollback --json.
type Result struct {
	PushID string `json:"push_id"` // empty until the agent has created the push
	Target string `json:"target"`  // live or staging; empty only for a rollback whose target nobody could name
	// Status: dry_run, confirmed, rolled_back, committed (swapped, neither confirmed nor rolled
	// back – the site needs attention) or empty (nothing on the site changed).
	Status string   `json:"status"`
	Units  []string `json:"units"`
}

var (
	// ErrNoBaseline: push compares against the last pull.
	ErrNoBaseline = errors.New("noch kein Pull")
	// ErrNothing: no unit has local changes.
	ErrNothing = errors.New("nichts zu pushen")
	// ErrAborted: the user declined.
	ErrAborted = errors.New("abgebrochen")
	// ErrConflict: the server changed since the last pull (P5).
	ErrConflict = errors.New("auf dem Server geändert seit dem letzten Pull")
	// ErrWindowClosed: an administrator has to open the push window (P2, P3).
	ErrWindowClosed = errors.New("das Push-Fenster ist geschlossen")
	// ErrNotWritable: the web server may not replace the directory (P14).
	ErrNotWritable = errors.New("der Webserver darf das Verzeichnis nicht ersetzen")
	// ErrAgentTooOld: the agent has no push endpoints.
	ErrAgentTooOld = errors.New("der Agent auf der Site kann noch nicht pushen")
	// ErrVersionChange: a changed version needs its own confirmation (P9).
	ErrVersionChange = errors.New("Versionswechsel braucht eine eigene Bestätigung")
	// ErrNeedsYes: a question without a terminal or with --json (exit code usage).
	ErrNeedsYes = errors.New("ohne Terminal mit --yes bestätigen")
	// ErrAgentNoStaging: the agent cannot push to staging (agent < 0.5.0).
	ErrAgentNoStaging = errors.New("der Agent auf der Site kennt noch kein Staging")
	// ErrTarget: --to names something else than live or staging. Nothing falls back to live.
	ErrTarget = errors.New("unbekanntes Ziel – erlaubt sind live und staging")
	// ErrTargetMismatch: the agent answers for another target than the one asked for, or a push
	// belongs to another target than the one named.
	ErrTargetMismatch = errors.New("das Ziel stimmt nicht")
)

// TargetError: the push belongs to another target than --to names. A rollback never crosses
// from staging to live or back.
type TargetError struct{ PushID, Is, Want string }

func (e *TargetError) Error() string {
	return fmt.Sprintf("Push %s ging nach %s, nicht nach %s", ShowID(e.PushID), targetLabel(e.Is), targetLabel(e.Want))
}

func (e *TargetError) Unwrap() error { return ErrTargetMismatch }

// PendingError: an earlier push was swapped in but never confirmed (U7).
type PendingError struct{ PushID, Device string }

func (e *PendingError) Error() string {
	return fmt.Sprintf("Push %s (von %s) ist getauscht, aber nicht bestätigt", ShowID(e.PushID), agentapi.Printable(e.Device))
}

// SkippedNewError: nothing to push because the only candidates were new units,
// which go out only when named (U14). It matches ErrNothing.
type SkippedNewError struct{ Units []string }

func (e *SkippedNewError) Error() string {
	return fmt.Sprintf("%v – neu und nicht genannt: %s", ErrNothing, strings.Join(e.Units, ", "))
}

func (e *SkippedNewError) Unwrap() error { return ErrNothing }

// RolledBackError: the push made the site worse and was taken back.
type RolledBackError struct {
	PushID     string
	Reasons    []string // what got worse after the swap
	StillWorse []string // what is still worse after the rollback; normally empty
}

func (e *RolledBackError) Error() string {
	return fmt.Sprintf("Push %s zurückgerollt: %s", e.PushID, strings.Join(e.Reasons, "; "))
}

func (o Options) defaults() Options {
	if o.HTTP == nil {
		o.HTTP = &http.Client{Timeout: 30 * time.Second}
	}
	if o.Client == nil {
		o.Client = agentapi.New(o.Site.URL, o.Site.KeyID, o.Secret, o.Site.RPS)
	}
	if o.Sleep == nil {
		o.Sleep = time.Sleep
	}
	if o.Commit == nil {
		out := o.Out
		if out == nil {
			out = io.Discard
		}
		// The snapshot repo lives next to the site folder (localgit.GitDir), never inside it.
		// It runs under the site lock (Run, Rollback), so git locks of a killed run are cleared first.
		o.Commit = func(siteDir, message string) error {
			if err := localgit.ClearStaleLocks(localgit.GitDir(filepath.Dir(siteDir), filepath.Base(siteDir))); err != nil {
				return err
			}
			return localgit.Commit(filepath.Dir(siteDir), filepath.Base(siteDir), message, out)
		}
	}
	if o.ChunkBytes <= 0 {
		o.ChunkBytes = 3 << 20
	}
	if o.Access == nil {
		o.Access = staging.Access
	}
	return o
}

// checkTarget refuses a target that is neither named nor known; a typo must never mean live.
func (o Options) checkTarget() error {
	switch o.Target {
	case "", TargetLive, TargetStaging:
		return nil
	}
	return fmt.Errorf("%w (%s)", ErrTarget, agentapi.Printable(o.Target))
}

func (o Options) target() string {
	if o.Target == TargetStaging {
		return TargetStaging
	}
	return TargetLive
}

func (o Options) event(name string, data any) {
	if o.Event != nil {
		o.Event(name, data)
	}
}

func targetLabel(target string) string {
	switch target {
	case "", TargetLive:
		return "Live"
	case TargetStaging:
		return "Staging"
	}
	return agentapi.Printable(target)
}

// answeredFor checks the target in the agent's answer against the one asked for. An agent
// before 0.5.0 names none and knows only live.
func answeredFor(want, got string) error {
	if got == want || (got == "" && want == TargetLive) {
		return nil
	}
	return fmt.Errorf("%w: angefordert %s, der Agent antwortet für %s – nichts übertragen", ErrTargetMismatch, targetLabel(want), targetLabel(got))
}

// agentError ties refusals of a push to staging to the staging errors; the agent's message stays.
func agentError(target string, err error) error {
	var apiErr *agentapi.APIError
	if target != TargetStaging || !errors.As(err, &apiErr) {
		return err
	}
	switch apiErr.Code {
	case "wpsync_push_target": // agent 0.4.0 knows only live
		return fmt.Errorf("%w: %w", ErrAgentNoStaging, err)
	case "wpsync_staging_missing":
		return fmt.Errorf("%w: %w", staging.ErrMissing, err)
	case "wpsync_staging_locked":
		return fmt.Errorf("%w: %w", staging.ErrLocked, err)
	case "wpsync_staging_busy":
		return fmt.Errorf("%w: %w", staging.ErrBusy, err)
	}
	return err
}

// healthPages returns the pages of the health check. For staging it redeems a login link first –
// once per push, it is a one-time link – and keeps only pages inside the copy: the check sees
// the copy like a visitor with access and uses nothing but the access cookie (Spec 2b 6.2, V20).
func (o Options) healthPages(agentURLs []string) (*copyAccess, []string, error) {
	if o.target() != TargetStaging {
		urls, dropped := HealthURLs(o.Site.URL, agentURLs, o.Site.HealthURLs)
		for _, u := range dropped {
			fmt.Fprintf(o.Out, "  ! Health-Seite %s verworfen – nur http(s) und beim Agenten nur Seiten der gekoppelten Site\n", agentapi.Printable(u))
		}
		return nil, urls, nil
	}
	base, cookie, err := o.Access(o.Client, o.HTTP, o.Site.URL)
	if err != nil {
		return nil, nil, fmt.Errorf("Zugang zur Staging-Kopie für den Health-Check: %w", err)
	}
	acc, err := newCopyAccess(o.Site.URL, base, cookie)
	if err != nil {
		return nil, nil, err
	}
	// Front page and login of the copy are checked whatever the agent names.
	own := append([]string{acc.base + "/", acc.base + "/wp-login.php"}, stagingPages(o.Site.URL, acc.base, o.Site.HealthURLs)...)
	all, dropped := HealthURLs(o.Site.URL, agentURLs, own)
	var urls []string
	for _, u := range all {
		if acc.inside(u) {
			urls = append(urls, u)
		} else {
			dropped = append(dropped, u)
		}
	}
	for _, u := range dropped {
		fmt.Fprintf(o.Out, "  ! Health-Seite %s verworfen – bei einem Push nach Staging nur Seiten der Kopie\n", agentapi.Printable(u))
	}
	return acc, urls, nil
}

// planEvent is the plan of push --json. Versions and conflicts are the agent's words, unescaped.
func planEvent(units []Unit, plan *agentapi.PushBegin, target string) map[string]any {
	list := make([]map[string]any, len(units))
	for i, u := range units {
		p := plan.Units[i]
		conflicts := p.Conflicts
		if conflicts == nil {
			conflicts = []string{}
		}
		list[i] = map[string]any{
			"path": u.Path, "exists": p.Exists, "files": len(u.Files), "upload": len(p.Need),
			"old_version": p.Version, "new_version": u.Version, "conflicts": conflicts, "writable": p.Writable,
		}
	}
	return map[string]any{"target": target, "window_open": plan.WindowOpen, "units": list}
}

// lock takes the site lock shared with pull (Nach-Review M-1): one pull, push or rollback per site.
func lock(o Options) (func(), error) {
	l, err := sitelock.Acquire(sitelock.Path(o.SitesRoot, o.Site.Name, ""))
	if err != nil {
		return nil, localenv.Wrap("lock", err)
	}
	return func() { l.Close() }, nil
}

// pause keeps the health check as gentle as the pull: one request per 1/RPS seconds.
func (o Options) pause() {
	rps := o.Site.RPS
	if rps <= 0 {
		rps = 1
	}
	o.Sleep(time.Duration(float64(time.Second) / rps))
}

// AtLeast compares dotted versions numerically ("0.10.0" ≥ "0.4.0"); unreadable versions fail.
func AtLeast(version, minimum string) bool {
	parse := func(v string) ([]int, bool) {
		v, _, _ = strings.Cut(v, "-")
		var out []int
		for _, part := range strings.Split(v, ".") {
			n, err := strconv.Atoi(part)
			if err != nil {
				return nil, false
			}
			out = append(out, n)
		}
		return out, true
	}
	have, ok := parse(version)
	want, _ := parse(minimum)
	if !ok {
		return false
	}
	for i := range want {
		h := 0
		if i < len(have) {
			h = have[i]
		}
		if h != want[i] {
			return h > want[i]
		}
	}
	return true
}

// Run pushes the locally changed units of a site (Spec Stufe 2, 6.3) – to live or, with Target
// staging, into the staging copy (Spec 2b 5.8, 6.2).
func Run(o Options) error {
	if err := o.checkTarget(); err != nil {
		return err
	}
	unlock, err := lock(o)
	if err != nil {
		return err
	}
	defer unlock()
	o = o.defaults()
	target := o.target()
	report := &Result{Target: target, Units: []string{}}
	if o.Report != nil {
		defer func() { *o.Report = *report }()
	}
	siteDir := filepath.Join(o.SitesRoot, o.Site.Name)
	docroot := filepath.Join(siteDir, "public")

	base, err := baseline.Load(siteDir)
	if err != nil {
		return fmt.Errorf("load baseline: %w", err)
	}
	if base.Empty() {
		return ErrNoBaseline
	}
	all, deleted, links, err := scan(docroot, base)
	if err != nil {
		return err
	}
	if err := namedLinks(docroot, links, o.Units, o.Out); err != nil {
		return err
	}
	units, err := selectUnits(all, o.Units, o.Out)
	if err != nil {
		return err
	}
	for _, unit := range deleted {
		fmt.Fprintf(o.Out, "  Hinweis: %s fehlt lokal – ein Push löscht nie, auf der Site bleibt es bestehen.\n", unit)
	}
	var skipped []string
	if len(o.Units) == 0 {
		// Without named units, units missing from the baseline stay local – whether the
		// site has them or not. They are either stale copies outside the pull profile or
		// something new that should go out on purpose (U14).
		var kept []Unit
		for _, u := range units {
			if u.New {
				fmt.Fprintf(o.Out, "  übersprungen: %s – neu, nur mit ausdrücklicher Nennung: wpsync push %s code %s\n", u.Path, o.Site.Name, u.Path)
				skipped = append(skipped, u.Path)
				continue
			}
			kept = append(kept, u)
		}
		units = kept
	}
	if len(units) == 0 {
		if len(skipped) > 0 {
			return &SkippedNewError{Units: skipped}
		}
		return ErrNothing
	}
	req := agentapi.PushBeginRequest{Target: target, Force: o.Force, Dry: true}
	names := make([]string, len(units))
	for i := range units {
		if err := units[i].Hash(docroot); err != nil {
			return err
		}
		req.Units = append(req.Units, units[i].Request())
		names[i] = units[i].Path
	}
	report.Units = names

	plan, err := o.Client.PushBegin(req)
	if err != nil {
		return agentError(target, err)
	}
	if !AtLeast(plan.AgentVersion, MinAgent) {
		return ErrAgentTooOld
	}
	if target == TargetStaging && !AtLeast(plan.AgentVersion, staging.MinAgent) {
		return ErrAgentNoStaging
	}
	if err := answeredFor(target, plan.Target); err != nil {
		return err
	}
	if plan.Pending != nil {
		return &PendingError{PushID: plan.Pending.PushID, Device: plan.Pending.Device}
	}
	if len(plan.Units) != len(units) {
		return errors.New("der Agent hat nicht jede Einheit beantwortet")
	}
	if target == TargetStaging {
		fmt.Fprintln(o.Out, "Ziel: Staging-Kopie (Live bleibt unverändert, die Baseline auch)")
	}
	conflict, readonly, versionChange := printPlan(o.Out, units, plan)
	o.event("plan", planEvent(units, plan, target))
	if readonly {
		return ErrNotWritable
	}
	if conflict && !o.Force {
		return ErrConflict
	}
	if o.DryRun {
		report.Status = "dry_run"
		return nil
	}
	if !plan.WindowOpen {
		return ErrWindowClosed
	}
	if versionChange {
		const question = "Die Versionsnummer ändert sich. Ein Plugin kann dabei die Datenbank umbauen – das nimmt ein Rollback nicht zurück. Trotzdem pushen?"
		switch {
		case o.Yes && o.AllowVersionChange:
		case o.Yes || o.Confirm == nil:
			return ErrVersionChange
		case !o.Confirm(question):
			return ErrAborted
		}
	}
	if !o.Yes {
		if o.Confirm == nil {
			return ErrNeedsYes
		}
		where := o.Site.URL
		if target == TargetStaging {
			where = "in die Staging-Kopie von " + o.Site.URL
		} else {
			where = "nach " + where
		}
		if !o.Confirm(fmt.Sprintf("%d Einheit(en) %s pushen?", len(units), where)) {
			return ErrAborted
		}
	}

	if err := RescueAllowed(o.Site.URL, plan.Rescue.URL); err != nil {
		return err
	}
	if err := RescuePing(o.HTTP, plan.Rescue.URL); err != nil {
		return err
	}
	acc, urls, err := o.healthPages(plan.HealthURLs)
	if err != nil {
		return err
	}
	before := check(o.HTTP, urls, o.pause, acc)

	req.Dry = false
	begin, err := o.Client.PushBegin(req)
	if err != nil {
		return agentError(target, err)
	}
	// Before the first byte travels: the push the agent created must be the one asked for.
	if err := answeredFor(target, begin.Target); err != nil {
		return err
	}
	if len(begin.Units) != len(units) {
		return errors.New("der Agent hat nicht jede Einheit beantwortet")
	}
	journal := NewJournal(begin.PushID, plan.Rescue.URL, begin.Rescue.Salt, base, names)
	journal.Target = target
	if err := SaveJournal(siteDir, journal); err != nil {
		return err
	}
	report.PushID = begin.PushID
	for i := range units {
		if err := upload(o, begin.PushID, i, docroot, &units[i], begin.Units[i].Need); err != nil {
			return fmt.Errorf("Upload abgebrochen, auf der Site wurde nichts geändert: %w", err)
		}
		o.event("upload", map[string]any{"unit": units[i].Path, "files": len(begin.Units[i].Need)})
	}
	stamps, err := o.Client.PushCommit(begin.PushID)
	if err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Code != "" {
			return agentError(target, err) // the agent refused and left the site as it was
		}
		report.Status = "committed" // unknown; the worse case
		return fmt.Errorf("der Tausch wurde nicht bestätigt, der Stand ist unklar – prüfen mit: wpsync pushes %s (%w)", o.Site.Name, err)
	}
	report.Status = "committed"
	o.event("commit", map[string]any{"push_id": begin.PushID})
	fmt.Fprintln(o.Out, "  getauscht – prüfe die Site …")

	after := check(o.HTTP, urls, o.pause, acc)
	for attempt := 0; attempt < 2 && len(Worse(before, after)) > 0; attempt++ {
		o.Sleep(2 * time.Second) // caches and opcache may need a moment
		after = check(o.HTTP, urls, o.pause, acc)
	}
	worse := Worse(before, after)
	o.event("health", map[string]any{"pages": len(urls), "worse": append([]string{}, worse...)})
	if len(worse) > 0 {
		return rolledBack(report, rollbackNow(o, acc, journal, urls, before, worse))
	}
	if err := o.Client.PushConfirm(begin.PushID); err != nil {
		rbErr := rollbackNow(o, acc, journal, urls, before, []string{"der Agent antwortet nach dem Tausch nicht mehr (" + err.Error() + ")"})
		if !errors.Is(rbErr, ErrRescueConfirmed) {
			return rolledBack(report, rbErr)
		}
		// confirm went through, only its answer was lost; the health check had passed.
		fmt.Fprintln(o.Out, "  rescue.php meldet den Push als bereits bestätigt – nur die Antwort ging verloren, er bleibt bestehen")
	}
	report.Status = "confirmed"

	if target == TargetStaging {
		// The baseline describes live: a push to staging changes neither it nor the internal git
		// (Spec 2b 6.2). The next push to live uploads the same state and checks it against live.
		for _, file := range leftOut(units, stamps) {
			why := ""
			if strings.EqualFold(path.Base(file), ".htaccess") {
				why = " (eine .htaccess mit Rewrite-Regeln nähme ihrem Ordner die Zugangssperre der Kopie; nach Live geht sie mit)"
			}
			fmt.Fprintf(o.Out, "  ! nicht in die Kopie übernommen: %s%s\n", file, why)
		}
		fmt.Fprintf(o.Out, "\n✓ Push %s ist auf Staging – %d Requests\n  Zurücknehmen: wpsync rollback %s %s\n  Nach dem Test nach Live: wpsync push %s code %s\n",
			begin.PushID, o.Client.Stats.Requests, o.Site.Name, begin.PushID, o.Site.Name, strings.Join(names, " "))
		return nil
	}
	Apply(base, stamps)
	if err := baseline.Save(siteDir, base); err != nil {
		return fmt.Errorf("save baseline: %w", err)
	}
	journal.Applied = true
	if err := SaveJournal(siteDir, journal); err != nil {
		return err
	}
	if err := o.Commit(siteDir, fmt.Sprintf("push %s to %s", begin.PushID, o.Site.URL)); err != nil {
		return err
	}
	fmt.Fprintf(o.Out, "\n✓ Push %s ist live – %d Requests\n  Zurücknehmen: wpsync rollback %s %s\n",
		begin.PushID, o.Client.Stats.Requests, o.Site.Name, begin.PushID)
	return nil
}

// namedLinks refuses a named unit that is a symlink; without names it lists the skipped ones (U19).
func namedLinks(docroot string, links, only []string, out io.Writer) error {
	for _, link := range links {
		if len(only) == 0 {
			fmt.Fprintf(out, "  übersprungen: %s – symbolischer Link, wird nie gepusht\n", link)
			continue
		}
		for _, name := range only {
			if unitName(name) == link {
				return fmt.Errorf("%s %w, Push abgebrochen", showDir(docroot, "wp-content/"+link), ErrSymlink)
			}
		}
	}
	return nil
}

// unitName normalises a unit named on the command line ("wp-content/plugins/x/" → "plugins/x").
func unitName(name string) string {
	return strings.Trim(strings.TrimPrefix(filepath.ToSlash(name), "wp-content/"), "/")
}

// selectUnits narrows the changed units to the ones named on the command line.
func selectUnits(changed []Unit, only []string, out io.Writer) ([]Unit, error) {
	if len(only) == 0 {
		return changed, nil
	}
	var picked []Unit
	for _, name := range only {
		name = unitName(name)
		if !ValidUnit(name) {
			return nil, fmt.Errorf("%q ist keine pushbare Einheit – erlaubt: plugins/<slug>, themes/<slug>, mu-plugins", name)
		}
		found := false
		for _, u := range changed {
			if u.Path == name {
				picked = append(picked, u)
				found = true
			}
		}
		if !found {
			fmt.Fprintf(out, "  %s: lokal unverändert oder nicht vorhanden\n", name)
		}
	}
	return picked, nil
}

// printPlan shows what would happen and reports conflicts, missing permissions and version changes.
func printPlan(out io.Writer, units []Unit, plan *agentapi.PushBegin) (conflict, readonly, versionChange bool) {
	if plan.WindowOpen {
		fmt.Fprintln(out, "Push-Fenster: offen")
	} else {
		fmt.Fprintln(out, "Push-Fenster: geschlossen – im WP-Admin unter Werkzeuge → wpsync öffnen")
	}
	for i, u := range units {
		p := plan.Units[i]
		line := fmt.Sprintf("%s – %d von %d Dateien zu übertragen", u.Path, len(p.Need), len(u.Files))
		switch {
		case !p.Exists:
			line += " (neu, bleibt auf der Site inaktiv)"
		case u.Version != p.Version:
			line += fmt.Sprintf(" (Version %s → %s)", showVersion(p.Version), showVersion(u.Version))
			versionChange = true
		}
		fmt.Fprintln(out, line)
		if p.Exists { // a new unit is new as a whole – no need to list every file
			for _, rel := range u.Changed {
				fmt.Fprintf(out, "    %s\n", showPath(rel))
			}
		}
		if len(p.Conflicts) > 0 {
			conflict = true
			sort.Strings(p.Conflicts)
			fmt.Fprintln(out, "  ! auf dem Server geändert seit dem letzten Pull (erst wpsync pull, oder bewusst --force):")
			for _, rel := range p.Conflicts {
				fmt.Fprintf(out, "      %s\n", agentapi.Printable(rel))
			}
		}
		if !p.Writable {
			readonly = true
			fmt.Fprintln(out, "  ! der Webserver darf dieses Verzeichnis nicht ersetzen (Rechte auf dem Server prüfen)")
		}
	}
	return conflict, readonly, versionChange
}

// showVersion quotes a plugin or theme version for display; it comes from the server or from
// code the site delivered.
func showVersion(v string) string {
	if v == "" {
		return "–"
	}
	return agentapi.Printable(v)
}

// leftOut lists the files of the manifest the agent did not place, as unit/file. A push to staging
// leaves out a .htaccess with rewrite directives, as the copy itself does. A unit the agent sent no
// stamps for says nothing.
func leftOut(units []Unit, stamps map[string]map[string]agentapi.PushStamp) []string {
	var out []string
	for _, u := range units {
		placed, ok := stamps[u.Path]
		if !ok {
			continue
		}
		for rel := range u.Files {
			if _, ok := placed[rel]; !ok {
				out = append(out, u.Path+"/"+rel)
			}
		}
	}
	sort.Strings(out)
	return out
}

// upload sends the needed files of one unit, at most ChunkBytes of raw data per request;
// larger files travel in pieces (U13).
func upload(o Options, pushID string, index int, docroot string, u *Unit, need []string) error {
	var batch []agentapi.PushChunk
	size := 0
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := o.Client.PushUpload(pushID, index, batch)
		batch, size = nil, 0
		return err
	}
	root, err := u.open(docroot)
	if err != nil {
		return err
	}
	defer root.Close()
	for _, rel := range need {
		local, ok := u.Files[rel]
		if !ok {
			return fmt.Errorf("der Agent verlangt %s/%s, das nicht im Manifest steht", u.Path, agentapi.Printable(rel))
		}
		data, err := readExactly(root, u.Path, rel, local)
		if err != nil {
			return err
		}
		for off := 0; ; {
			n := min(len(data)-off, o.ChunkBytes-size)
			batch = append(batch, agentapi.PushChunk{Path: rel, Offset: int64(off), Data: data[off : off+n]})
			size += n
			off += n
			if size >= o.ChunkBytes {
				if err := flush(); err != nil {
					return err
				}
			}
			if off >= len(data) {
				break
			}
		}
	}
	return flush()
}

// readExactly reads a file of the unit as the scan and Hash saw it; anything else stops the push
// before the site changes.
func readExactly(root *os.Root, unit, rel string, want LocalFile) ([]byte, error) {
	file, err := openFile(root, unit, rel, want)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	data, err := io.ReadAll(io.LimitReader(file, want.Size+1))
	if err != nil {
		return nil, err
	}
	sum := sha256.Sum256(data)
	if int64(len(data)) != want.Size || hex.EncodeToString(sum[:]) != want.SHA256 {
		return nil, fmt.Errorf("%s/%s %w", unit, rel, ErrChanged)
	}
	return data, nil
}

// rolledBack notes in the result whether the way back worked.
func rolledBack(report *Result, err error) error {
	var rolled *RolledBackError
	if errors.As(err, &rolled) {
		report.Status = "rolled_back"
	}
	return err
}

// stagingPages maps the health pages of the site configuration into the staging copy; pages of
// other hosts have no counterpart there.
func stagingPages(siteURL, base string, pages []string) []string {
	prefix := strings.TrimRight(siteURL, "/") + "/"
	var out []string
	for _, p := range pages {
		if rest, ok := strings.CutPrefix(p, prefix); ok {
			out = append(out, base+"/"+rest)
		}
	}
	return out
}

// rollbackNow takes a swapped push back through rescue.php and checks the site again.
func rollbackNow(o Options, acc *copyAccess, j *Journal, urls []string, before []Probe, reasons []string) error {
	for _, r := range reasons {
		fmt.Fprintf(o.Out, "  ! %s\n", r)
	}
	fmt.Fprintln(o.Out, "  rolle zurück …")
	if err := RescueRollback(o.HTTP, j.RescueURL, j.PushID, RescueKey(o.Secret, j.PushID, j.Salt)); err != nil {
		where := "live und die Site"
		if j.target() == TargetStaging {
			where = "auf Staging und die Kopie"
		}
		return fmt.Errorf("ROLLBACK FEHLGESCHLAGEN – Push %s ist noch %s beschädigt (%s). "+
			"Sofort: wpsync rollback %s %s, sonst im WP-Admin unter Werkzeuge → wpsync „Zurückrollen“ (%w)",
			j.PushID, where, strings.Join(reasons, "; "), o.Site.Name, j.PushID, err)
	}
	return &RolledBackError{PushID: j.PushID, Reasons: reasons, StillWorse: Worse(before, check(o.HTTP, urls, o.pause, acc))}
}

// showPath returns a local file path for the plan: as is when it is safe to show (umlauts stay
// readable), otherwise quoted by agentapi.Printable – like ShowID for push IDs.
func showPath(rel string) string {
	if agentapi.CleanText(rel) == rel {
		return rel
	}
	return agentapi.Printable(rel)
}
