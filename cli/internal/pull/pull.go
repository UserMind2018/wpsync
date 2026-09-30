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
	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

// Options for one pull.
type Options struct {
	Site            sites.Site
	Secret          string
	SitesRoot       string
	MailguardSource string
	Full            bool
	Yes             bool                    // accept profile deviations without asking
	Confirm         func(string) bool       // asks the user; nil without a terminal
	SaveSite        func(*sites.Site) error // records a confirmed deviation in the profile
	Out             io.Writer
	RowsPerChunk    int
	FileBundleBytes int64
	DBBundleBytes   int64
}

// ErrNoProfile: a pull needs a profile from wpsync scan (Spec 5.1).
var ErrNoProfile = errors.New("noch kein Pull-Profil")

// ErrNoInfosheet: the agent has not built an inventory yet.
var ErrNoInfosheet = errors.New("die Site hat noch kein Infosheet")

// ErrAborted: the user declined to continue.
var ErrAborted = errors.New("abgebrochen")

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
	modes := prof.TableModes(sheet.Tables)
	delta, err := c.Delta(scope)
	if err != nil {
		return nil, err
	}
	var fresh []string
	for i := range delta.Tables {
		t := &delta.Tables[i]
		mode, ok := modes[t.Name]
		if !ok {
			// Created after the infosheet: the agent knows nothing else either, so it sends it fully.
			mode = profile.ModeFull
			fresh = append(fresh, t.Name)
		}
		t.Mode = TableKey(mode, scope, delta.Env.TablePrefix, t.Name)
	}
	if len(fresh) > 0 {
		fmt.Fprintf(o.Out, "  Tabellen jünger als das Infosheet (werden vollständig geladen): %s\n", strings.Join(fresh, ", "))
	}
	return &plan{scope: scope, delta: delta}, nil
}

// Run pulls the site into its DDEV project within the profile's scope.
func Run(o Options) error {
	client := agentapi.New(o.Site.URL, o.Site.KeyID, o.Secret, o.Site.RPS)
	siteDir := filepath.Join(o.SitesRoot, o.Site.Name)
	docroot := filepath.Join(siteDir, "public")
	runner := &ddev.Exec{Dir: siteDir, Stdout: o.Out, Stderr: o.Out}
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

	if !ddev.Exists(siteDir) {
		if err := ddev.Setup(runner, siteDir, o.Site.Name, delta.Env, o.MailguardSource); err != nil {
			return err
		}
		timer.done("DDEV-Setup")
	} else if err := runner.Run("start", "-y"); err != nil {
		return err
	}
	proxyChanged, err := ddev.WriteUploadsProxy(siteDir, o.Site.URL, agentapi.UserAgent(), o.Site.Profile.Uploads.Proxy)
	if err != nil {
		return err
	}
	if proxyChanged {
		fmt.Fprintln(o.Out, "  Uploads-Proxy konfiguriert – DDEV startet neu")
		if err := runner.Run("restart"); err != nil {
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

		reader, closeAll, err := ImportReader(dir, tables)
		if err != nil {
			return err
		}
		importErr := runner.RunStdin(reader, "mysql")
		closeAll()
		if importErr != nil {
			return importErr
		}
		timer.done("DB-Import")

		localURL, err := ddev.LocalURL(runner)
		if err != nil {
			return err
		}
		if err := PostSetup(runner, delta.Env, localURL, PostSetupOptions{ExcludedPlugins: p.scope.ExcludePlugins}, o.Out); err != nil {
			return err
		}
		timer.done("Post-Setup")
	}

	if err := MailguardCheck(runner); err != nil {
		_ = runner.Run("stop")
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
	if err := localgit.Commit(siteDir, fmt.Sprintf("pull %s from %s (profile %s)", time.Now().Format(time.RFC3339), o.Site.URL, o.Site.Profile.Preset)); err != nil {
		return err
	}

	localURL, err := ddev.LocalURL(runner)
	if err != nil {
		return err
	}
	fmt.Fprintf(o.Out, "\n✓ Fertig in %s – %d Requests, %.1f MB übertragen\n  %s\n",
		time.Since(started).Round(time.Millisecond), client.Stats.Requests, float64(client.Stats.BytesIn)/(1<<20), localURL)
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
