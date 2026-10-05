package push

import (
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/sites"
)

// MinAgent is the first agent version with the push endpoints.
const MinAgent = "0.4.0"

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

	Confirm func(string) bool // asks the user; nil without a terminal
	Out     io.Writer

	// For tests; zero values are replaced in defaults().
	HTTP       *http.Client                        // health check and rescue.php
	Client     *agentapi.Client                    // signed agent requests
	Sleep      func(time.Duration)                 // pauses of the health check
	Commit     func(siteDir, message string) error // internal git
	ChunkBytes int                                 // raw bytes per upload request
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
)

// PendingError: an earlier push was swapped in but never confirmed (U7).
type PendingError struct{ PushID, Device string }

func (e *PendingError) Error() string {
	return fmt.Sprintf("Push %s (von %s) ist getauscht, aber nicht bestätigt", e.PushID, e.Device)
}

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
		o.Commit = func(siteDir, message string) error {
			return localgit.Commit(filepath.Dir(siteDir), filepath.Base(siteDir), message, out)
		}
	}
	if o.ChunkBytes <= 0 {
		o.ChunkBytes = 3 << 20
	}
	return o
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

// Run pushes the locally changed units of a site (Spec Stufe 2, 6.3).
func Run(o Options) error {
	o = o.defaults()
	siteDir := filepath.Join(o.SitesRoot, o.Site.Name)
	docroot := filepath.Join(siteDir, "public")

	base, err := baseline.Load(siteDir)
	if err != nil {
		return fmt.Errorf("load baseline: %w", err)
	}
	if base.Empty() {
		return ErrNoBaseline
	}
	all, deleted, err := Scan(docroot, base)
	if err != nil {
		return err
	}
	units, err := selectUnits(all, o.Units, o.Out)
	if err != nil {
		return err
	}
	for _, unit := range deleted {
		fmt.Fprintf(o.Out, "  Hinweis: %s fehlt lokal – ein Push löscht nie, auf der Site bleibt es bestehen.\n", unit)
	}
	if len(units) == 0 {
		return ErrNothing
	}
	req := agentapi.PushBeginRequest{Target: "live", Force: o.Force, Dry: true}
	for i := range units {
		if err := units[i].Hash(docroot); err != nil {
			return err
		}
		req.Units = append(req.Units, units[i].Request())
	}

	plan, err := o.Client.PushBegin(req)
	if err != nil {
		return err
	}
	if !AtLeast(plan.AgentVersion, MinAgent) {
		return ErrAgentTooOld
	}
	if plan.Pending != nil {
		return &PendingError{PushID: plan.Pending.PushID, Device: plan.Pending.Device}
	}
	if len(plan.Units) != len(units) {
		return errors.New("der Agent hat nicht jede Einheit beantwortet")
	}
	if len(o.Units) == 0 {
		// Without explicit units, copies that were never pulled are left alone (U14).
		var kept []Unit
		var keptPlans []agentapi.PushUnitPlan
		var keptReqs []agentapi.PushUnit
		for i, u := range units {
			if u.New && plan.Units[i].Exists {
				fmt.Fprintf(o.Out, "  übersprungen: %s liegt auf der Site, wurde von diesem Rechner aber nie gezogen (nicht im Pull-Profil).\n", u.Path)
				continue
			}
			kept, keptPlans, keptReqs = append(kept, u), append(keptPlans, plan.Units[i]), append(keptReqs, req.Units[i])
		}
		units, plan.Units, req.Units = kept, keptPlans, keptReqs
		if len(units) == 0 {
			return ErrNothing
		}
	}
	conflict, readonly, versionChange := printPlan(o.Out, units, plan)
	if readonly {
		return ErrNotWritable
	}
	if conflict && !o.Force {
		return ErrConflict
	}
	if o.DryRun {
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
			return errors.New("ohne Terminal mit --yes bestätigen")
		}
		if !o.Confirm(fmt.Sprintf("%d Einheit(en) nach %s pushen?", len(units), o.Site.URL)) {
			return ErrAborted
		}
	}

	if err := RescueAllowed(o.Site.URL, plan.Rescue.URL); err != nil {
		return err
	}
	if err := RescuePing(o.HTTP, plan.Rescue.URL); err != nil {
		return err
	}
	urls := HealthURLs(plan.HealthURLs, o.Site.HealthURLs)
	before := Check(o.HTTP, urls, o.pause)

	req.Dry = false
	begin, err := o.Client.PushBegin(req)
	if err != nil {
		return err
	}
	if len(begin.Units) != len(units) {
		return errors.New("der Agent hat nicht jede Einheit beantwortet")
	}
	names := make([]string, len(units))
	for i, u := range units {
		names[i] = u.Path
	}
	journal := NewJournal(begin.PushID, plan.Rescue.URL, begin.Rescue.Salt, base, names)
	if err := SaveJournal(siteDir, journal); err != nil {
		return err
	}
	for i := range units {
		if err := upload(o, begin.PushID, i, docroot, &units[i], begin.Units[i].Need); err != nil {
			return fmt.Errorf("Upload abgebrochen, auf der Site wurde nichts geändert: %w", err)
		}
	}
	stamps, err := o.Client.PushCommit(begin.PushID)
	if err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Code != "" {
			return err // the agent refused and left the site as it was
		}
		return fmt.Errorf("der Tausch wurde nicht bestätigt, der Stand ist unklar – prüfen mit: wpsync pushes %s (%w)", o.Site.Name, err)
	}
	fmt.Fprintln(o.Out, "  getauscht – prüfe die Site …")

	after := Check(o.HTTP, urls, o.pause)
	for attempt := 0; attempt < 2 && len(Worse(before, after)) > 0; attempt++ {
		o.Sleep(2 * time.Second) // caches and opcache may need a moment
		after = Check(o.HTTP, urls, o.pause)
	}
	if worse := Worse(before, after); len(worse) > 0 {
		return rollbackNow(o, journal, urls, before, worse)
	}
	if err := o.Client.PushConfirm(begin.PushID); err != nil {
		return rollbackNow(o, journal, urls, before, []string{"der Agent antwortet nach dem Tausch nicht mehr (" + err.Error() + ")"})
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

// selectUnits narrows the changed units to the ones named on the command line.
func selectUnits(changed []Unit, only []string, out io.Writer) ([]Unit, error) {
	if len(only) == 0 {
		return changed, nil
	}
	var picked []Unit
	for _, name := range only {
		name = strings.Trim(strings.TrimPrefix(filepath.ToSlash(name), "wp-content/"), "/")
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
			line += fmt.Sprintf(" (Version %s → %s)", orDash(p.Version), orDash(u.Version))
			versionChange = true
		}
		fmt.Fprintln(out, line)
		if p.Exists { // a new unit is new as a whole – no need to list every file
			for _, rel := range u.Changed {
				fmt.Fprintf(out, "    %s\n", rel)
			}
		}
		if len(p.Conflicts) > 0 {
			conflict = true
			sort.Strings(p.Conflicts)
			fmt.Fprintln(out, "  ! auf dem Server geändert seit dem letzten Pull (erst wpsync pull, oder bewusst --force):")
			for _, rel := range p.Conflicts {
				fmt.Fprintf(out, "      %s\n", rel)
			}
		}
		if !p.Writable {
			readonly = true
			fmt.Fprintln(out, "  ! der Webserver darf dieses Verzeichnis nicht ersetzen (Rechte auf dem Server prüfen)")
		}
	}
	return conflict, readonly, versionChange
}

