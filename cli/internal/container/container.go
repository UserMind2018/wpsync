// Package container runs a site in a WordPress container that the caller created (server mode,
// Spec Server-Modus §3). wpsync creates no containers, networks, databases or users: it runs
// WP-CLI and the SQL import in short-lived containers in the network of the site's container.
package container

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"syscall"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/localenv"
)

// Labels set by the caller; List only sees containers with LabelStudio.
const (
	LabelStudio = "um.website-studio=1"
	LabelSlug   = "um.slug"
)

// wwwData is www-data in the official wordpress image (apache); WP-CLI writes as this user.
const wwwData = "33:33"

// stopGrace: after SIGTERM the docker CLI gets this long before it is killed.
const stopGrace = 10 * time.Second

// removeTimeout bounds the docker rm -f after a cancelled run; the pull's own context is done then.
const removeTimeout = 30 * time.Second

// LabelRun marks every docker run of wpsync with its site, so a pull finds runs that outlived a
// killed predecessor (RemoveOrphans).
const LabelRun = "wpsync.site"

// runName names one docker run: wpsync-<site>-<random>. Tests replace it.
var runName = func(site string) string {
	b := make([]byte, 6)
	rand.Read(b)
	return "wpsync-" + site + "-" + hex.EncodeToString(b)
}

// Config are the per-call parameters of the container mode.
type Config struct {
	Site       string // wpsync site name
	Container  string // WordPress container of the site
	Docroot    string // docroot on the host (= path in the OS container)
	DBHost     string
	DBName     string
	DBUser     string
	DBPassword string // from stdin, never from arguments
	LocalURL   string // target of the search-replace
	CLIImage   string // "" = wordpress:cli-php<PHP of the source>
}

var (
	nameRe  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)
	imageRe = regexp.MustCompile(`^[a-z0-9][a-z0-9./:_-]{0,254}$`)
)

// Validate checks the flags before anything runs.
func (c Config) Validate() error {
	var problems []string
	if !nameRe.MatchString(c.Container) {
		problems = append(problems, "--container fehlt oder ist ungültig")
	}
	if c.Docroot == "" || !filepath.IsAbs(c.Docroot) {
		problems = append(problems, "--docroot muss ein absoluter Pfad sein")
	}
	for flag, v := range map[string]string{"--db-host": c.DBHost, "--db-name": c.DBName, "--db-user": c.DBUser} {
		if !nameRe.MatchString(v) {
			problems = append(problems, flag+" fehlt oder ist ungültig")
		}
	}
	if c.DBPassword == "" || strings.ContainsAny(c.DBPassword, "\r\n") {
		problems = append(problems, "DB-Passwort fehlt (zweite Zeile von stdin, --secret-stdin)")
	}
	if u, err := url.Parse(c.LocalURL); err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		problems = append(problems, "--local-url muss eine http(s)-URL sein")
	}
	if c.CLIImage != "" && !imageRe.MatchString(c.CLIImage) {
		problems = append(problems, "--cli-image ist ungültig")
	}
	if len(problems) > 0 {
		return errors.New("Container-Modus: " + strings.Join(problems, "; "))
	}
	return nil
}

// Driver implements localenv.Driver with the docker CLI.
type Driver struct {
	Config
	Ctx      context.Context
	Out, Err io.Writer

	php, prefix, wpVersion string
}

var (
	_ localenv.Driver        = (*Driver)(nil)
	_ localenv.OrphanRemover = (*Driver)(nil)
)

// ErrNoCore: the docroot has no WordPress core. The site network has no internet, so wpsync does
// not download it; the caller puts the core in place before the first pull (exit code local_env).
var ErrNoCore = errors.New("kein WordPress-Core im Docroot – der Aufrufer lädt ihn vor dem Erst-Pull (wp core download --version=<Version der Quelle>)")

func (d *Driver) ctx() context.Context {
	if d.Ctx == nil {
		return context.Background()
	}
	return d.Ctx
}

