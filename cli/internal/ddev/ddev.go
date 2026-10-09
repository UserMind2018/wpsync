// Package ddev drives the local DDEV project of a site.
package ddev

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/safefs"
)

// Runner executes ddev commands in a site directory.
type Runner = localenv.Runner

// Exec runs the real ddev binary.
type Exec struct {
	Dir    string
	Stdout io.Writer
	Stderr io.Writer
}

func (e *Exec) cmd(args ...string) *exec.Cmd {
	c := exec.Command("ddev", args...)
	c.Dir = e.Dir
	c.Stdout = e.Stdout
	c.Stderr = e.Stderr
	return c
}

// Run runs ddev with output to Stdout/Stderr.
func (e *Exec) Run(args ...string) error {
	if err := e.cmd(args...).Run(); err != nil {
		return fmt.Errorf("ddev %s: %w", strings.Join(args, " "), err)
	}
	return nil
}

// Output runs ddev and returns stdout.
func (e *Exec) Output(args ...string) (string, error) {
	c := e.cmd(args...)
	c.Stdout = nil
	out, err := c.Output()
	if err != nil {
		return "", fmt.Errorf("ddev %s: %w", strings.Join(args, " "), err)
	}
	return string(out), nil
}

// RunStdin runs ddev with stdin, e.g. `ddev mysql` for imports. `ddev mysql` passes all
// arguments to the client unchanged; the caller is responsible for its options (see pull.importArgs).
func (e *Exec) RunStdin(stdin io.Reader, args ...string) error {
	c := e.cmd(args...)
	c.Stdin = stdin
	if err := c.Run(); err != nil {
		return fmt.Errorf("ddev %s: %w", strings.Join(args, " "), err)
	}
	return nil
}

// Stream runs ddev with stdin and writes its stdout to stdout, e.g. `ddev wp eval-file -`.
func (e *Exec) Stream(stdin io.Reader, stdout io.Writer, args ...string) error {
	c := e.cmd(args...)
	c.Stdin, c.Stdout = stdin, stdout
	if err := c.Run(); err != nil {
		return fmt.Errorf("ddev %s: %w", strings.Join(args, " "), err)
	}
	return nil
}

// Exists reports whether siteDir already has a DDEV project.
func Exists(siteDir string) bool {
	_, err := os.Stat(filepath.Join(siteDir, ".ddev", "config.yaml"))
	return err == nil
}

// Start is the first ddev call of a pull. It creates the project on the first pull; otherwise it
// checks .ddev, brings wpsync's own compose files up to date and starts DDEV (restart if one of
// them changed). r must be p.Runner(…), so every call is checked. fresh: the project was created.
func Start(r Runner, p *Project, env agentapi.Env, mailguardSource string, out io.Writer) (fresh bool, err error) {
	// Checked here as well as after /delta: Start is exported, and setup hands the prefix to `wp`.
	if !agentapi.ValidTablePrefix(env.TablePrefix) {
		return false, fmt.Errorf("%w: table_prefix %s", agentapi.ErrInvalidEnv, agentapi.Printable(env.TablePrefix))
	}
	if _, err := PHPMajorMinor(env.PHPVersion); env.PHPVersion != "" && err != nil {
		return false, err
	}
	if !Exists(p.Dir) {
		return true, setup(r, p, env, mailguardSource)
	}
	if err := p.Check("start"); err != nil {
		return false, err
	}
	changed, err := p.EnsureOwnFiles(mailguardSource)
	if err != nil {
		return false, err
	}
	if changed {
		fmt.Fprintln(out, "  DDEV-Härtung bzw. Mailguard-Mount aktualisiert – DDEV startet neu")
		return false, r.Run("restart")
	}
	return false, r.Run("start", "-y")
}

