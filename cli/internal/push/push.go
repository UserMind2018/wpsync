package push

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"slices"
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
	// SiteDir holds baseline, journals and staging stamps; default SitesRoot/<name> with the Mac
	// lock and the snapshot repo in localgit.GitDir. With SiteDir set (container mode) lock and
	// snapshot repo lie in <SiteDir>/.wpsync like the container pull (Spec Container-Push C1, C10).
	SiteDir string
	// Docroot holds the WordPress files; default SiteDir/public. Must lie directly below SiteDir.
	Docroot string

	Units              []string // empty: every unit with local changes
	Uploads            []string // --uploads: new files relative to wp-content/uploads/ (Spec Content-Push §8)
	Content            string   // --content: file of a content package (Spec Content-Push §7)
	NoCode             bool     // --no-code: only uploads and content; no unit is scanned or pushed
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
	// Ctx stops a push before the swap (SIGTERM in the server mode); nil = never. From /push/commit
	// on the push runs to its end – confirmed or rolled back (Spec Container-Push C12) –, but only
	// for AfterSignal after the signal. Rollback ignores it.
	Ctx context.Context
	// AfterSignal bounds what follows a SIGTERM that arrives after the swap began: commit, health
	// check and confirm or rollback (default 10 min; the studio waits 15 min before SIGKILL).
	// RollbackReserve of it is kept for a rollback (default 3 min): a health check that has not
	// passed by then counts as failed.
	AfterSignal, RollbackReserve time.Duration

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
	// RescueURL is the rescue.php of the push, set together with PushID (push only). The rollback
	// key never leaves the CLI: the way back is wpsync rollback <id> (Spec Container-Push C2).
	RescueURL string `json:"rescue_url,omitempty"`
	// Warnings name what failed without failing the push or rollback; omitted when empty.
	Warnings []string `json:"warnings,omitempty"`
	// Health names every page that got worse after the swap, when the push was taken back for it
	// (Spec Content-Push §7.4, S5); omitted otherwise.
	Health []HealthFinding `json:"health,omitempty"`
	// PostActions: what the agent did after applying or taking back content, step by step (Spec
	// Content-Push §7.7); a failed step is no failed push. Omitted without content.
	PostActions []agentapi.PostAction `json:"post_actions,omitempty"`
	// Content: what the agent applied of the package – while it stands on the site. Omitted
	// without content, in a dry run and once the push is rolled back.
	Content *ContentReport `json:"content,omitempty"`
}

// ContentReport is the content part of a push that stands: the rows of the package and the
// seconds the agent took to apply them in its transaction (Spec Content-Push §7.5). The steps
// after it are Result.PostActions.
type ContentReport struct {
	Rows    int     `json:"rows"`
	Seconds float64 `json:"seconds"`
}

// WarningContentNotRolledBack: code and uploads of the push are taken back, its content is not –
// rescue.php knows no database. wpsync rollback <id> takes it back once the agent answers again
// (Spec Content-Push §7.6).
const WarningContentNotRolledBack = "content_not_rolled_back"

// WarningContentKept: pushes --confirm closed a push whose code and uploads rescue.php had taken
// back; its content stays on the site and cannot be taken back any more (Spec Content-Push §7.6).
const WarningContentKept = "content_kept"

// WarningContentState: the push is live and confirmed, only manifest and baseline of this site
// folder could not be brought to the new state – the next pull with --content rebuilds them.
const WarningContentState = "content_state_failed"

// WarningSnapshotFailed: the push (or rollback) is done, baseline and journal are written, only the
// commit in the internal git failed – as for the pull (Spec Container-Push C3).
const WarningSnapshotFailed = "snapshot_failed"

// WarningSnapshotIncomplete: the snapshot is saved but lacks files of the site that were not readable
// or changed while it ran – as for the pull (Spec Container-Push C3).
const WarningSnapshotIncomplete = "snapshot_incomplete"

