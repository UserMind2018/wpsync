package ddev

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
)

// fakeDocker answers ps/inspect for the containers of one project and records every call.
type fakeDocker struct {
	containers []Container
	calls      [][]string
}

func (f *fakeDocker) Output(args ...string) (string, error) {
	f.calls = append(f.calls, args)
	switch args[0] {
	case "ps":
		var ids []string
		for _, c := range f.containers {
			ids = append(ids, c.ID)
		}
		return strings.Join(ids, "\n"), nil
	case "inspect":
		type state struct {
			Running bool `json:"Running"`
		}
		type config struct {
			Labels map[string]string `json:"Labels"`
		}
		type entry struct {
			ID     string  `json:"Id"`
			State  state   `json:"State"`
			Config config  `json:"Config"`
			Mounts []Mount `json:"Mounts"`
		}
		var out []entry
		for _, c := range f.containers {
			out = append(out, entry{ID: c.ID, State: state{c.Running}, Config: config{map[string]string{"com.docker.compose.service": c.Service}}, Mounts: c.Mounts})
		}
		data, err := json.Marshal(out)
		return string(data), err
	case "stop":
		for i := range f.containers {
			f.containers[i].Running = false
		}
	case "rm":
		f.containers = nil
	}
	return "", nil
}

func (f *fakeDocker) verbs() []string {
	var v []string
	for _, c := range f.calls {
		v = append(v, c[0])
	}
	return v
}

func hardenedWeb(site string) []Mount {
	return []Mount{
		{Source: site, Destination: "/var/www/html", RW: true},
		{Source: filepath.Join(site, ".ddev"), Destination: "/var/www/html/.ddev", RW: false},
		{Source: filepath.Join(site, ".ddev"), Destination: "/mnt/ddev_config", RW: false},
		{Source: "/cfg/00-local-mailguard.php", Destination: "/var/www/html/public/wp-content/mu-plugins/00-local-mailguard.php", RW: false},
		{Source: "/var/lib/docker/volumes/ddev-global-cache/_data", Destination: "/mnt/ddev-global-cache", RW: true},
	}
}

func hardenedDB(site string) []Mount {
	return []Mount{
		{Source: filepath.Join(site, ".ddev"), Destination: "/mnt/ddev_config", RW: false},
		{Source: filepath.Join(site, ".ddev", "db_snapshots"), Destination: "/mnt/ddev_config/db_snapshots", RW: true},
		{Source: filepath.Join(site, ".ddev", "db_snapshots"), Destination: "/mnt/snapshots", RW: true},
		{Source: "/var/lib/docker/volumes/kunde-mariadb/_data", Destination: "/var/lib/mysql", RW: true},
	}
}

func hardenedDocker(site string) *fakeDocker {
	return &fakeDocker{containers: []Container{
		{ID: "web1", Service: "web", Running: true, Mounts: hardenedWeb(site)},
		{ID: "db1", Service: "db", Running: true, Mounts: hardenedDB(site)},
	}}
}

// fakeDDEV stands in for the ddev binary: config creates config.yaml, start regenerates DDEV's
// compose files, wp core download writes wp-config.php. hook runs inside a call, like site code.
type fakeDDEV struct {
	dir   string
	calls [][]string
	n     int
	fail  map[string]error
	hook  func(args []string)
}

func (f *fakeDDEV) do(args []string) error {
	f.calls = append(f.calls, args)
	f.n++
	switch args[0] {
	case "config":
		os.MkdirAll(filepath.Join(f.dir, ".ddev"), 0o755)
		os.WriteFile(filepath.Join(f.dir, ".ddev", "config.yaml"), []byte("name: kunde\n"), 0o644)
	case "start", "restart":
		os.WriteFile(filepath.Join(f.dir, ".ddev", ".ddev-docker-compose-full.yaml"), []byte(fmt.Sprintf("# run %d\n", f.n)), 0o644)
		os.WriteFile(filepath.Join(f.dir, ".ddev", ".ddev-docker-compose-base.yaml"), []byte(fmt.Sprintf("# run %d\n", f.n)), 0o644)
	case "wp":
		if len(args) > 2 && args[1] == "core" {
			os.MkdirAll(filepath.Join(f.dir, "public"), 0o755)
			os.WriteFile(filepath.Join(f.dir, "public", "wp-config.php"), []byte("<?php // #ddev-generated\n"), 0o644)
		}
	}
	if f.hook != nil {
		f.hook(args)
	}
	return f.fail[args[0]]
}

