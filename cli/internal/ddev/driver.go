package ddev

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"path/filepath"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/sites"
)

// GlobalDir is the working directory of global ddev calls (list, stop <names>): never a project
// directory, whose .ddev ddev would otherwise pick up from the current directory.
const GlobalDir = "/"

// Driver runs every site as a DDEV project below SitesRoot (Mac, default driver). Every call that
// concerns one project goes through that project's guard (trusted .ddev, hardened containers);
// global calls run in GlobalDir with --skip-hooks or after a per-site check.
type Driver struct {
	SitesRoot       string
	MailguardSource string            // mounted into the web container (pull only)
	State           Store             // trusted .ddev state, outside the sites root
	Docker          Docker            // nil: the docker CLI
	Out, Err        io.Writer         // ddev output and wpsync's notes; nil discards
	Confirm         func(string) bool // takeover of an existing .ddev; nil without a terminal
	// NewRunner creates the unguarded ddev runner for a directory; nil uses Exec. Tests replace it.
	NewRunner func(dir string) Runner

	env      agentapi.Env
	projects map[string]*Project
	guard    stopGuard // nil: the driver's own check; tests replace it
}

var (
	_ localenv.Driver       = (*Driver)(nil)
	_ localenv.UploadsProxy = (*Driver)(nil)
)

func (d *Driver) dir(site string) string { return filepath.Join(d.SitesRoot, site) }

func (d *Driver) docker() Docker {
	if d.Docker == nil {
		return DockerExec{}
	}
	return d.Docker
}

func (d *Driver) out() io.Writer {
	if d.Out == nil {
		return io.Discard
	}
	return d.Out
}

func (d *Driver) exec(dir string) Runner {
	if d.NewRunner != nil {
		return d.NewRunner(dir)
	}
	return &Exec{Dir: dir, Stdout: d.Out, Stderr: d.Err}
}

// project opens the trusted .ddev state of a site once per driver.
func (d *Driver) project(site string) (*Project, error) {
	if p, ok := d.projects[site]; ok {
		return p, nil
	}
	if d.State.Dir == "" {
		return nil, errors.New("no location for the trusted .ddev state")
	}
	p, err := OpenProject(site, d.dir(site), d.State, d.docker())
	if err != nil {
		return nil, err
	}
	if d.projects == nil {
		d.projects = map[string]*Project{}
	}
	d.projects[site] = p
	return p, nil
}

// Exists reports whether the site already has a DDEV project.
func (d *Driver) Exists(site string) (bool, error) { return Exists(d.dir(site)), nil }

// Configure keeps the source environment for Setup and Start.
func (d *Driver) Configure(env agentapi.Env) error {
	d.env = env
	return nil
}

// Setup creates the DDEV project on the first pull (Start below decides that from .ddev itself).
func (d *Driver) Setup(site string) error { return d.start(site) }

// Start checks .ddev, updates wpsync's own compose files and starts the project.
func (d *Driver) Start(site string) error { return d.start(site) }

// start is the first ddev call of a pull: containers that can still write to .ddev are stopped
// through docker (no ddev, so no hooks), an existing site without a trusted .ddev state is taken
// over once the user confirmed it (--yes does not), then Start creates or starts the project.
func (d *Driver) start(site string) error {
	p, err := d.project(site)
	if err != nil {
		return err
	}
	stopped, err := StopUnhardened(d.docker(), site, p.Dir)
	if err != nil {
		return fmt.Errorf("DDEV-Container von %s prüfen: %w", site, err)
	}
	if stopped {
		fmt.Fprintf(d.out(), "  DDEV-Container von %s konnten .ddev noch beschreiben – ohne ddev gestoppt, sie werden gehärtet neu erstellt\n", site)
	}
	if Exists(p.Dir) && !p.Trusted() {
		if err := d.takeOver(p); err != nil {
			return err
		}
	}
	_, err = Start(p.Runner(d.exec(p.Dir)), p, d.env, d.MailguardSource, d.out())
	return err
}

// takeOver records the current .ddev of a site pulled before this check existed, after showing
// everything that reaches the Mac.
func (d *Driver) takeOver(p *Project) error {
	own, err := OwnFiles(d.MailguardSource)
	if err != nil {
		return err
	}
	changes, shown, content, err := p.Review()
	if err != nil {
		return err
	}
	fmt.Fprintf(d.out(), "wpsync prüft ab jetzt vor jedem ddev-Aufruf .ddev von %s. Einmalig wird der heutige Stand übernommen:\n", p.Name)
	DescribeContent(d.out(), changes, content, own, true)
	if d.Confirm == nil {
		return fmt.Errorf("%w für %s – ohne Terminal keine Übernahme. Ansehen und übernehmen: wpsync trust %s", ErrNotAdopted, p.Name, p.Name)
	}
	if !d.Confirm("Diesen Stand von .ddev übernehmen?") {
		return localenv.ErrAborted
	}
	return p.Trust(shown)
}

// Runner runs ddev in the site directory; every call is checked against the trusted .ddev.
func (d *Driver) Runner(site string) localenv.Runner { return &siteRunner{d: d, site: site} }

// siteRunner resolves the site's guarded project runner on every call, so it can be handed out
// before Setup or Start opened the project.
type siteRunner struct {
	d    *Driver
	site string
}