// setup creates the DDEV project on the first pull. .ddev must not hold protected paths yet.
func setup(r Runner, p *Project, env agentapi.Env, mailguardSource string) error {
	siteDir := p.Dir
	p.StartFresh()
	if err := p.Check("config"); err != nil {
		var dev *DeviationError
		if errors.As(err, &dev) {
			dev.Fresh = true
		}
		return err
	}
	if err := os.MkdirAll(filepath.Join(siteDir, "public"), 0o755); err != nil {
		return err
	}
	if err := r.Run("config",
		"--project-name="+p.Name,
		"--project-type=wordpress",
		"--docroot=public",
		"--php-version="+MajorMinor(env.PHPVersion),
		"--database="+DatabaseSpec(env.DBServer),
		// OrbStack has fast bind mounts; Mutagen would sync the mailguard mount back as a copy (Spike B5).
		"--performance-mode=none",
	); err != nil {
		return err
	}
	if _, err := p.EnsureOwnFiles(mailguardSource); err != nil {
		return err
	}
	if err := r.Run("start", "-y"); err != nil {
		return err
	}
	if err := r.Run("wp", "core", "download", "--version="+env.WPVersion, "--skip-content", "--force"); err != nil {
		return err
	}
	if err := ClaimWPConfig(siteDir); err != nil {
		return err
	}
	if env.TablePrefix != "wp_" {
		return r.Run("wp", "config", "set", "table_prefix", env.TablePrefix, "--type=variable")
	}
	return nil
}

const ddevMarker = "#ddev-generated"

// ClaimWPConfig removes DDEV's marker so DDEV stops regenerating wp-config.php and
// dropping prefix and constants (Spike B15, AC-20). wp-config-ddev.php stays DDEV-managed.
func ClaimWPConfig(siteDir string) error {
	// public/ is the docroot in the DDEV mount: read and write without following a symlink (N-d).
	root, err := safefs.OpenDir(siteDir, "public")
	if err != nil {
		return fmt.Errorf("read wp-config.php: %w", err)
	}
	defer root.Close()
	data, err := safefs.ReadFile(root, "wp-config.php")
	if err != nil {
		return fmt.Errorf("read wp-config.php: %w", err)
	}
	claimed := strings.Replace(string(data), ddevMarker, "wpsync-managed (DDEV marker removed so DDEV no longer overwrites this file)", 1)
	return safefs.WriteFile(root, "wp-config.php", strings.NewReader(claimed), int64(len(claimed)), time.Time{}, 0o644)
}

// DatabaseSpec maps the source server version to a DDEV database spec (Spike B17, AC-19).
func DatabaseSpec(server string) string {
	maria := strings.Contains(strings.ToLower(server), "mariadb")
	if maria {
		// MariaDB ≥ 10 announces itself as "5.5.5-10.11.19-MariaDB" so that old replication clients
		// accept it; PHP before 8.0.16 passes that on unchanged.
		server = strings.TrimPrefix(server, "5.5.5-")
	}
	version := MajorMinor(strings.SplitN(server, "-", 2)[0])
	switch {
	case maria:
		return "mariadb:" + version
	case version == "5.7" || version == "8.0" || version == "8.4":
		return "mysql:" + version
	default:
		return "mysql:8.0"
	}
}

// MajorMinor turns "8.2.29" into "8.2".
func MajorMinor(version string) string {
	parts := strings.SplitN(version, ".", 3)
	if len(parts) < 2 {
		return version
	}
	return parts[0] + "." + parts[1]
}

// PHPMajorMinor returns <major>.<minor> of the source's PHP version; anything else than two
// numbers is ErrInvalidEnv (agentapi.PHPMajorMinor).
func PHPMajorMinor(version string) (string, error) {
	mm, ok := agentapi.PHPMajorMinor(version)
	if !ok {
		return "", fmt.Errorf("%w: php_version %s", agentapi.ErrInvalidEnv, agentapi.Printable(version))
	}
	return mm, nil
}

// LocalURL returns the project's HTTP URL.
func LocalURL(r Runner) (string, error) {
	out, err := r.Output("describe", "-j")
	if err != nil {
		return "", err
	}
	return parseDescribe([]byte(out))
}

func parseDescribe(data []byte) (string, error) {
	var d struct {
		Raw struct {
			HTTPURL string `json:"httpurl"`
		} `json:"raw"`
	}
	if err := json.Unmarshal(data, &d); err != nil {
		return "", fmt.Errorf("parse ddev describe: %w", err)
	}
	if d.Raw.HTTPURL == "" {
		return "", errors.New("ddev describe: no httpurl")
	}
	return d.Raw.HTTPURL, nil
}