func (f *fakeDDEV) Run(args ...string) error { return f.do(args) }
func (f *fakeDDEV) Output(args ...string) (string, error) {
	if err := f.do(args); err != nil {
		return "", err
	}
	return `{"raw":{"httpurl":"http://kunde.ddev.site"}}`, nil
}
func (f *fakeDDEV) RunStdin(stdin io.Reader, args ...string) error {
	io.Copy(io.Discard, stdin)
	return f.do(args)
}

func mailguardFile(t *testing.T) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), "00-local-mailguard.php")
	if err := os.WriteFile(p, []byte("<?php"), 0o644); err != nil {
		t.Fatal(err)
	}
	return p
}

var testEnv = agentapi.Env{PHPVersion: "8.2.29", DBServer: "10.11.14-MariaDB", WPVersion: "6.8.3", TablePrefix: "wp_"}

// newSite runs a first pull against the fakes and returns the trusted project.
func newSite(t *testing.T) (*Project, *fakeDDEV, *fakeDocker, Store, string) {
	t.Helper()
	site := t.TempDir()
	store := Store{Dir: filepath.Join(t.TempDir(), "ddev-state")}
	docker := hardenedDocker(site)
	p, err := OpenProject("kunde", site, store, docker)
	if err != nil {
		t.Fatal(err)
	}
	inner := &fakeDDEV{dir: site}
	mg := mailguardFile(t)
	fresh, err := Start(p.Runner(inner), p, testEnv, mg, io.Discard)
	if err != nil || !fresh {
		t.Fatalf("Start = %v, %v", fresh, err)
	}
	return p, inner, docker, store, mg
}

func argsOf(calls [][]string) []string {
	var out []string
	for _, c := range calls {
		out = append(out, strings.Join(c, " "))
	}
	return out
}

// AC-6: Guarded prüft vor jedem der drei Wege und ruft nach einem Fehler des inneren Runners
// nicht After auf.
func TestGuardedChecksBeforeEveryCall(t *testing.T) {
	inner := &fakeDDEV{dir: t.TempDir()}
	block := errors.New("blocked")
	var before, after int
	allow := true
	g := &Guarded{Inner: inner,
		Before: func([]string) error {
			before++
			if !allow {
				return block
			}
			return nil
		},
		After: func([]string) error { after++; return nil },
	}
	g.Run("describe")
	g.Output("describe", "-j")
	g.RunStdin(strings.NewReader("x"), "mysql")
	if inner.n != 3 || before != 3 || after != 3 {
		t.Fatalf("inner=%d before=%d after=%d", inner.n, before, after)
	}
	allow = false
	if err := g.Run("stop"); !errors.Is(err, block) {
		t.Fatalf("Run = %v", err)
	}
	if _, err := g.Output("wp", "eval", "x"); !errors.Is(err, block) {
		t.Fatalf("Output = %v", err)
	}
	if err := g.RunStdin(strings.NewReader("x"), "mysql"); !errors.Is(err, block) {
		t.Fatalf("RunStdin = %v", err)
	}
	if inner.n != 3 || after != 3 {
		t.Fatalf("blocked calls ran: inner=%d after=%d", inner.n, after)
	}
	allow = true
	inner.fail = map[string]error{"wp": errors.New("exit 1")}
	if _, err := g.Output("wp", "eval", "x"); err == nil {
		t.Fatal("inner error lost")
	}
	if after != 3 {
		t.Fatal("After ran after a failed call")
	}
}