func (r *siteRunner) guarded() (Runner, error) {
	p, err := r.d.project(r.site)
	if err != nil {
		return nil, err
	}
	return p.Runner(r.d.exec(p.Dir)), nil
}

func (r *siteRunner) Run(args ...string) error {
	g, err := r.guarded()
	if err != nil {
		return err
	}
	return g.Run(args...)
}

func (r *siteRunner) Output(args ...string) (string, error) {
	g, err := r.guarded()
	if err != nil {
		return "", err
	}
	return g.Output(args...)
}

func (r *siteRunner) RunStdin(stdin io.Reader, args ...string) error {
	g, err := r.guarded()
	if err != nil {
		return err
	}
	return g.RunStdin(stdin, args...)
}

// LocalURL asks `ddev describe` for the project's HTTP URL.
func (d *Driver) LocalURL(site string) (string, error) { return LocalURL(d.Runner(site)) }

// UploadsProxy writes or removes the nginx proxy for missing uploads and restarts on change.
func (d *Driver) UploadsProxy(site, sourceURL, userAgent string, enabled bool) error {
	changed, err := WriteUploadsProxy(d.dir(site), sourceURL, userAgent, enabled)
	if err != nil || !changed {
		return err
	}
	fmt.Fprintln(d.out(), "  Uploads-Proxy konfiguriert – DDEV startet neu")
	return d.Runner(site).Run("restart")
}

// stopGuard decides per site whether `ddev stop` may run: it runs the project's pre-stop hooks
// on the Mac. A site that fails the check is halted through docker instead.
type stopGuard interface {
	Check(name string) error
	Halt(name string) error
}

// Stop stops the given projects with one global `ddev stop`, but only those whose .ddev matches
// the trusted state and whose containers cannot write to it; the others are halted through
// docker, and a deviating one is reported as an error after the others were stopped.
func (d *Driver) Stop(names ...string) (int, error) {
	if len(names) == 0 {
		return 0, nil
	}
	g := d.guard
	if g == nil {
		g = ownGuard{d}
	}
	var checked, failed []string
	halted := 0
	for _, n := range names {
		err := g.Check(n)
		if err == nil {
			checked = append(checked, n)
			continue
		}
		if haltErr := g.Halt(n); haltErr != nil {
			failed = append(failed, fmt.Sprintf("%v\n%s nicht gestoppt: %v", err, n, haltErr))
			continue
		}
		halted++
		if !errors.Is(err, ErrNotAdopted) {
			failed = append(failed, fmt.Sprintf("%v\n%s wurde ohne ddev angehalten (docker stop).", err, n))
		}
	}
	if len(checked) > 0 {
		if err := d.exec(GlobalDir).Run(append([]string{"stop"}, checked...)...); err != nil {
			return halted, err
		}
	}
	stopped := halted + len(checked)
	if len(failed) > 0 {
		return stopped, errors.New(strings.Join(failed, "\n\n"))
	}
	return stopped, nil
}

// ownGuard is the check of `wpsync stop`: trusted .ddev and hardened containers.
type ownGuard struct{ d *Driver }

func (g ownGuard) Check(name string) error {
	if !sites.ValidName(name) {
		return fmt.Errorf("unerwarteter Projektname %q", name)
	}
	siteDir := g.d.dir(name)
	p, err := OpenProject(name, siteDir, g.d.State, g.d.docker())
	if err != nil {
		return err
	}
	if err := p.Check("stop"); err != nil {
		return err
	}
	// The check is only as good as the containers' read-only .ddev: one that can still write
	// could add a pre-stop hook after it.
	cs, err := Containers(g.d.docker(), name)
	if err != nil {
		return err
	}
	for _, c := range cs {
		if !Hardened(c, siteDir) {
			return fmt.Errorf("%w (%s)", ErrNotHardened, c.Service)
		}
	}
	return nil
}

// Halt stops the containers through docker: no ddev, so no pre-stop hooks.
func (g ownGuard) Halt(name string) error {
	_, err := StopContainers(g.d.docker(), name)
	return err
}

type ddevProject struct {
	Name     string `json:"name"`
	Status   string `json:"status"`
	AppRoot  string `json:"approot"`
	HTTPSURL string `json:"httpsurl"`
}

// List returns the DDEV projects below SitesRoot; other projects on the Mac stay invisible.
func (d *Driver) List() ([]localenv.Env, error) {
	// ddev list runs the pre-describe hooks of every running project – without --skip-hooks a
	// hook a site wrote into its .ddev would run on the Mac (no project check covers this call).
	out, err := d.exec(GlobalDir).Output("list", "-j", "--skip-hooks")
	if err != nil {
		return nil, fmt.Errorf("list ddev projects: %w", err)
	}
	var resp struct {
		Raw []ddevProject `json:"raw"`
	}
	if err := json.Unmarshal([]byte(out), &resp); err != nil {
		return nil, fmt.Errorf("parse ddev list: %w", err)
	}
	var envs []localenv.Env
	for _, p := range resp.Raw {
		if inside(d.SitesRoot, p.AppRoot) {
			envs = append(envs, localenv.Env{Name: p.Name, Status: p.Status, LocalURL: p.HTTPSURL, Ref: p.Name})
		}
	}
	return envs, nil
}

func inside(root, path string) bool {
	rel, err := filepath.Rel(filepath.Clean(root), filepath.Clean(path))
	return err == nil && rel != "." && !strings.HasPrefix(rel, "..")
}