// docker runs the docker CLI. Values in env reach the child only through its environment;
// args name them with a bare "-e NAME", so no secret appears in a process list.
func (d *Driver) docker(stdin io.Reader, stdout io.Writer, env []string, args ...string) error {
	cmd := exec.CommandContext(d.ctx(), "docker", args...)
	cmd.Cancel = func() error { return cmd.Process.Signal(syscall.SIGTERM) }
	cmd.WaitDelay = stopGrace
	cmd.Env = append(os.Environ(), env...)
	cmd.Stdin, cmd.Stdout, cmd.Stderr = stdin, stdout, d.Err
	if err := cmd.Run(); err != nil {
		if ctxErr := d.ctx().Err(); ctxErr != nil && !errors.Is(err, ctxErr) {
			return fmt.Errorf("docker %s: %w (%w)", args[0], ctxErr, err)
		}
		return fmt.Errorf("docker %s: %w", args[0], err)
	}
	return nil
}

func (d *Driver) output(args ...string) (string, error) {
	var out strings.Builder
	err := d.docker(nil, &out, nil, args...)
	return out.String(), err
}

func (d *Driver) stateDir() string { return filepath.Join(filepath.Dir(d.Docroot), ".wpsync") }

func (d *Driver) marker() string { return filepath.Join(d.stateDir(), "container-setup") }

// Exists requires the running container (start stays with the caller) and reports whether
// wpsync already set up the docroot.
func (d *Driver) Exists(string) (bool, error) {
	out, err := d.output("inspect", "--type=container", "--format", "{{.State.Running}}", d.Container)
	if err != nil {
		return false, fmt.Errorf("Container %s fehlt – der Aufrufer legt ihn an (%v)", d.Container, err)
	}
	if strings.TrimSpace(out) != "true" {
		return false, fmt.Errorf("Container %s läuft nicht – Start bleibt beim Aufrufer", d.Container)
	}
	_, err = os.Stat(d.marker())
	return err == nil, nil
}

// Configure takes PHP version, WordPress version and table prefix of the source; on every pull.
func (d *Driver) Configure(env agentapi.Env) error {
	d.php = ddev.MajorMinor(env.PHPVersion)
	if d.php == "" {
		return errors.New("die Quelle meldet keine PHP-Version")
	}
	d.prefix = env.TablePrefix
	if d.prefix == "" {
		d.prefix = "wp_"
	}
	d.wpVersion = env.WPVersion
	return nil
}

func (d *Driver) image() string {
	if d.CLIImage != "" {
		return d.CLIImage
	}
	return "wordpress:cli-php" + d.php
}

// Setup checks what the caller prepared for the first pull: a WordPress core in the docroot
// (wpsync downloads nothing – the site network has no internet) and wp-config.php, which the
// container's entrypoint writes from the WORDPRESS_* variables.
func (d *Driver) Setup(string) error {
	version, err := os.ReadFile(filepath.Join(d.Docroot, "wp-includes", "version.php"))
	if err != nil {
		return fmt.Errorf("%w (%s)", ErrNoCore, d.Docroot)
	}
	if _, err := os.Stat(filepath.Join(d.Docroot, "wp-config.php")); err != nil {
		return fmt.Errorf("wp-config.php fehlt in %s – der WordPress-Container legt sie beim ersten Start an", d.Docroot)
	}
	if found := coreVersion(version); d.wpVersion != "" && found != d.wpVersion {
		fmt.Fprintf(d.Out, "  ! WordPress-Core im Docroot ist %s, die Quelle hat %s\n", found, d.wpVersion)
	}
	if err := d.ensureHtaccess(); err != nil {
		return err
	}
	if err := os.MkdirAll(d.stateDir(), 0o755); err != nil {
		return err
	}
	return os.WriteFile(d.marker(), []byte(d.wpVersion+"\n"), 0o644)
}