// AC-6: Manipulation nach dem n-ten Aufruf → genau n Aufrufe, für Run, Output und RunStdin.
func TestProjectRunnerStopsAfterManipulation(t *testing.T) {
	blocked := map[string]func(r Runner) error{
		"Run stop":        func(r Runner) error { return r.Run("stop") },
		"Output wp":       func(r Runner) error { _, err := r.Output("wp", "eval", "x"); return err },
		"Output describe": func(r Runner) error { _, err := LocalURL(r); return err },
		"RunStdin mysql":  func(r Runner) error { return r.RunStdin(strings.NewReader("SELECT 1;"), "mysql") },
		"Run restart":     func(r Runner) error { return r.Run("restart") },
	}
	for name, call := range blocked {
		t.Run(name, func(t *testing.T) {
			p, inner, _, _, _ := newSite(t)
			r := p.Runner(inner)
			if _, err := LocalURL(r); err != nil {
				t.Fatal(err)
			}
			if err := r.RunStdin(strings.NewReader("x"), "mysql"); err != nil {
				t.Fatal(err)
			}
			if err := r.Run("wp", "option", "get", "home"); err != nil {
				t.Fatal(err)
			}
			n := inner.n
			writeDDEV(t, p.Dir, "config.audit.yaml", "hooks:\n  pre-stop:\n    - exec-host: touch marker\n")
			var dev *DeviationError
			if err := call(r); !errors.As(err, &dev) {
				t.Fatalf("err = %v, want DeviationError", err)
			}
			if inner.n != n {
				t.Fatalf("%d ddev calls after the manipulation: %v", inner.n-n, argsOf(inner.calls[n:]))
			}
		})
	}
}

// AC-6 + A5: was DDEV während eines erfolgreichen start neu erzeugt, wird übernommen; ein
// Aufruf ohne Soll-Zustand läuft nicht.
func TestProjectRunnerWithoutTrustedState(t *testing.T) {
	site := baseSite(t)
	inner := &fakeDDEV{dir: site}
	p, err := OpenProject("kunde", site, Store{Dir: t.TempDir()}, hardenedDocker(site))
	if err != nil {
		t.Fatal(err)
	}
	if err := p.Runner(inner).Run("stop"); !errors.Is(err, ErrNotAdopted) {
		t.Fatalf("err = %v", err)
	}
	if inner.n != 0 {
		t.Fatalf("ddev called: %v", inner.calls)
	}
}

// AC-11: .ddev ohne config.yaml, aber mit geschütztem Inhalt → Abbruch vor ddev config.
func TestStartFreshRefusesPrefilledDDEV(t *testing.T) {
	for _, rel := range []string{"commands/host/x", "config.audit.yaml", "docker-compose.x.yaml", ".env"} {
		t.Run(rel, func(t *testing.T) {
			site := t.TempDir()
			writeDDEV(t, site, rel, "x")
			store := Store{Dir: t.TempDir()}
			// An older state of a site with the same name does not count.
			old := trustedProject(t, baseSite(t))
			store.Save("kunde", old.trusted)
			p, err := OpenProject("kunde", site, store, hardenedDocker(site))
			if err != nil {
				t.Fatal(err)
			}
			inner := &fakeDDEV{dir: site}
			_, err = Start(p.Runner(inner), p, testEnv, mailguardFile(t), io.Discard)
			var dev *DeviationError
			if !errors.As(err, &dev) || !dev.Fresh || dev.Changes[0].Path != rel {
				t.Fatalf("err = %#v", err)
			}
			if inner.n != 0 {
				t.Fatalf("ddev called: %v", argsOf(inner.calls))
			}
		})
	}
	// Only unprotected content: the first pull goes ahead.
	site := t.TempDir()
	writeDDEV(t, site, "nginx/x.conf", "x")
	p, _ := OpenProject("kunde", site, Store{Dir: t.TempDir()}, hardenedDocker(site))
	if _, err := Start(p.Runner(&fakeDDEV{dir: site}), p, testEnv, mailguardFile(t), io.Discard); err != nil {
		t.Fatal(err)
	}
}

