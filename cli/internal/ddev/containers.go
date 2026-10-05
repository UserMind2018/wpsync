package ddev

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Docker runs the docker CLI. wpsync uses it where ddev would run project hooks.
type Docker interface {
	Output(args ...string) (string, error)
}

// DockerExec runs the real docker binary.
type DockerExec struct{}

// Output runs docker and returns stdout; stderr goes into the error.
func (DockerExec) Output(args ...string) (string, error) {
	out, err := exec.Command("docker", args...).Output()
	if err != nil {
		var exit *exec.ExitError
		if errors.As(err, &exit) && len(exit.Stderr) > 0 {
			return "", fmt.Errorf("docker %s: %w: %s", strings.Join(args, " "), err, strings.TrimSpace(string(exit.Stderr)))
		}
		return "", fmt.Errorf("docker %s: %w", strings.Join(args, " "), err)
	}
	return string(out), nil
}

// Mount is one mount of a container as docker inspect reports it.
type Mount struct {
	Source      string `json:"Source"`
	Destination string `json:"Destination"`
	RW          bool   `json:"RW"`
}

// Container of a DDEV project.
type Container struct {
	ID      string
	Service string
	AppRoot string // label com.ddev.approot; empty for add-on services without DDEV labels
	Running bool
	Mounts  []Mount
}

// Containers lists all containers of the project, running or not: those DDEV labels with the
// site name and those of the compose project ddev-<name>, which includes add-on services that
// carry no DDEV label.
func Containers(d Docker, name string) ([]Container, error) {
	var ids []string
	seen := map[string]bool{}
	for _, filter := range []string{"label=com.ddev.site-name=" + name, "label=com.docker.compose.project=ddev-" + strings.ToLower(name)} {
		out, err := d.Output("ps", "-aq", "--filter", filter)
		if err != nil {
			return nil, err
		}
		for _, id := range strings.Fields(out) {
			if !seen[id] {
				seen[id] = true
				ids = append(ids, id)
			}
		}
	}
	if len(ids) == 0 {
		return nil, nil
	}
	out, err := d.Output(append([]string{"inspect"}, ids...)...)
	if err != nil {
		return nil, err
	}
	return parseInspect([]byte(out))
}

func parseInspect(data []byte) ([]Container, error) {
	var raw []struct {
		ID    string `json:"Id"`
		State struct {
			Running bool `json:"Running"`
		} `json:"State"`
		Config struct {
			Labels map[string]string `json:"Labels"`
		} `json:"Config"`
		Mounts []Mount `json:"Mounts"`
	}
	if err := json.Unmarshal(data, &raw); err != nil {
		return nil, fmt.Errorf("parse docker inspect: %w", err)
	}
	out := make([]Container, 0, len(raw))
	for _, r := range raw {
		for i := range r.Mounts {
			r.Mounts[i].Source = hostPath(r.Mounts[i].Source)
		}
		out = append(out, Container{ID: r.ID, Service: r.Config.Labels["com.docker.compose.service"],
			AppRoot: hostPath(r.Config.Labels["com.ddev.approot"]), Running: r.State.Running, Mounts: r.Mounts})
	}
	return out, nil
}

// hostPath undoes Docker Desktop's /host_mnt prefix on bind sources, so they compare with the
// paths wpsync uses.
func hostPath(p string) string {
	if rest, ok := strings.CutPrefix(p, "/host_mnt/"); ok {
		return "/" + rest
	}
	return p
}

// Container paths of .ddev that HardeningCompose makes read-only.
const (
	webDDEVPath   = "/var/www/html/.ddev"
	ddevConfigDir = "/mnt/ddev_config"
)

// Hardened reports whether the container cannot write to siteDir/.ddev: every writable mount that
// contains .ddev is covered by a read-only mount of .ddev at the matching place, and nothing below
// .ddev is writable except db_snapshots. The checks by target path also hold if docker reports
// host paths differently than wpsync sees them.
func Hardened(c Container, siteDir string) bool {
	ddevDir := filepath.Join(siteDir, ".ddev")
	snapshots := filepath.Join(ddevDir, snapshotsDir)
	if !realDirOrMissing(snapshots) {
		// DDEV mounts db_snapshots writable; through a symlink that reaches .ddev or the Mac.
		return false
	}
	byDest := map[string]Mount{}
	for _, m := range c.Mounts {
		byDest[m.Destination] = m
	}
	if m, ok := byDest[ddevConfigDir]; ok && m.RW {
		return false
	}
	if c.Service == "web" {
		if m, ok := byDest[webDDEVPath]; !ok || m.RW {
			return false
		}
	}
	for _, m := range c.Mounts {
		if !m.RW {
			continue
		}
		switch {
		case sameLeaf(m.Source, snapshots):
		case within(ddevDir, m.Source):
			return false
		case within(m.Source, ddevDir):
			rel, err := filepath.Rel(absClean(m.Source), absClean(ddevDir))
			if err != nil {
				return false
			}
			cover, ok := byDest[filepath.ToSlash(filepath.Join(m.Destination, rel))]
			if !ok || cover.RW {
				return false
			}
		}
	}
	return true
}