// ensureHtaccess writes WordPress' default rewrite rules if the docroot has no .htaccess. The pull
// only brings wp-content/, the caller only the core; without the rules apache answers /wp-json/
// with 404 and the Elementor editor does not load. Whatever is there – file, directory or
// symlink – stays untouched, and O_EXCL never follows a symlink out of the docroot.
func (d *Driver) ensureHtaccess() error {
	path := filepath.Join(d.Docroot, ".htaccess")
	if _, err := os.Lstat(path); !errors.Is(err, os.ErrNotExist) {
		return err
	}
	base := "/"
	if u, err := url.Parse(d.Config.LocalURL); err == nil && u.Path != "" {
		base = "/" + strings.Trim(u.Path, "/") + "/"
		base = strings.ReplaceAll(base, "//", "/")
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if errors.Is(err, os.ErrExist) {
		return nil
	}
	if err != nil {
		return err
	}
	_, err = fmt.Fprintf(f, htaccess, base, base)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err == nil {
		fmt.Fprintln(d.Out, "  .htaccess mit den WordPress-Standardregeln angelegt")
	}
	return err
}

const htaccess = `# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%%{HTTP:Authorization}]
RewriteBase %s
RewriteRule ^index\.php$ - [L]
RewriteCond %%{REQUEST_FILENAME} !-f
RewriteCond %%{REQUEST_FILENAME} !-d
RewriteRule . %sindex.php [L]
</IfModule>
# END WordPress
`

var coreVersionRe = regexp.MustCompile(`\$wp_version\s*=\s*'([^']+)'`)

// coreVersion reads $wp_version from wp-includes/version.php ("" if not found).
func coreVersion(versionPHP []byte) string {
	if m := coreVersionRe.FindSubmatch(versionPHP); m != nil {
		return string(m[1])
	}
	return ""
}

// Start only restores a missing .htaccess: Exists already required the running container.
func (d *Driver) Start(string) error { return d.ensureHtaccess() }

// Runner runs wp-cli and the SQL import in the site's network.
func (d *Driver) Runner(string) localenv.Runner { return &runner{d: d} }

// LocalURL is given by the caller (--local-url).
func (d *Driver) LocalURL(string) (string, error) { return d.Config.LocalURL, nil }

// Stop stops containers; the configured site name maps to its container.
func (d *Driver) Stop(refs ...string) (int, error) {
	if len(refs) == 0 {
		return 0, nil
	}
	names := make([]string, len(refs))
	for i, r := range refs {
		names[i] = r
		if r == d.Site && d.Container != "" {
			names[i] = d.Container
		}
	}
	if err := d.docker(nil, d.Out, nil, append([]string{"stop"}, names...)...); err != nil {
		return 0, err
	}
	return len(names), nil
}

type psLine struct {
	Names  string `json:"Names"`
	State  string `json:"State"`
	Labels string `json:"Labels"`
}

// List returns the containers labelled um.website-studio=1; name is um.slug if set.
func (d *Driver) List() ([]localenv.Env, error) {
	out, err := d.output("ps", "-a", "--filter", "label="+LabelStudio, "--format", "{{json .}}")
	if err != nil {
		return nil, err
	}
	var envs []localenv.Env
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		var p psLine
		if err := json.Unmarshal([]byte(line), &p); err != nil {
			return nil, fmt.Errorf("parse docker ps: %w", err)
		}
		name := label(p.Labels, LabelSlug)
		if name == "" {
			name = p.Names
		}
		envs = append(envs, localenv.Env{Name: name, Status: state(p.State), Ref: p.Names})
	}
	return envs, nil
}

func label(labels, key string) string {
	for _, kv := range strings.Split(labels, ",") {
		if k, v, ok := strings.Cut(kv, "="); ok && k == key {
			return v
		}
	}
	return ""
}

func state(s string) string {
	switch s {
	case "running":
		return localenv.StatusRunning
	case "paused":
		return localenv.StatusPaused
	}
	return localenv.StatusStopped
}

// runner translates the DDEV-style commands of package pull into docker runs.
type runner struct{ d *Driver }

// wpEnv: the wordpress image builds wp-config.php from these variables; WP-CLI needs the same.
func (r *runner) wpEnv() (flags, env []string) {
	d := r.d
	vars := [][2]string{
		{"WORDPRESS_DB_HOST", d.DBHost}, {"WORDPRESS_DB_NAME", d.DBName}, {"WORDPRESS_DB_USER", d.DBUser},
		{"WORDPRESS_DB_PASSWORD", d.DBPassword}, {"WORDPRESS_TABLE_PREFIX", d.prefix},
	}
	for _, v := range vars {
		flags = append(flags, "-e", v[0])
		env = append(env, v[0]+"="+v[1])
	}
	return flags, env
}