// AC-4, AC-9: Erstlauf legt beide wpsync-Dateien an; zwei weitere Pulls ohne Eingriff laufen
// durch (DDEV erzeugt bei jedem start seine Compose-Dateien neu), ohne Restart.
func TestStartFirstPullAndRepeatedPulls(t *testing.T) {
	p, inner, docker, store, mg := newSite(t)
	if got := strings.Join(argsOf(inner.calls), " | "); !strings.HasPrefix(got, "config --project-name=kunde") || !strings.Contains(got, "| start -y | wp core download") {
		t.Fatalf("first pull calls = %s", got)
	}
	for name, want := range map[string]string{MailguardComposeFile: MailguardCompose(mg), HardeningComposeFile: HardeningCompose()} {
		data, err := os.ReadFile(filepath.Join(p.Dir, ".ddev", name))
		if err != nil || string(data) != want {
			t.Fatalf("%s = %q, %v", name, data, err)
		}
	}
	for i := 0; i < 2; i++ {
		p2, err := OpenProject("kunde", p.Dir, store, docker)
		if err != nil {
			t.Fatal(err)
		}
		inner.calls = nil
		var out bytes.Buffer
		r := p2.Runner(inner)
		if fresh, err := Start(r, p2, testEnv, mg, &out); err != nil || fresh {
			t.Fatalf("pull %d: Start = %v, %v", i+2, fresh, err)
		}
		if err := r.Run("restart"); err != nil {
			t.Fatalf("pull %d: restart = %v", i+2, err)
		}
		if _, err := r.Output("wp", "eval", "x"); err != nil {
			t.Fatalf("pull %d: wp = %v", i+2, err)
		}
		if !reflect.DeepEqual(argsOf(inner.calls), []string{"start -y", "restart", "wp eval x"}) || out.Len() != 0 {
			t.Fatalf("pull %d: calls = %v, out = %q", i+2, argsOf(inner.calls), out.String())
		}
	}
}

// AC-4: fehlt die Härtung (Site vor dem Update) oder hat sich der Mailguard-Pfad geändert,
// schreibt wpsync die Datei neu und startet DDEV mit Hinweis neu.
func TestStartRewritesOwnFilesAndRestarts(t *testing.T) {
	p, inner, docker, store, mg := newSite(t)

	// Site from before the update: no hardening file, trusted state without it.
	os.Remove(filepath.Join(p.Dir, ".ddev", HardeningComposeFile))
	if err := p.Accept(); err != nil {
		t.Fatal(err)
	}
	p2, _ := OpenProject("kunde", p.Dir, store, docker)
	inner.calls = nil
	var out bytes.Buffer
	if _, err := Start(p2.Runner(inner), p2, testEnv, mg, &out); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(argsOf(inner.calls), []string{"restart"}) || !strings.Contains(out.String(), "DDEV startet neu") {
		t.Fatalf("calls = %v, out = %q", argsOf(inner.calls), out.String())
	}
	if data, _ := os.ReadFile(filepath.Join(p.Dir, ".ddev", HardeningComposeFile)); string(data) != HardeningCompose() {
		t.Fatalf("hardening not written: %q", data)
	}

	// Mailguard moved (WPSYNC_MAILGUARD / config dir changed): rewrite, no alarm.
	moved := mailguardFile(t)
	p3, _ := OpenProject("kunde", p.Dir, store, docker)
	inner.calls, out = nil, bytes.Buffer{}
	if _, err := Start(p3.Runner(inner), p3, testEnv, moved, &out); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(argsOf(inner.calls), []string{"restart"}) {
		t.Fatalf("calls = %v", argsOf(inner.calls))
	}
	if data, _ := os.ReadFile(filepath.Join(p.Dir, ".ddev", MailguardComposeFile)); string(data) != MailguardCompose(moved) {
		t.Fatalf("mailguard compose = %q", data)
	}

	// Unchanged: plain start.
	p4, _ := OpenProject("kunde", p.Dir, store, docker)
	inner.calls, out = nil, bytes.Buffer{}
	if _, err := Start(p4.Runner(inner), p4, testEnv, moved, &out); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(argsOf(inner.calls), []string{"start -y"}) || out.Len() != 0 {
		t.Fatalf("calls = %v, out = %q", argsOf(inner.calls), out.String())
	}
}

