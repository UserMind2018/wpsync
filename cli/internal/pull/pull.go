package pull

import (
	"context"
	"errors"
	"fmt"
	"io"
	"path/filepath"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

// Options for one pull.
type Options struct {
	Site      sites.Site
	Secret    string
	SitesRoot string
	// Driver is the local runtime (DDEV or container); it brings its own .ddev guard and mailguard.
	Driver localenv.Driver
	// SiteDir holds baseline and DB cache; default SitesRoot/<name> with the snapshot repo in
	// localgit.GitDir. With SiteDir set (server mode) the repo is localgit.TreeGitDir(SiteDir).
	SiteDir string
	// Docroot holds the WordPress files; default SiteDir/public. Must lie directly below SiteDir.
	Docroot         string
	Full            bool
	Yes             bool                    // accept profile deviations without asking
	NoAnonymize     bool                    // pull personal data in plain text (needs confirmation)
	Confirm         func(string) bool       // asks the user; nil without a terminal
	SaveSite        func(*sites.Site) error // records a confirmed deviation in the profile
	Out             io.Writer
	RowsPerChunk    int
	FileBundleBytes int64
	DBBundleBytes   int64
	// Ctx ends the pull resumably (SIGTERM in the server mode); nil = never.
	Ctx context.Context
	// Progress reports phase progress for pull --json; nil = none.
	Progress func(phase string, done, total int)
	// Report receives the summary of a successful pull; nil = not needed.
	Report *Result
}

// Phases reported through Options.Progress (Spec Server-Modus §4).
const (
	PhaseDelta      = "delta"
	PhaseSetup      = "setup"
	PhaseFiles      = "files"
	PhaseDBDownload = "db_download"
	PhaseDBImport   = "db_import"
	PhasePostSetup  = "postsetup"
	PhaseMailguard  = "mailguard"
)

// Result summarizes a pull for --json.
type Result struct {
	FirstPull          bool   `json:"first_pull"`
	FilesChanged       int    `json:"files_changed"`
	FilesDeleted       int    `json:"files_deleted"`
	TablesLoaded       int    `json:"tables_loaded"`
	TablesTotal        int    `json:"tables_total"`
	Requests           int    `json:"requests"`
	BytesIn            int64  `json:"bytes_in"`
	DurationMS         int64  `json:"duration_ms"`
	LocalURL           string `json:"local_url"`
	AgentVersion       string `json:"agent_version"`
	LocalAdminUser     string `json:"local_admin_user,omitempty"`
	LocalAdminPassword string `json:"local_admin_password,omitempty"`
}

func (o *Options) progress(phase string, done, total int) {
	if o.Progress != nil {
		o.Progress(phase, done, total)
	}
}

// interrupted reports a cancelled Ctx as ErrInterrupted.
func (o *Options) interrupted() error {
	if o.Ctx != nil && o.Ctx.Err() != nil {
		return ErrInterrupted
	}
	return nil
}

// dirs resolves site folder and docroot.
func (o *Options) dirs() (siteDir, docroot string, err error) {
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

// commit records code and baseline in the site's snapshot repo.
func (o *Options) commit(siteDir, docroot, message string) error {
	if o.SiteDir == "" {
		return localgit.Commit(o.SitesRoot, o.Site.Name, message, o.Out)
	}
	return localgit.CommitTree(localgit.TreeGitDir(siteDir), siteDir, filepath.Base(docroot), message, o.Out)
}

// ErrNoProfile: a pull needs a profile from wpsync scan (Spec 5.1).
var ErrNoProfile = errors.New("noch kein Pull-Profil")

// ErrNoInfosheet: the agent has not built an inventory yet.
var ErrNoInfosheet = errors.New("die Site hat noch kein Infosheet")

// ErrNeedsConfirmation: a question would be needed, but there is no terminal and no --yes.
var ErrNeedsConfirmation = errors.New("das Profil kennt neue Tabellen/Plugins nicht")

// ErrUploadsWithoutProxy: the profile leaves out older uploads, but the driver has no uploads
// proxy (container mode) – the site would miss images (exit code usage).
var ErrUploadsWithoutProxy = errors.New("das Profil lässt ältere Uploads aus, im Container-Modus gibt es keinen Uploads-Proxy")

// ErrInterrupted: the pull was cancelled (SIGTERM); downloaded files and tables are kept and the
// next pull continues (exit code interrupted).
var ErrInterrupted = errors.New("Pull abgebrochen – der nächste Pull setzt fort")

// PostSetupError: search-replace, local settings or the mailguard check failed (exit code postsetup_failed).
type PostSetupError struct{ Err error }

func (e *PostSetupError) Error() string { return "Post-Setup fehlgeschlagen: " + e.Err.Error() }
func (e *PostSetupError) Unwrap() error { return e.Err }

// ErrAborted: the user declined to continue (also the declined .ddev takeover of the DDEV driver).
var ErrAborted = localenv.ErrAborted

// ErrAgentCannotAnonymize: the agent is older than 0.3.0 and would deliver plain personal data.
var ErrAgentCannotAnonymize = errors.New("der Agent auf der Site kann noch nicht anonymisieren")

// ErrPlainNeedsConfirmation: --no-anonymize without a terminal needs --yes.
var ErrPlainNeedsConfirmation = errors.New("--no-anonymize braucht eine Bestätigung")

type plan struct {
	scope agentapi.Scope
	delta *agentapi.Delta
}

// prepare fetches infosheet and delta for the site's profile and tags every table with its mode
// key. With ask, deviations from the profile need confirmation and are then recorded; otherwise
// they are only shown.
func prepare(c *agentapi.Client, o *Options, ask bool) (*plan, error) {
	prof := o.Site.Profile
	if prof == nil {
		return nil, ErrNoProfile
	}
	sheet, _, err := c.Infosheet()
	if err != nil {
		return nil, err
	}
	if sheet == nil {
		return nil, ErrNoInfosheet
	}
	if dev := prof.Deviations(sheet); !dev.Empty() {
		fmt.Fprintln(o.Out, "Neu seit dem Scan (wird nach dem Preset behandelt, ändern mit wpsync scan):")
		for _, line := range dev.Lines() {
			fmt.Fprintf(o.Out, "  %s\n", line)
		}
		if ask {
			if !o.Yes {
				if o.Confirm == nil {
					return nil, fmt.Errorf("%w – mit --yes bestätigen oder wpsync scan ausführen", ErrNeedsConfirmation)
				}
				if !o.Confirm("Mit diesem Profil fortfahren?") {
					return nil, ErrAborted
				}
			}
			prof.MarkSeen(sheet)
			if o.SaveSite != nil {
				if err := o.SaveSite(&o.Site); err != nil {
					return nil, err
				}
			}
		}
	}

	scope := prof.Scope(sheet)
	scope.PlainPII = o.NoAnonymize
	modes := prof.TableModes(sheet.Tables)
	delta, err := c.Delta(scope)
	if err != nil {
		return nil, err
	}
	if err := checkDelta(delta, o.Site.Name); err != nil {
		return nil, err
	}
	// W11: the agent excludes VCS folders but no gitfile; such paths are never requested,
	// counted, recorded in the baseline or deleted locally.
	var vcs int
	if delta.Files, vcs = dropVCSPaths(delta.Files); vcs > 0 {
		fmt.Fprintf(o.Out, "  ! %d VCS-Pfade der Quelle übersprungen\n", vcs)
	}
	if !o.NoAnonymize && delta.Env.Anon == "" {
		// An agent before 0.3.0 ignores the scope field and would send plain data (AC-36).
		return nil, &agentapi.OutdatedError{Installed: delta.Env.AgentVersion, Required: agentapi.MinAgentVersion, Err: ErrAgentCannotAnonymize}
	}
	class := make(map[string]string, len(sheet.Tables))
	for _, t := range sheet.Tables {
		class[t.Name] = t.Class
	}
	var fresh, plain []string
	for i := range delta.Tables {
		t := &delta.Tables[i]
		mode, ok := modes[t.Name]
		if !ok {
			// Created after the infosheet: the agent knows nothing else either, so it sends it fully.
			mode = profile.ModeFull
			fresh = append(fresh, t.Name)
		}
		t.Mode = TableKey(mode, scope, delta.Env.TablePrefix, t.Name)
		switch {
		case mode != profile.ModeFull:
		case t.Anonymized:
			t.Mode = AnonKey(delta.Env.Anon, t.Mode)
		case class[t.Name] == "pii":
			plain = append(plain, t.Name)
		}
	}
	if len(fresh) > 0 {
		fmt.Fprintf(o.Out, "  Tabellen jünger als das Infosheet (werden vollständig geladen): %s\n", strings.Join(fresh, ", "))
	}
	if len(plain) > 0 {
		reason := "keine Anonymisierungsregel"
		if o.NoAnonymize {
			reason = "--no-anonymize"
		}
		fmt.Fprintf(o.Out, "  ! Personenbezogene Tabellen im Klartext (%s): %s\n", reason, strings.Join(plain, ", "))
	}
	return &plan{scope: scope, delta: delta}, nil
}

// Run pulls the site into its local environment (Options.Driver) within the profile's scope.
// A cancelled Ctx ends it with ErrInterrupted; the next pull continues where this one stopped.
func Run(o Options) error {
	err := run(o)
	if err != nil && o.interrupted() != nil && !errors.Is(err, ErrInterrupted) {
		return fmt.Errorf("%w (%v)", ErrInterrupted, err)
	}
	return err
}

func run(o Options) error {
	if _, proxy := o.Driver.(localenv.UploadsProxy); !proxy && o.Site.Profile != nil && o.Site.Profile.Uploads.Since != "" {
		return fmt.Errorf("%w (Uploads ab %s) – Profil neu speichern: wpsync scan %s --preset %s --uploads-since alle",
			ErrUploadsWithoutProxy, o.Site.Profile.Uploads.Since, o.Site.Name, o.Site.Profile.Preset)
	}
	if o.NoAnonymize && !o.Yes {
		if o.Confirm == nil {
			return ErrPlainNeedsConfirmation
		}
		if !o.Confirm("Personenbezogene Daten (Benutzer, Kommentare, Bestellungen) im KLARTEXT auf diesen Rechner ziehen?") {
			return ErrAborted
		}
	}
	siteDir, docroot, err := o.dirs()
	if err != nil {
		return err
	}
	client := agentapi.New(o.Site.URL, o.Site.KeyID, o.Secret, o.Site.RPS)
	client.Ctx = o.Ctx
	drv, name := o.Driver, o.Site.Name
	timer := newPhaseTimer(client, o.Out)
	started := time.Now()

	p, err := prepare(client, &o, true)
	if err != nil {
		return err
	}
	delta := p.delta
	fmt.Fprintf(o.Out, "Quelle: WP %s, PHP %s, DB %s – im Profil: %d Tabellen, %d Dateien\n",
		delta.Env.WPVersion, delta.Env.PHPVersion, delta.Env.DBServer, len(delta.Tables), len(delta.Files))
	for _, s := range delta.Skipped {
		fmt.Fprintf(o.Out, "  ! übersprungen (größer als 256 MB): %s (%.0f MB)\n", s.Path, float64(s.Size)/(1<<20))
	}
	timer.done("Delta")
	o.progress(PhaseDelta, 1, 1)

	base, err := baseline.Load(siteDir)
	if err != nil {
		return fmt.Errorf("load baseline: %w", err)
	}
	if o.Full {
		base = baseline.New(o.Site.URL)
	}

	if err := o.interrupted(); err != nil {
		return err
	}
	if err := drv.Configure(delta.Env); err != nil {
		return localenv.Wrap("configure", err)
	}
	runner := drv.Runner(name)
	exists, err := drv.Exists(name)
	if err != nil {
		return localenv.Wrap("exists", err)
	}
	if !exists {
		if err := drv.Setup(name); err != nil {
			return localenv.Wrap("setup", err)
		}
		timer.done("Setup")
	} else if err := drv.Start(name); err != nil {
		return localenv.Wrap("start", err)
	}
	if up, ok := drv.(localenv.UploadsProxy); ok {
		if err := up.UploadsProxy(name, o.Site.URL, agentapi.UserAgent(), o.Site.Profile.Uploads.Proxy); err != nil {
			return localenv.Wrap("uploads proxy", err)
		}
	}
	o.progress(PhaseSetup, 1, 1)

	var present func(agentapi.File) bool
	if !o.Full {
		present = PresentLocally(docroot)
	}
	changed, deleted := DiffFiles(delta.Files, base, present)
	deleted = inScope(deleted, p.scope)
	fmt.Fprintf(o.Out, "Dateien: %d neu/geändert, %d gelöscht\n", len(changed), len(deleted))
	o.progress(PhaseFiles, 0, len(changed))
	fileProgress := func(done, total int) { o.progress(PhaseFiles, done, total) }
	if err := DownloadFiles(client, docroot, changed, o.FileBundleBytes, o.Out, fileProgress); err != nil {
		return err
	}
	RemoveFiles(docroot, deleted)
	RemoveDropIns(docroot)
	timer.done("Dateien")
	if err := o.interrupted(); err != nil {
		return err
	}

	tables := ChangedTables(delta.Tables, base)
	fmt.Fprintf(o.Out, "Tabellen: %d von %d neu zu laden\n", len(tables), len(delta.Tables))
	if len(tables) > 0 {
		dir := filepath.Join(siteDir, ".wpsync", "db", "tables")
		opts := DBOptions{RowsPerChunk: o.RowsPerChunk, BundleBytes: o.DBBundleBytes, Scope: p.scope,
			Progress: func(done, total int) { o.progress(PhaseDBDownload, done, total) }}
		if err := DownloadTables(client, dir, tables, opts); err != nil {
			return err
		}
		timer.done("DB-Download")
		if err := o.interrupted(); err != nil {
			return err
		}

		if err := importTables(runner, dir, tables); err != nil {
			return localenv.Wrap("db import", err)
		}
		timer.done("DB-Import")
		o.progress(PhaseDBImport, 1, 1)

		localURL, err := drv.LocalURL(name)
		if err != nil {
			return localenv.Wrap("local url", err)
		}
		setup := PostSetupOptions{ExcludedPlugins: p.scope.ExcludePlugins, LocalAdmin: !o.NoAnonymize}
		if err := PostSetup(runner, delta.Env, localURL, setup, o.Out); err != nil {
			return &PostSetupError{Err: err}
		}
		timer.done("Post-Setup")
		o.progress(PhasePostSetup, 1, 1)
	}

	if err := MailguardCheck(runner); err != nil {
		_, _ = drv.Stop(name)
		return &PostSetupError{Err: err}
	}
	fmt.Fprintln(o.Out, "  local-mailguard aktiv ✓")
	o.progress(PhaseMailguard, 1, 1)

	next := baseline.New(o.Site.URL)
	for _, f := range delta.Files {
		next.Files[f.Path] = baseline.FileStamp{Size: f.Size, MTime: f.MTime}
	}
	for _, t := range delta.Tables {
		next.Modes[t.Name] = t.Mode
		if t.Checksum != nil {
			next.Tables[t.Name] = *t.Checksum
		}
	}
	if err := baseline.Save(siteDir, next); err != nil {
		return fmt.Errorf("save baseline: %w", err)
	}
	if err := o.commit(siteDir, docroot, fmt.Sprintf("pull %s from %s (profile %s)", time.Now().Format(time.RFC3339), o.Site.URL, o.Site.Profile.Preset)); err != nil {
		return err
	}

	localURL, err := drv.LocalURL(name)
	if err != nil {
		return localenv.Wrap("local url", err)
	}
	if o.Report != nil {
		*o.Report = Result{
			FirstPull: !exists, FilesChanged: len(changed), FilesDeleted: len(deleted),
			TablesLoaded: len(tables), TablesTotal: len(delta.Tables),
			Requests: client.Stats.Requests, BytesIn: client.Stats.BytesIn,
			DurationMS: time.Since(started).Milliseconds(), LocalURL: localURL, AgentVersion: delta.Env.AgentVersion,
		}
		if !o.NoAnonymize {
			o.Report.LocalAdminUser, o.Report.LocalAdminPassword = LocalAdminUser, LocalAdminPassword
		}
	}
	fmt.Fprintf(o.Out, "\n✓ Fertig in %s – %d Requests, %.1f MB übertragen\n  %s\n",
		time.Since(started).Round(time.Millisecond), client.Stats.Requests, float64(client.Stats.BytesIn)/(1<<20), localURL)
	if !o.NoAnonymize {
		fmt.Fprintf(o.Out, "  Login: %s / %s – %s/wp-admin/ (übernommene Konten sind pseudonymisiert)\n", LocalAdminUser, LocalAdminPassword, localURL)
	}
	timer.print()
	return nil
}

// inScope drops paths outside the scope: they stay untouched locally (e.g. uploads the proxy fetched).
func inScope(paths []string, s agentapi.Scope) []string {
	var out []string
	for _, p := range paths {
		if profile.InScope(s, p) {
			out = append(out, p)
		}
	}
	return out
}

// IsSuspectedBan reports whether err means the server blocked us.
func IsSuspectedBan(err error) bool { return errors.Is(err, agentapi.ErrSuspectedBan) }

type phaseTimer struct {
	client *agentapi.Client
	out    io.Writer
	start  time.Time
	reqs   int
	bytes  int64
	lines  []string
}

func newPhaseTimer(c *agentapi.Client, out io.Writer) *phaseTimer {
	return &phaseTimer{client: c, out: out, start: time.Now()}
}

func (p *phaseTimer) done(name string) {
	p.lines = append(p.lines, fmt.Sprintf("  %-12s %9s  %4d Requests  %7.1f MB", name,
		time.Since(p.start).Round(time.Millisecond), p.client.Stats.Requests-p.reqs, float64(p.client.Stats.BytesIn-p.bytes)/(1<<20)))
	p.start, p.reqs, p.bytes = time.Now(), p.client.Stats.Requests, p.client.Stats.BytesIn
}

func (p *phaseTimer) print() {
	for _, l := range p.lines {
		fmt.Fprintln(p.out, l)
	}
}
