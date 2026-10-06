// Package localenv is the local runtime of pulled sites: DDEV on the Mac, a plain WordPress
// container on the server (Spec Server-Modus §3). It also lists and stops wpsync's environments;
// other projects on the machine are never touched.
package localenv

import (
	"errors"
	"fmt"
	"io"
	"sort"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/sites"
)

// Status values, plus StatusMissing for a paired site that was never pulled.
const (
	StatusRunning = "running"
	StatusPaused  = "paused"
	StatusStopped = "stopped"
	StatusMissing = "missing"
)

// ErrAborted: the user declined to continue (shared by pull and the DDEV takeover question).
var ErrAborted = errors.New("abgebrochen")

// Runner executes commands inside a site's environment: "wp …" and "mysql …" (SQL on stdin).
type Runner interface {
	Run(args ...string) error
	Output(args ...string) (string, error)
	RunStdin(stdin io.Reader, args ...string) error
}

// Driver is the local runtime of a site. The post-setup stays in package pull and runs through
// Runner, so it exists only once.
type Driver interface {
	// Exists reports whether wpsync already set up the site's environment.
	Exists(site string) (bool, error)
	// Configure passes what depends on the source (PHP version, table prefix); on every pull.
	Configure(env agentapi.Env) error
	// Setup prepares the environment on the first pull.
	Setup(site string) error
	// Start makes an existing environment ready for the pull.
	Start(site string) error
	// Runner runs wp-cli and SQL in the site.
	Runner(site string) Runner
	// LocalURL is the URL the site answers on locally (target of the search-replace).
	LocalURL(site string) (string, error)
	// Stop stops the given environments (Env.Ref from List) and returns how many were stopped,
	// also when it fails for some of them.
	Stop(refs ...string) (int, error)
	// List returns the environments the driver manages.
	List() ([]Env, error)
}

// UploadsProxy is implemented by drivers that can fetch missing uploads from the source (DDEV).
type UploadsProxy interface {
	UploadsProxy(site, sourceURL, userAgent string, enabled bool) error
}

// Error marks a failure of the local environment (exit code local_env).
type Error struct {
	Op  string
	Err error
}

func (e *Error) Error() string { return e.Op + ": " + e.Err.Error() }
func (e *Error) Unwrap() error { return e.Err }

// Wrap marks err as a local environment failure; nil stays nil.
func Wrap(op string, err error) error {
	if err == nil {
		return nil
	}
	return &Error{Op: op, Err: err}
}

// Env is one row of `wpsync list`.
type Env struct {
	Name     string
	Status   string
	LocalURL string
	LiveURL  string // empty if the site is no longer paired
	Ref      string // handle for Driver.Stop: DDEV project or container name
}

// Merge adds the paired sites to the environments a driver found, sorted by name.
// A paired site without environment gets StatusMissing.
func Merge(found []Env, paired []sites.Site) []Env {
	byName := map[string]*Env{}
	for i := range found {
		e := found[i]
		byName[e.Name] = &e
	}
	for _, s := range paired {
		if e, ok := byName[s.Name]; ok {
			e.LiveURL = s.URL
			continue
		}
		byName[s.Name] = &Env{Name: s.Name, Status: StatusMissing, LiveURL: s.URL}
	}
	envs := make([]Env, 0, len(byName))
	for _, e := range byName {
		envs = append(envs, *e)
	}
	sort.Slice(envs, func(i, j int) bool { return envs[i].Name < envs[j].Name })
	return envs
}

// Stop stops the named environments (running or paused), or with all=true every running one.
// Unknown names fail before anything is stopped. Returns how many were stopped; the driver
// decides how (DDEV checks every site's .ddev first).
func Stop(d Driver, envs []Env, names []string, all bool) (int, error) {
	known := map[string]Env{}
	for _, e := range envs {
		known[e.Name] = e
	}
	var targets []string
	if all {
		for _, e := range envs {
			if e.Status == StatusRunning {
				targets = append(targets, ref(e))
			}
		}
	} else {
		for _, n := range names {
			e, ok := known[n]
			if !ok || e.Status == StatusMissing {
				return 0, fmt.Errorf("keine lokale wpsync-Umgebung: %s (wpsync list zeigt alle)", n)
			}
			if active(e.Status) {
				targets = append(targets, ref(e))
			}
		}
	}
	if len(targets) == 0 {
		return 0, nil
	}
	return d.Stop(targets...)
}

func ref(e Env) string {
	if e.Ref != "" {
		return e.Ref
	}
	return e.Name
}

func active(status string) bool { return status != StatusStopped && status != StatusMissing }

// Label is the German status shown in `wpsync list`.
func Label(status string) string {
	switch status {
	case StatusRunning:
		return "läuft"
	case StatusPaused:
		return "pausiert"
	case StatusStopped:
		return "gestoppt"
	case StatusMissing:
		return "nicht angelegt"
	}
	return status
}