func orDash(v string) string {
	if v == "" {
		return "–"
	}
	return v
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
	for _, rel := range need {
		local, ok := u.Files[rel]
		if !ok {
			return fmt.Errorf("der Agent verlangt %s/%s, das nicht im Manifest steht", u.Path, rel)
		}
		data, err := os.ReadFile(u.file(docroot, rel))
		if err != nil {
			return err
		}
		if int64(len(data)) != local.Size {
			return fmt.Errorf("%s/%s hat sich während des Pushs geändert", u.Path, rel)
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

// rollbackNow takes a swapped push back through rescue.php and checks the site again.
func rollbackNow(o Options, j *Journal, urls []string, before []Probe, reasons []string) error {
	for _, r := range reasons {
		fmt.Fprintf(o.Out, "  ! %s\n", r)
	}
	fmt.Fprintln(o.Out, "  rolle zurück …")
	if err := RescueRollback(o.HTTP, j.RescueURL, j.PushID, RescueKey(o.Secret, j.PushID, j.Salt)); err != nil {
		return fmt.Errorf("ROLLBACK FEHLGESCHLAGEN – Push %s ist noch live und die Site beschädigt (%s). "+
			"Sofort: wpsync rollback %s %s, sonst im WP-Admin unter Werkzeuge → wpsync „Zurückrollen“ (%w)",
			j.PushID, strings.Join(reasons, "; "), o.Site.Name, j.PushID, err)
	}
	return &RolledBackError{PushID: j.PushID, Reasons: reasons, StillWorse: Worse(before, Check(o.HTTP, urls, o.pause))}
}