// translate returns the docker run for args and the container name it gets. Every run has
// --init, so a SIGTERM that the docker CLI forwards reaches WP-CLI or the client instead of a PID 1
// that ignores it, plus a name and the label LabelRun for the cleanup after a cancel or a kill.
func (r *runner) translate(args []string) (dockerArgs, env []string, name string, err error) {
	d := r.d
	if len(args) == 0 {
		return nil, nil, "", errors.New("container: empty command")
	}
	net := "container:" + d.Container
	name = runName(d.Site)
	own := []string{"--init", "--name", name, "--label", LabelRun + "=" + d.Site, "--network", net}
	switch args[0] {
	case "wp":
		flags, env := r.wpEnv()
		a := append(append([]string{"run", "--rm"}, own...), "--volumes-from", d.Container, "--user", wwwData)
		a = append(append(a, flags...), d.image())
		return append(a, args...), env, name, nil
	case "mysql":
		opts, err := clientOptions(args[1:])
		if err != nil {
			return nil, nil, "", err
		}
		a := append(append([]string{"run", "--rm", "-i"}, own...), "-e", "MYSQL_PWD", d.image(), "mariadb", "--skip-ssl")
		a = append(append(a, opts...), "-h", d.DBHost, "-u", d.DBUser, d.DBName)
		return a, []string{"MYSQL_PWD=" + d.DBPassword}, name, nil
	}
	return nil, nil, "", fmt.Errorf("container: unsupported command %q", args[0])
}

// importHardening are the client options pull sends with every SQL import (pull.importArgs): they
// turn off client commands such as \! and source and LOAD DATA LOCAL in the site's dump. The
// container mode keeps them and refuses an import without them.
var importHardening = []string{"--binary-mode", "--local-infile=0"}

// clientOptions keeps the hardening options of a `mysql …` call and drops DDEV's import account
// (--user, --password, --database): the container mode connects as --db-user to --db-name.
func clientOptions(args []string) ([]string, error) {
	var kept []string
	for _, a := range args {
		switch {
		case slices.Contains(importHardening, a):
			kept = append(kept, a)
		case strings.HasPrefix(a, "--user="), strings.HasPrefix(a, "--password="), strings.HasPrefix(a, "--database="):
		default:
			return nil, fmt.Errorf("container: unsupported mysql option %q", a)
		}
	}
	for _, h := range importHardening {
		if !slices.Contains(kept, h) {
			return nil, fmt.Errorf("container: SQL import without %s refused", h)
		}
	}
	return kept, nil
}

func (r *runner) exec(stdin io.Reader, stdout io.Writer, args []string) error {
	dockerArgs, env, name, err := r.translate(args)
	if err != nil {
		return err
	}
	started := r.d.ctx().Err() == nil // cancelled before: docker does not even start
	if err := r.d.docker(stdin, stdout, env, dockerArgs...); err != nil {
		if started && r.d.ctx().Err() != nil {
			// Cancelled (SIGTERM): the CLI is gone, the container may still run. --rm only
			// applies once it ends, so remove it here; a failure is left to RemoveOrphans.
			r.d.remove(name)
		}
		return fmt.Errorf("%s: %w", strings.Join(args[:min(len(args), 3)], " "), err)
	}
	return nil
}

// remove force-removes containers with a short context of its own (the pull's is cancelled).
func (d *Driver) remove(names ...string) error {
	ctx, cancel := context.WithTimeout(context.Background(), removeTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, "docker", append([]string{"rm", "-f"}, names...)...)
	cmd.Stdout, cmd.Stderr = io.Discard, d.Err
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("docker rm: %w", err)
	}
	return nil
}

// RemoveOrphans removes the docker runs of site that outlived a killed pull (label LabelRun).
// pull calls it under the site lock, so none of them belongs to a running pull.
func (d *Driver) RemoveOrphans(site string) error {
	out, err := d.output("ps", "-aq", "--filter", "label="+LabelRun+"="+site)
	if err != nil {
		return err
	}
	ids := strings.Fields(out)
	if len(ids) == 0 {
		return nil
	}
	fmt.Fprintf(d.Out, "  %d verwaiste Hilfscontainer eines abgebrochenen Pulls entfernt\n", len(ids))
	return d.remove(ids...)
}

func (r *runner) Run(args ...string) error { return r.exec(nil, r.d.Out, args) }

func (r *runner) Output(args ...string) (string, error) {
	var out strings.Builder
	err := r.exec(nil, &out, args)
	return out.String(), err
}

func (r *runner) RunStdin(stdin io.Reader, args ...string) error { return r.exec(stdin, r.d.Out, args) }
