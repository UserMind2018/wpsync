// Package localenv lists and stops the local DDEV projects wpsync created under the sites root.
// Other DDEV projects on the Mac are never touched.
package localenv

import (
	"encoding/json"
	"errors"
	"fmt"
	"path/filepath"
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/sites"
)

// DDEV status values, plus StatusMissing for a paired site that was never pulled.
const (
	StatusRunning = "running"
	StatusPaused  = "paused"
	StatusStopped = "stopped"
	StatusMissing = "missing"
)

// DDEV runs global ddev commands (no project directory needed).
type DDEV interface {
	Output(args ...string) (string, error)
	Run(args ...string) error
}

// Env is one row of `wpsync list`.
type Env struct {
	Name     string
	Status   string
	LocalURL string
	LiveURL  string // empty if the site is no longer paired
}

type ddevProject struct {
	Name     string `json:"name"`
	Status   string `json:"status"`
	AppRoot  string `json:"approot"`
	HTTPSURL string `json:"httpsurl"`
}

// List merges the DDEV projects below sitesRoot with the paired sites, sorted by name.
func List(d DDEV, sitesRoot string, paired []sites.Site) ([]Env, error) {
	// ddev list runs the pre-describe hooks of every running project – without --skip-hooks a
	// hook a site wrote into its .ddev would run on the Mac (no project check covers this call).
	out, err := d.Output("list", "-j", "--skip-hooks")
	if err != nil {
		return nil, fmt.Errorf("list ddev projects: %w", err)
	}
	var resp struct {
		Raw []ddevProject `json:"raw"`
	}
	if err := json.Unmarshal([]byte(out), &resp); err != nil {
		return nil, fmt.Errorf("parse ddev list: %w", err)
	}

	byName := map[string]*Env{}
	for _, p := range resp.Raw {
		if !inside(sitesRoot, p.AppRoot) {
			continue
		}
		byName[p.Name] = &Env{Name: p.Name, Status: p.Status, LocalURL: p.HTTPSURL}
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
	return envs, nil
}

func inside(root, path string) bool {
	rel, err := filepath.Rel(filepath.Clean(root), filepath.Clean(path))
	return err == nil && rel != "." && !strings.HasPrefix(rel, "..")
}

// Guard checks a site's .ddev before ddev may stop it. `ddev stop` runs the project's pre-stop
// hooks on the Mac; a site that fails the check is halted through docker instead (Halt).
type Guard interface {
	Check(name string) error
	Halt(name string) error
}

// Stop stops the named environments (running or paused), or with all=true every running one.
// Unknown names fail before anything is stopped. With a guard, sites whose .ddev is not trusted
// are halted without ddev; a deviating one is reported as an error after the others were stopped.
// Returns how many were stopped.
func Stop(d DDEV, envs []Env, names []string, all bool, g Guard) (int, error) {
	known := map[string]Env{}
	for _, e := range envs {
		known[e.Name] = e
	}
	var targets []string
	if all {
		for _, e := range envs {
			if e.Status == StatusRunning {
				targets = append(targets, e.Name)
			}
		}
	} else {
		for _, n := range names {
			e, ok := known[n]
			if !ok || e.Status == StatusMissing {
				return 0, fmt.Errorf("keine lokale wpsync-Umgebung: %s (wpsync list zeigt alle)", n)
			}
			if active(e.Status) {
				targets = append(targets, n)
			}
		}
	}
	if len(targets) == 0 {
		return 0, nil
	}
	checked, halted := targets, 0
	var failed []string
	if g != nil {
		checked = nil
		for _, n := range targets {
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
			if !errors.Is(err, ddev.ErrNotAdopted) {
				failed = append(failed, fmt.Sprintf("%v\n%s wurde ohne ddev angehalten (docker stop).", err, n))
			}
		}
	}
	if len(checked) > 0 {
		if err := d.Run(append([]string{"stop"}, checked...)...); err != nil {
			return halted, err
		}
	}
	stopped := halted + len(checked)
	if len(failed) > 0 {
		return stopped, errors.New(strings.Join(failed, "\n\n"))
	}
	return stopped, nil
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