// sameLeaf compares two paths with symlinks resolved in their parents but not in the last
// element: db_snapshots must be the directory itself, not whatever a symlink there points to.
func sameLeaf(a, b string) bool {
	leaf := func(p string) string {
		p = filepath.Clean(p)
		return filepath.Join(absClean(filepath.Dir(p)), filepath.Base(p))
	}
	return leaf(a) == leaf(b)
}

func samePath(a, b string) bool { return absClean(a) == absClean(b) }

// realDirOrMissing: p is a directory and not a symlink, or does not exist yet (docker then
// creates a plain directory).
func realDirOrMissing(p string) bool {
	info, err := os.Lstat(p)
	if errors.Is(err, os.ErrNotExist) {
		return true
	}
	return err == nil && info.IsDir()
}

// ErrForeignProject: containers with this project name belong to a DDEV project elsewhere.
var ErrForeignProject = errors.New("DDEV-Container mit diesem Projektnamen gehören zu einem anderen Ordner – wpsync fasst sie nicht an")

func checkOwner(cs []Container, siteDir string) error {
	for _, c := range cs {
		if c.AppRoot != "" && !samePath(c.AppRoot, siteDir) {
			return fmt.Errorf("%w (%s)", ErrForeignProject, agentapi.Printable(c.AppRoot))
		}
	}
	return nil
}

// ErrNotHardened: a project container can still write to .ddev after the start.
var ErrNotHardened = errors.New("ein DDEV-Container kann .ddev weiterhin beschreiben")

// StopUnhardened stops and removes the project's containers through docker if any of them can
// write to .ddev. Plain docker, not ddev: `ddev stop` would run the project's pre-stop hooks,
// which such a container may have written. Data in the database volume stays.
func StopUnhardened(d Docker, name, siteDir string) (bool, error) {
	cs, err := Containers(d, name)
	if err != nil {
		return false, err
	}
	unsafe := false
	for _, c := range cs {
		if !Hardened(c, siteDir) {
			unsafe = true
		}
	}
	if !unsafe {
		return false, nil
	}
	if err := checkOwner(cs, siteDir); err != nil {
		return false, err
	}
	return true, removeContainers(d, cs)
}

// StopContainers stops the project's running containers through docker, without hooks.
func StopContainers(d Docker, name string) (int, error) {
	cs, err := Containers(d, name)
	if err != nil {
		return 0, err
	}
	var running []string
	for _, c := range cs {
		if c.Running {
			running = append(running, c.ID)
		}
	}
	if len(running) == 0 {
		return 0, nil
	}
	if _, err := d.Output(append([]string{"stop", "-t", "30"}, running...)...); err != nil {
		return 0, err
	}
	return len(running), nil
}

func removeContainers(d Docker, cs []Container) error {
	var running, all []string
	for _, c := range cs {
		all = append(all, c.ID)
		if c.Running {
			running = append(running, c.ID)
		}
	}
	if len(running) > 0 {
		if _, err := d.Output(append([]string{"stop", "-t", "30"}, running...)...); err != nil {
			return err
		}
	}
	_, err := d.Output(append([]string{"rm"}, all...)...)
	return err
}

// VerifyHardened checks every project container after a start, running or not: a stopped one
// with a writable .ddev would come back with the next start. If one fails, all project
// containers are stopped through docker and the pull ends.
func VerifyHardened(d Docker, name, siteDir string) error {
	cs, err := Containers(d, name)
	if err != nil {
		return err
	}
	var bad []string
	running := 0
	for _, c := range cs {
		if c.Running {
			running++
		}
		if !Hardened(c, siteDir) {
			bad = append(bad, c.Service)
		}
	}
	if len(bad) == 0 {
		if running == 0 {
			return fmt.Errorf("%w: keine laufenden Container gefunden", ErrNotHardened)
		}
		return nil
	}
	if err := checkOwner(cs, siteDir); err != nil {
		return err
	}
	if err := removeContainers(d, cs); err != nil {
		return fmt.Errorf("%w (%s) – stoppen fehlgeschlagen: %v", ErrNotHardened, strings.Join(bad, ", "), err)
	}
	return fmt.Errorf("%w (%s) – Container gestoppt. Meist mountet eine eigene docker-compose.*.yaml .ddev erneut beschreibbar", ErrNotHardened, strings.Join(bad, ", "))
}