// snapshotWarning is the warning for an error of Options.Commit.
func snapshotWarning(err error) string {
	if errors.Is(err, localgit.ErrIncomplete) {
		return WarningSnapshotIncomplete
	}
	return WarningSnapshotFailed
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
	// ErrInterrupted: SIGTERM before the swap; nothing on the site was swapped (exit code 30).
	ErrInterrupted = errors.New("abgebrochen (SIGTERM) – auf der Site wurde nichts getauscht")
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
	Warnings   []string // what the rollback reports beyond its status, e.g. upload_changed_since_push
	// PostActions: the agent's steps after taking the content back; nil through rescue.php.
	PostActions []agentapi.PostAction
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
	if o.Ctx != nil {
		o.Client.Ctx = o.Ctx // a running request ends with the signal, before the swap
	}
	if o.Sleep == nil {
		o.Sleep = time.Sleep
	}
	if o.AfterSignal == 0 {
		o.AfterSignal = 10 * time.Minute
	}
	if o.RollbackReserve == 0 {
		o.RollbackReserve = 3 * time.Minute
	}
	if o.Commit == nil {
		out := o.Out
		if out == nil {
			out = io.Discard
		}
		// The snapshot repo never lies inside the site folder's reach: on the Mac next to the site
		// folder (localgit.GitDir), in the container mode in <SiteDir>/.wpsync/history.git, out of
		// the docroot mount. It runs under the site lock (Run, Rollback), so git locks of a killed
		// run are cleared first.
		container, docroot := o.SiteDir != "", o.Docroot
		o.Commit = func(siteDir, message string) error {
			if !container {
				if err := localgit.ClearStaleLocks(localgit.GitDir(filepath.Dir(siteDir), filepath.Base(siteDir))); err != nil {
					return err
				}
				return localgit.Commit(filepath.Dir(siteDir), filepath.Base(siteDir), message, out)
			}
			gitDir := localgit.TreeGitDir(siteDir)
			if err := localgit.ClearStaleLocks(gitDir); err != nil {
				return err
			}
			return localgit.CommitTree(gitDir, siteDir, filepath.Base(docroot), message, out)
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

// dirs resolves site folder and docroot like pull does: the docroot lies directly below the site
// folder, which holds .wpsync next to it.
func (o Options) dirs() (siteDir, docroot string, err error) {
	siteDir = o.SiteDir
	if siteDir == "" {
		siteDir = filepath.Join(o.SitesRoot, o.Site.Name)
	}
	docroot = o.Docroot
	if docroot == "" {
		docroot = filepath.Join(siteDir, "public")
	}
	if filepath.Dir(docroot) != filepath.Clean(siteDir) {
		return "", "", fmt.Errorf("docroot %s must lie directly below %s", docroot, siteDir)
	}
	return siteDir, docroot, nil
}

// interrupted reports a cancelled Ctx; it wraps context.Canceled for the exit code.
func (o Options) interrupted() error {
	if o.Ctx != nil && o.Ctx.Err() != nil {
		return fmt.Errorf("%w (%w)", ErrInterrupted, o.Ctx.Err())
	}
	return nil
}

// ctx is Ctx, or a context that never ends.
func (o Options) ctx() context.Context {
	if o.Ctx == nil {
		return context.Background()
	}
	return o.Ctx
}

// afterSignal returns the contexts for the part from /push/commit on. Without a SIGTERM neither
// ends. After one, health ends AfterSignal-RollbackReserve later and done AfterSignal later –
// running requests included. stop releases the watcher.
func (o Options) afterSignal() (done, health context.Context, stop func()) {
	done, cancelDone := context.WithCancel(context.Background())
	health, cancelHealth := context.WithCancel(done)
	quit := make(chan struct{})
	go func() {
		select {
		case <-o.ctx().Done():
		case <-quit:
			return
		}
		h := time.AfterFunc(o.AfterSignal-o.RollbackReserve, cancelHealth)
		d := time.AfterFunc(o.AfterSignal, cancelDone)
		<-quit
		h.Stop()
		d.Stop()
	}()
	return done, health, func() {
		close(quit)
		cancelHealth()
		cancelDone()
	}
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
// skipped are local units new to the baseline that were not named (U14), missing the units of the
// baseline that are gone locally and stay on the site (Spec Container-Push C7).
func planEvent(units []Unit, plan *agentapi.PushBegin, target string, skipped, missing []string, up *agentapi.PushUnitPlan, ct *agentapi.ContentPlan) map[string]any {
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
	ev := map[string]any{"target": target, "window_open": plan.WindowOpen, "units": list,
		"skipped_new": append([]string{}, skipped...), "missing_locally": append([]string{}, missing...)}
	if up != nil { // only with --uploads: plans of pushes without stay as they were
		ev["uploads"] = map[string]any{"need": nonNil(up.Need), "same": nonNil(up.Same), "conflicts": nonNil(up.Conflicts)}
	}
	if ct != nil { // only with --content (Spec Content-Push §10)
		rows := ct.Rows
		if rows == nil {
			rows = map[string]int{}
		}
		ev["content"] = map[string]any{"rows": rows, "conflicts": append([]agentapi.ContentKey{}, ct.Conflicts...), "limits": ct.Limits}
	}
	return ev
}

// lock takes the site lock shared with pull (Nach-Review M-1): one pull, push or rollback per site –
// on the Mac next to the snapshot repo, in the container mode <SiteDir>/.wpsync/lock like the
// container pull (Spec Container-Push C10).
func lock(o Options) (func(), error) {
	l, err := sitelock.Acquire(sitelock.Path(o.SitesRoot, o.Site.Name, o.SiteDir))
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

// AtLeast compares dotted versions numerically; see agentapi.AtLeast.
func AtLeast(version, minimum string) bool { return agentapi.AtLeast(version, minimum) }

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
	siteDir, docroot, err := o.dirs()
	if err != nil {
		return err
	}

	base, err := baseline.Load(siteDir)
	if err != nil {
		return fmt.Errorf("load baseline: %w", err)
	}
	if base.Empty() {
		return ErrNoBaseline
	}
	// With --no-code no unit is looked at: the set is uploads and content alone.
	var units []Unit
	var deleted []string
	if !o.NoCode {
		all, gone, links, err := scan(docroot, base)
		if err != nil {
			return err
		}
		if err := namedLinks(docroot, links, o.Units, o.Out); err != nil {
			return err
		}
		if units, err = selectUnits(all, o.Units, o.Out); err != nil {
			return err
		}
		deleted = gone
	}
	for _, unit := range deleted {
		fmt.Fprintf(o.Out, "  Hinweis: %s fehlt lokal – ein Push löscht nie, auf der Site bleibt es bestehen.\n", unit)
	}
	var skipped []string
	if len(o.Units) == 0 && !o.NoCode {
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
	// The unit uploads comes from the list of --uploads, never from the scan (Spec Content-Push §8).
	var up *Unit
	if len(o.Uploads) > 0 {
		u, err := uploadsUnit(docroot, o.Uploads)
		if err != nil {
			return err
		}
		up = &u
	}
	// The content comes as a package the caller built (Spec Content-Push §7.1); it is checked here
	// for its form and against the content state of this site folder, by the agent for the rest.
	var pkg *Package
	if o.Content != "" {
		if pkg, err = LoadPackage(o.Content); err != nil {
			return err
		}
		if err := pkg.CheckSite(siteDir); err != nil {
			return err
		}
	}
	if len(units) == 0 && up == nil && pkg == nil {
		if len(skipped) > 0 {
			return &SkippedNewError{Units: skipped}
		}
		return ErrNothing
	}
	// Which units changed is decided by the baseline alone (scan above). Only the base of the
	// conflict check differs for staging: a unit this machine pushed there before is compared with
	// the stamps that push left in the copy, every other unit with the baseline – the copy was made
	// from live. A push to live never reads the staging stamps.
	// Every file of a unit to push is read before the begin: a folder or file this process may not
	// read stops the push here, before the site is asked (P-O3, AC-130).
	for _, u := range units {
		if len(u.Unreadable) > 0 {
			return &UnreadableError{Path: unreadablePath(u.Path, u.Unreadable[0])}
		}
	}
	var copied *copyID
	var known *stagingBase
	if target == TargetStaging {
		copied, known = o.stagingStamps(siteDir)
	}
	req := agentapi.PushBeginRequest{Target: target, Force: o.Force, Dry: true, RescueStub: !o.DryRun}
	names := make([]string, len(units))
	for i := range units {
		if err := units[i].Hash(docroot); err != nil {
			return err
		}
		unit := units[i].Request()
		if base := known.unit(units[i].Path); base != nil {
			unit.Base = base
		}
		req.Units = append(req.Units, unit)
		names[i] = units[i].Path
	}
	report.Units = names
	want := len(units) // units the agent answers for: the code units, then uploads
	if up != nil {
		if err := up.Hash(docroot); err != nil {
			return err
		}
		req.Units = append(req.Units, up.Request())
		report.Units = append(append([]string{}, names...), UploadsUnit)
		want++
	}
	if pkg != nil {
		// The package lies on the site before the dry run, so that the agent checks all of it –
		// staging needs no push window and writes nothing a visitor could reach.
		if err := stagePackage(o, pkg); err != nil {
			return err
		}
		req.Content = &agentapi.PushContentRef{SHA256: pkg.SHA256}
		report.Units = append(append([]string{}, report.Units...), ContentUnit)
	}

	plan, err := o.Client.PushBegin(req)
	if err != nil {
		var apiErr *agentapi.APIError
		if up != nil && errors.As(err, &apiErr) && apiErr.Code == "wpsync_push_unit" {
			return fmt.Errorf("%w: %w", ErrAgentNoUploads, err) // an agent before 0.6.0 refuses the unit
		}
		if pkg != nil && len(req.Units) == 0 && errors.As(err, &apiErr) && apiErr.Code == "wpsync_push_units" {
			return fmt.Errorf("%w: %w", ErrAgentNoContent, err) // an agent without the channel wants units
		}
		return contentError(uploadError(agentError(target, err)))
	}
	if !AtLeast(plan.AgentVersion, MinAgent) {
		return ErrAgentTooOld
	}
	if target == TargetStaging && !AtLeast(plan.AgentVersion, staging.MinAgent) {
		return ErrAgentNoStaging
	}
	if up != nil && !AtLeast(plan.AgentVersion, MinAgentUploads) {
		return ErrAgentNoUploads
	}
	// An agent that knows the content channel answers for the package; the version alone does not
	// say it (0.7.0 was built with and, before its release, without the channel).
	if pkg != nil && (plan.Content == nil || !AtLeast(plan.AgentVersion, agentapi.MinAgentContentPush)) {
		return ErrAgentNoContent
	}
	if err := answeredFor(target, plan.Target); err != nil {
		return err
	}
	if plan.Pending != nil {
		return &PendingError{PushID: plan.Pending.PushID, Device: plan.Pending.Device}
	}
	if len(plan.Units) != want {
		return errors.New("der Agent hat nicht jede Einheit beantwortet")
	}
	var upPlan *agentapi.PushUnitPlan
	if up != nil {
		if upPlan = &plan.Units[len(units)]; upPlan.Path != UploadsUnit {
			return errors.New("der Agent hat die Uploads nicht beantwortet")
		}
	}
	if target == TargetStaging {
		fmt.Fprintln(o.Out, "Ziel: Staging-Kopie (Live bleibt unverändert, die Baseline auch)")
	}
	conflict, readonly, versionChange := printPlan(o.Out, units, plan)
	for i, u := range units {
		// Such a unit was compared with the last push to staging, not with the last pull.
		if len(plan.Units[i].Conflicts) > 0 && known.unit(u.Path) != nil {
			fmt.Fprintf(o.Out, "  ! %s: in der Kopie geändert seit dem letzten Push nach Staging – ein Pull löst das nicht.\n"+
				"    Bewusst --force, oder die Kopie neu von Live holen: wpsync staging refresh %s --code\n", u.Path, o.Site.Name)
		}
	}
	if upPlan != nil {
		printUploads(o.Out, up, upPlan)
		readonly = readonly || !upPlan.Writable
	}
	if pkg != nil {
		printContent(o.Out, pkg, plan.Content)
	}
	o.event("plan", planEvent(units, plan, target, skipped, deleted, upPlan, plan.Content))
	if readonly {
		return ErrNotWritable
	}
	// A file below uploads is never replaced, --force or not (Spec Content-Push §8.2, W3).
	if upPlan != nil && len(upPlan.Conflicts) > 0 {
		return &UploadExistsError{Paths: upPlan.Conflicts}
	}
	if conflict && !o.Force {
		return ErrConflict
	}
	// What the agent refuses of the package stops the whole set – there is no --force for content.
	if pkg != nil && !plan.Content.OK {
		if plan.Content.Error == nil {
			return &ContentError{Reason: "content_failed", Message: "der Agent lehnt die Inhalte ohne Grund ab"}
		}
		return contentFailure(plan.Content.Error)
	}
	if len(units) == 0 && pkg == nil && len(upPlan.Need) == 0 {
		return ErrUploadsThere // no code, and every upload is there already
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
		if !o.Confirm(fmt.Sprintf("%s %s pushen?", pushWhat(len(units), upPlan, pkg), where)) {
			return ErrAborted
		}
	}

	if err := rescueReady(o, plan.Rescue); err != nil {
		return err
	}
	// With content the published pages it changes are checked too, before and after (Spec §7.4).
	pages := plan.HealthURLs
	if pkg != nil {
		pages = append(append([]string{}, pages...), plan.Content.HealthURLs...)
	}
	acc, urls, err := o.healthPages(pages)
	if err != nil {
		return err
	}
	before := check(o.ctx(), o.HTTP, urls, o.pause, acc)
	if err := o.interrupted(); err != nil {
		return err
	}

	req.Dry = false
	begin, err := o.Client.PushBegin(req)
	if err != nil {
		return contentError(uploadError(agentError(target, err)))
	}
	// Before the first byte travels: the push the agent created must be the one asked for.
	if err := answeredFor(target, begin.Target); err != nil {
		return err
	}
	if len(begin.Units) != want {
		return errors.New("der Agent hat nicht jede Einheit beantwortet")
	}
	expires := func(err error) error {
		return fmt.Errorf("Push %s nicht getauscht – er verfällt auf dem Server (bis dahin ist die Site für Pushes belegt, Exit 44): %w", begin.PushID, err)
	}
	// The real begin names the stub of the dry run again – unless it was tidied away meanwhile (a
	// long confirmation prompt). Then the new way back is checked before the first byte (Spec 12, R4).
	rescueURL := plan.Rescue.URL
	if begin.Rescue.URL != "" && begin.Rescue.URL != rescueURL {
		if err := rescueReady(o, begin.Rescue); err != nil {
			return expires(err)
		}
		rescueURL = begin.Rescue.URL
	}
	journal := NewJournal(begin.PushID, rescueURL, begin.Rescue.Salt, base, names)
	journal.Target = target
	if up != nil {
		journal.NoteUploads(base, begin.Units[len(units)].Need)
	}
	if pkg != nil {
		journal.Content = &JournalContent{SHA256: pkg.SHA256, Rows: len(pkg.Rows)}
	}
	if err := SaveJournal(siteDir, journal); err != nil {
		return err
	}
	report.PushID, report.RescueURL = begin.PushID, rescueURL
	for i := range units {
		if err := o.interrupted(); err != nil {
			return expires(err)
		}
		if err := upload(o, begin.PushID, i, docroot, &units[i], begin.Units[i].Need); err != nil {
			return fmt.Errorf("Upload abgebrochen, auf der Site wurde nichts geändert: %w", err)
		}
		o.event("upload", map[string]any{"unit": units[i].Path, "files": len(begin.Units[i].Need)})
	}
	if up != nil {
		if err := o.interrupted(); err != nil {
			return expires(err)
		}
		need := begin.Units[len(units)].Need
		if err := upload(o, begin.PushID, len(units), docroot, up, need); err != nil {
			return fmt.Errorf("Upload abgebrochen, auf der Site wurde nichts geändert: %w", err)
		}
		o.event("upload", map[string]any{"unit": UploadsUnit, "files": len(need)})
	}
	if err := o.interrupted(); err != nil {
		return expires(err)
	}
	// From the swap on the push runs to its end: confirmed or rolled back, never left swapped and
	// unchecked because a caller gave up (C12). A SIGTERM from here on only bounds the rest, so
	// that it ends before the caller's SIGKILL (Grill 2026-10-07).
	done, health, stop := o.afterSignal()
	o.Client.Ctx = done
	defer func() {
		o.Client.Ctx = nil // the client outlives this push (rollback, pushes): never leave it cancelled
		stop()
	}()
	committed, err := o.Client.PushCommitFull(begin.PushID)
	if err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Code != "" {
			// The agent refused and left the site as it was – also when the content failed last: it
			// swapped code and uploads back (Spec Content-Push §7.3).
			return contentError(uploadError(agentError(target, err)))
		}
		report.Status = "committed" // unknown; the worse case
		if done.Err() != nil {
			// not context.Canceled: that would be exit 30, "nothing swapped"
			return fmt.Errorf("Zeit nach SIGTERM abgelaufen, der Tausch ist unklar – prüfen mit: wpsync pushes %s: %w (%v)",
				o.Site.Name, &PendingError{PushID: begin.PushID, Device: "diesem Gerät"}, err)
		}
		return fmt.Errorf("der Tausch wurde nicht bestätigt, der Stand ist unklar – prüfen mit: wpsync pushes %s (%w)", o.Site.Name, err)
	}
	stamps := committed.Stamps
	report.Status = "committed"
	if committed.Content != nil {
		report.PostActions = committed.Content.PostActions
		report.Content = &ContentReport{Rows: committed.Content.Rows, Seconds: committed.Content.Seconds}
		fmt.Fprintf(o.Out, "  Inhalte: %d Zeilen in %s s angewandt\n", committed.Content.Rows, strconv.FormatFloat(committed.Content.Seconds, 'f', -1, 64))
		printActions(o.Out, committed.Content.PostActions)
	}
	o.event("commit", map[string]any{"push_id": begin.PushID})
	fmt.Fprintln(o.Out, "  getauscht – prüfe die Site …")

	after := check(health, o.HTTP, urls, o.pause, acc)
	for attempt := 0; attempt < 2 && len(Worse(before, after)) > 0 && health.Err() == nil; attempt++ {
		o.Sleep(2 * time.Second) // caches and opcache may need a moment
		after = check(health, o.HTTP, urls, o.pause, acc)
	}
	worse := Worse(before, after)
	if len(worse) > 0 && health.Err() != nil {
		// unproven is not healthy: what is left of the time goes to the rollback
		worse = []string{"der Health-Check kam nach SIGTERM nicht rechtzeitig zum Ende – ungeprüft wird nicht bestätigt"}
	}
	o.event("health", map[string]any{"pages": len(urls), "worse": append([]string{}, worse...)})
	if pkg != nil && committed.Content == nil && len(worse) == 0 {
		worse = []string{"der Agent hat die Inhalte des Pushs nicht angewandt"} // never confirm half a set
	}
	if len(worse) > 0 {
		report.Health = WorsePages(before, after)
		return rolledBack(report, rollbackNow(done, o, acc, journal, urls, before, worse))
	}
	if err := o.Client.PushConfirm(begin.PushID); err != nil {
		rbErr := rollbackNow(done, o, acc, journal, urls, before, []string{"der Agent antwortet nach dem Tausch nicht mehr (" + err.Error() + ")"})
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
		// What the copy holds now is remembered apart, for the next push to staging.
		if err := o.rememberStaging(siteDir, copied, known, begin.PushID, names, stamps); err != nil {
			return fmt.Errorf("Push %s ist auf Staging, die Stempel der Kopie liessen sich aber nicht merken – der nächste Push nach Staging meldet Konflikte: %w", begin.PushID, err)
		}
		for _, file := range leftOut(units, stamps) {
			why := ""
			if strings.EqualFold(path.Base(file), ".htaccess") {
				why = " (eine .htaccess mit Rewrite-Regeln nähme ihrem Ordner die Zugangssperre der Kopie; nach Live geht sie mit)"
			}
			fmt.Fprintf(o.Out, "  ! nicht in die Kopie übernommen: %s%s\n", file, why)
		}
		next := strings.Join(names, " ")
		if up != nil {
			next = strings.TrimSpace(next + " --uploads <liste>")
		}
		if pkg != nil {
			next = strings.TrimSpace(next + " --content <paket>")
		}
		if o.NoCode {
			next = strings.TrimSpace(next + " --no-code")
		}
		fmt.Fprintf(o.Out, "\n✓ Push %s ist auf Staging – %d Requests\n  Zurücknehmen: wpsync rollback %s %s\n  Nach dem Test nach Live: wpsync push %s code %s\n",
			begin.PushID, o.Client.Stats.Requests, o.Site.Name, begin.PushID, o.Site.Name, next)
		return nil
	}
	Apply(base, stamps)
	ApplyUploads(base, stamps[UploadsUnit])
	if err := baseline.Save(siteDir, base); err != nil {
		return fmt.Errorf("save baseline: %w", err)
	}
	journal.Applied = true
	if pkg != nil {
		// Manifest and baseline follow the site: the pushed rows are no local change any more, and
		// the next package is built against the fingerprints the site has now. A failure here is a
		// warning – the push is live and confirmed.
		if err := applyContent(siteDir, journal, pkg, committed.Content.After); err != nil {
			fmt.Fprintf(o.Out, "  ! Manifest und Baseline liessen sich nicht nachziehen – vor dem nächsten Inhalts-Push: wpsync pull %s --content (%v)\n", o.Site.Name, err)
			report.Warnings = append(report.Warnings, WarningContentState)
		}
	}
	if err := SaveJournal(siteDir, journal); err != nil {
		return err
	}
	// The push is live and confirmed: a failed snapshot is a warning, never a failed push – a caller
	// would retry and create a second push (Spec Container-Push C3).
	if err := o.Commit(siteDir, fmt.Sprintf("push %s to %s", begin.PushID, o.Site.URL)); err != nil {
		if snapshotWarning(err) == WarningSnapshotFailed {
			fmt.Fprintf(o.Out, "  ! Schnappschuss im lokalen Git fehlgeschlagen – der Push ist live, der Stand fehlt in der Historie: %v\n", err)
		}
		report.Warnings = append(report.Warnings, snapshotWarning(err))
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
		report.Warnings = append(report.Warnings, rolled.Warnings...)
		// Through rescue.php the content stays on the site – then it is still what was applied.
		if !slices.Contains(report.Warnings, WarningContentNotRolledBack) {
			report.Content = nil
		}
		if rolled.PostActions != nil {
			report.PostActions = rolled.PostActions
		}
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

// rollbackNow takes a swapped push back and checks the site again: through rescue.php – or, for a
// push with content, through the agent first, because only it takes content back (DB → code →
// uploads, Spec Content-Push §7.6). If the agent does not answer, rescue.php takes code and
// uploads back and the result says content_not_rolled_back. If the agent answers and refuses (a
// wpsync code below 500, e.g. rows changed since the push), nothing is taken back and rescue.php
// is not called: the set stays whole. A push without content goes through rescue.php, always.
// ctx bounds the check after the rollback; a check cut short by it is left out of the error.
func rollbackNow(ctx context.Context, o Options, acc *copyAccess, j *Journal, urls []string, before []Probe, reasons []string) error {
	for _, r := range reasons {
		fmt.Fprintf(o.Out, "  ! %s\n", r)
	}
	fmt.Fprintln(o.Out, "  rolle zurück …")
	var notes agentapi.RollbackNotes
	var err error
	viaAgent := false
	if j.Content != nil {
		var apiErr *agentapi.APIError
		notes, err = o.Client.PushRollbackNotes(j.PushID)
		switch {
		case err == nil:
			viaAgent = true
		case errors.As(err, &apiErr) && strings.HasPrefix(apiErr.Code, "wpsync_") && apiErr.Status < 500:
			// The agent answered and refused – rows changed since the push, or any other reason of
			// its own. Never around it through rescue.php: that would take back half the set.
			return fmt.Errorf("ROLLBACK NICHT MÖGLICH – Push %s bleibt ganz bestehen (%s): %w", j.PushID, strings.Join(reasons, "; "), contentError(agentError(j.target(), err)))
		default:
			// No answer, a 5xx, or a page that is not the agent's (a firewall, a fatal error).
			fmt.Fprintln(o.Out, "  der Agent antwortet nicht – nehme den Weg über rescue.php (nur Code und Uploads)")
		}
	}
	if !viaAgent {
		notes, err = RescueRollbackNotes(o.HTTP, j.RescueURL, j.PushID, RescueKey(o.Secret, j.PushID, j.Salt))
		if err == nil && j.Content != nil && !slices.Contains(notes.Warnings, WarningContentNotRolledBack) {
			notes.Warnings = append(notes.Warnings, WarningContentNotRolledBack) // whatever rescue.php says: it knows no database
		}
	}
	if err != nil {
		where := "live und die Site"
		if j.target() == TargetStaging {
			where = "auf Staging und die Kopie"
		}
		return fmt.Errorf("ROLLBACK FEHLGESCHLAGEN – Push %s ist noch %s beschädigt (%s). "+
			"Sofort: wpsync rollback %s %s, sonst im WP-Admin unter Werkzeuge → wpsync „Zurückrollen“ (%w)",
			j.PushID, where, strings.Join(reasons, "; "), o.Site.Name, j.PushID, err)
	}
	still := Worse(before, check(ctx, o.HTTP, urls, o.pause, acc))
	if ctx.Err() != nil {
		fmt.Fprintln(o.Out, "  nach SIGTERM nicht mehr nachgeprüft, ob die Site wieder heil ist")
		still = nil
	}
	printKept(o.Out, notes.Kept)
	printActions(o.Out, notes.PostActions)
	return &RolledBackError{PushID: j.PushID, Reasons: reasons, StillWorse: still, Warnings: notes.Warnings, PostActions: notes.PostActions}
}

// showPath returns a local file path for the plan: as is when it is safe to show (umlauts stay
// readable), otherwise quoted by agentapi.Printable – like ShowID for push IDs.
func showPath(rel string) string {
	if agentapi.CleanText(rel) == rel {
		return rel
	}
	return agentapi.Printable(rel)
}
