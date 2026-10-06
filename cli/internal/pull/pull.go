package pull

import (
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
					return nil, errors.New("das Profil kennt neue Tabellen/Plugins nicht – mit --yes bestätigen oder wpsync scan ausführen")
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
	if !o.NoAnonymize && delta.Env.Anon == "" {
		// An agent before 0.3.0 ignores the scope field and would send plain data (AC-36).
		return nil, ErrAgentCannotAnonymize
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
func Run(o Options) error {
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

	base, err := baseline.Load(siteDir)
	if err != nil {
		return fmt.Errorf("load baseline: %w", err)
	}
	if o.Full {
		base = baseline.New(o.Site.URL)
	}

	if err := drv.Configure(delta.Env); err != nil {
		return err
	}
	runner := drv.Runner(name)
	exists, err := drv.Exists(name)
	if err != nil {
		return err
	}
	if !exists {
		if err := drv.Setup(name); err != nil {
			return err
		}
		timer.done("Setup")
	} else if err := drv.Start(name); err != nil {
		return err
	}
	if up, ok := drv.(localenv.UploadsProxy); ok {
		if err := up.UploadsProxy(name, o.Site.URL, agentapi.UserAgent(), o.Site.Profile.Uploads.Proxy); err != nil {
			return err
		}
	}

	var present func(agentapi.File) bool
	if !o.Full {
		present = PresentLocally(docroot)
	}
	changed, deleted := DiffFiles(delta.Files, base, present)
	deleted = inScope(deleted, p.scope)
	fmt.Fprintf(o.Out, "Dateien: %d neu/geändert, %d gelöscht\n", len(changed), len(deleted))
	if err := DownloadFiles(client, docroot, changed, o.FileBundleBytes, o.Out); err != nil {
		return err
	}
	RemoveFiles(docroot, deleted)
	RemoveDropIns(docroot)
	timer.done("Dateien")

	tables := ChangedTables(delta.Tables, base)
	fmt.Fprintf(o.Out, "Tabellen: %d von %d neu zu laden\n", len(tables), len(delta.Tables))
	if len(tables) > 0 {
		dir := filepath.Join(siteDir, ".wpsync", "db", "tables")
		opts := DBOptions{RowsPerChunk: o.RowsPerChunk, BundleBytes: o.DBBundleBytes, Scope: p.scope}
		if err := DownloadTables(client, dir, tables, opts); err != nil {
			return err
		}
		timer.done("DB-Download")

		if err := importTables(runner, dir, tables); err != nil {
			return err
		}
		timer.done("DB-Import")

		localURL, err := drv.LocalURL(name)
		if err != nil {
			return err
		}
		setup := PostSetupOptions{ExcludedPlugins: p.scope.ExcludePlugins, LocalAdmin: !o.NoAnonymize}
		if err := PostSetup(runner, delta.Env, localURL, setup, o.Out); err != nil {
			return err
		}
		timer.done("Post-Setup")
	}

	if err := MailguardCheck(runner); err != nil {
		_, _ = drv.Stop(name)
		return err
	}
	fmt.Fprintln(o.Out, "  local-mailguard aktiv ✓")

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
		return err
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