// AC-7: eine wpsync-eigene Datei, die vom Soll abweicht, wird nicht still überschrieben.
func TestStartDoesNotRepairTamperedOwnFile(t *testing.T) {
	p, inner, docker, store, mg := newSite(t)
	writeDDEV(t, p.Dir, HardeningComposeFile, "services: {}\n")
	p2, _ := OpenProject("kunde", p.Dir, store, docker)
	inner.calls = nil
	_, err := Start(p2.Runner(inner), p2, testEnv, mg, io.Discard)
	var dev *DeviationError
	if !errors.As(err, &dev) || dev.Changes[0].Path != HardeningComposeFile {
		t.Fatalf("err = %v", err)
	}
	if len(inner.calls) != 0 {
		t.Fatalf("ddev called: %v", argsOf(inner.calls))
	}
	if data, _ := os.ReadFile(filepath.Join(p.Dir, ".ddev", HardeningComposeFile)); string(data) != "services: {}\n" {
		t.Fatal("tampered file was overwritten")
	}
}

// D4: greift die Härtung nach dem Start nicht, endet der Pull, die Container werden per docker
// angehalten und der Stand wird nicht übernommen.
func TestStartFailsIfContainersStayWritable(t *testing.T) {
	p, inner, _, store, mg := newSite(t)
	docker := &fakeDocker{containers: []Container{
		{ID: "web1", Service: "web", Running: true, Mounts: []Mount{{Source: p.Dir, Destination: "/var/www/html", RW: true}}},
		{ID: "db1", Service: "db", Running: true, Mounts: hardenedDB(p.Dir)},
	}}
	before, _, _ := store.Load("kunde")
	p2, _ := OpenProject("kunde", p.Dir, store, docker)
	_, err := Start(p2.Runner(inner), p2, testEnv, mg, io.Discard)
	if !errors.Is(err, ErrNotHardened) {
		t.Fatalf("err = %v", err)
	}
	if v := strings.Join(docker.verbs(), " "); v != "ps ps inspect stop rm" {
		t.Fatalf("docker calls = %s", v)
	}
	after, _, _ := store.Load("kunde")
	if !reflect.DeepEqual(before, after) {
		t.Fatal("state accepted although the containers can write to .ddev")
	}
}

type streamInner struct {
	calls []string
	err   error
}

func (s *streamInner) Run(args ...string) error              { return nil }
func (s *streamInner) Output(args ...string) (string, error) { return "", nil }
func (s *streamInner) RunStdin(io.Reader, ...string) error   { return nil }
func (s *streamInner) Stream(stdin io.Reader, stdout io.Writer, args ...string) error {
	s.calls = append(s.calls, strings.Join(args, " "))
	if s.err != nil {
		return s.err
	}
	_, err := io.Copy(stdout, stdin)
	return err
}

type plainInner struct{}

func (plainInner) Run(args ...string) error              { return nil }
func (plainInner) Output(args ...string) (string, error) { return "", nil }
func (plainInner) RunStdin(io.Reader, ...string) error   { return nil }

func TestGuardedStreamRunsBetweenBeforeAndAfter(t *testing.T) {
	inner := &streamInner{}
	var order []string
	g := &Guarded{Inner: inner,
		Before: func([]string) error { order = append(order, "before"); return nil },
		After:  func([]string) error { order = append(order, "after"); return nil }}
	var _ localenv.Streamer = g
	var out strings.Builder
	if err := g.Stream(strings.NewReader("in"), &out, "wp", "eval-file", "-"); err != nil {
		t.Fatal(err)
	}
	if out.String() != "in" || strings.Join(order, ",") != "before,after" || len(inner.calls) != 1 {
		t.Fatalf("out=%q order=%v calls=%v", out.String(), order, inner.calls)
	}
}

func TestGuardedStreamStopsWhenTheCheckFails(t *testing.T) {
	inner := &streamInner{}
	g := &Guarded{Inner: inner, Before: func([]string) error { return errors.New("untrusted") }, After: func([]string) error { return nil }}
	if err := g.Stream(strings.NewReader("in"), io.Discard, "wp"); err == nil || len(inner.calls) != 0 {
		t.Fatalf("err=%v calls=%v", err, inner.calls)
	}
}

func TestGuardedStreamNeedsAStreamingInner(t *testing.T) {
	g := &Guarded{Inner: plainInner{}, Before: func([]string) error { return nil }, After: func([]string) error { return nil }}
	if err := g.Stream(strings.NewReader("in"), io.Discard, "wp"); err == nil {
		t.Fatal("expected an error for an inner runner without Stream")
	}
}
