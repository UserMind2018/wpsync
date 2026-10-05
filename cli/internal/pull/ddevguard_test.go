package pull

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/sites"
)

// fakeBin puts a ddev and a docker script on PATH that log their arguments. The ddev script
// answers describe and, with WPSYNC_TEST_EVIL set, plays site code: during `ddev wp eval` it
// writes a hook into .ddev and fails – the mailguard error path then wants to `ddev stop`.
func fakeBin(t *testing.T) (ddevLog, dockerLog string) {
	t.Helper()
	bin := t.TempDir()
	logs := t.TempDir()
	ddevLog, dockerLog = filepath.Join(logs, "ddev"), filepath.Join(logs, "docker")
	ddevScript := `#!/bin/sh
echo "$@" >> '` + ddevLog + `'
if [ "$1" = describe ]; then echo '{"raw":{"httpurl":"http://kunde.ddev.site"}}'; fi
if [ "$1" = wp ] && [ "$2" = eval ]; then
  if [ -n "$WPSYNC_TEST_EVIL" ]; then printf 'hooks:\n  pre-stop:\n    - exec-host: touch marker\n' > .ddev/config.evil.yaml; fi
  exit 1
fi
exit 0
`
	dockerScript := "#!/bin/sh\necho \"$@\" >> '" + dockerLog + "'\n"
	if err := os.WriteFile(filepath.Join(bin, "ddev"), []byte(ddevScript), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(bin, "docker"), []byte(dockerScript), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin)
	return ddevLog, dockerLog
}

func readCalls(p string) []string {
	data, err := os.ReadFile(p)
	if err != nil {
		return nil
	}
	return strings.Split(strings.TrimSpace(string(data)), "\n")
}

// guardDocker reports the project's containers; hardened after a docker rm (ddev start recreates
// them with the hardening). It notes whether ddev had been called when docker stopped them.
type guardDocker struct {
	site       string
	hardened   bool
	running    bool
	ddevLog    string
	calls      []string
	ddevBefore bool
}

func (d *guardDocker) Output(args ...string) (string, error) {
	d.calls = append(d.calls, args[0])
	switch args[0] {
	case "ps":
		return "web1\ndb1\n", nil
	case "inspect":
		web := []ddev.Mount{{Source: d.site, Destination: "/var/www/html", RW: true}, {Source: filepath.Join(d.site, ".ddev"), Destination: "/mnt/ddev_config"}}
		db := []ddev.Mount{{Source: filepath.Join(d.site, ".ddev"), Destination: "/mnt/ddev_config", RW: true}}
		if d.hardened {
			web = append(web, ddev.Mount{Source: filepath.Join(d.site, ".ddev"), Destination: "/var/www/html/.ddev"})
			db = []ddev.Mount{{Source: filepath.Join(d.site, ".ddev"), Destination: "/mnt/ddev_config"},
				{Source: filepath.Join(d.site, ".ddev", "db_snapshots"), Destination: "/mnt/ddev_config/db_snapshots", RW: true}}
		}
		type c struct {
			ID     string `json:"Id"`
			State  struct{ Running bool }
			Config struct{ Labels map[string]string }
			Mounts []ddev.Mount
		}
		w, b := c{ID: "web1", Mounts: web}, c{ID: "db1", Mounts: db}
		w.State.Running, b.State.Running = d.running, d.running
		w.Config.Labels = map[string]string{"com.docker.compose.service": "web"}
		b.Config.Labels = map[string]string{"com.docker.compose.service": "db"}
		data, err := json.Marshal([]c{w, b})
		return string(data), err
	case "stop":
		d.ddevBefore = len(readCalls(d.ddevLog)) > 0
		d.running = false
	case "rm":
		d.hardened, d.running = true, true
	}
	return "", nil
}

type guardFixture struct {
	opts    Options
	site    string
	store   ddev.Store
	ddevLog string
	docker  *guardDocker
	out     *bytes.Buffer
}

// pulledSite is a site from an earlier pull: .ddev with config.yaml and wpsync's own files.
// trusted: its state was already recorded.
func pulledSite(t *testing.T, config string, trusted bool) *guardFixture {
	t.Helper()
	ddevLog, _ := fakeBin(t)
	var data int
	srv := anonServer(t, `{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"}`, `[]`, nil, &data)
	t.Cleanup(srv.Close)
	root := t.TempDir()
	site := filepath.Join(root, "kunde")
	mg := filepath.Join(t.TempDir(), "00-local-mailguard.php")
	os.WriteFile(mg, []byte("<?php"), 0o644)
	own, err := ddev.OwnFiles(mg)
	if err != nil {
		t.Fatal(err)
	}
	os.MkdirAll(filepath.Join(site, ".ddev"), 0o755)
	os.WriteFile(filepath.Join(site, ".ddev", "config.yaml"), []byte(config), 0o644)
	for name, content := range own {
		os.WriteFile(filepath.Join(site, ".ddev", name), []byte(content), 0o644)
	}
	store, err := ddev.NewStore(t.TempDir(), root)
	if err != nil {
		t.Fatal(err)
	}
	docker := &guardDocker{site: site, hardened: true, running: true, ddevLog: ddevLog}
	if trusted {
		p, err := ddev.OpenProject("kunde", site, store, docker)
		if err != nil {
			t.Fatal(err)
		}
		if err := p.Accept(); err != nil {
			t.Fatal(err)
		}
	}
	out := &bytes.Buffer{}
	return &guardFixture{
		opts: Options{
			Site:            sites.Site{Name: "kunde", URL: srv.URL, KeyID: "0123456789abcdef", RPS: 1000, Profile: anonProfile(t)},
			Secret:          "secret",
			SitesRoot:       root,
			MailguardSource: mg,
			DDEVState:       store,
			Docker:          docker,
			Yes:             true,
			Out:             out,
		},
		site: site, store: store, ddevLog: ddevLog, docker: docker, out: out,
	}
}

// AC-6, AC-10: schreibt Site-Code während `ddev wp eval` einen Hook und schlägt der Aufruf fehl,
// läuft das `ddev stop` im Mailguard-Fehlerpfad nicht mehr.
func TestRunMailguardErrorPathDoesNotStopChangedProject(t *testing.T) {
	f := pulledSite(t, "name: kunde\n", true)
	t.Setenv("WPSYNC_TEST_EVIL", "1")
	err := Run(f.opts)
	var dev *ddev.DeviationError
	if !errors.As(err, &dev) && !errors.Is(err, ErrMailguardMissing) {
		t.Fatalf("err = %v", err)
	}
	calls := readCalls(f.ddevLog)
	if len(calls) < 2 || calls[0] != "start -y" || !strings.HasPrefix(calls[len(calls)-1], "wp eval") {
		t.Fatalf("ddev calls = %q", calls)
	}
	if _, err := os.Stat(filepath.Join(f.site, ".ddev", "config.evil.yaml")); err != nil {
		t.Fatal("fake did not manipulate .ddev")
	}
}

// Gegenprobe: ohne Manipulation läuft der stop im Fehlerpfad.
func TestRunMailguardErrorPathStopsUnchangedProject(t *testing.T) {
	f := pulledSite(t, "name: kunde\n", true)
	if err := Run(f.opts); !errors.Is(err, ErrMailguardMissing) {
		t.Fatalf("err = %v", err)
	}
	calls := readCalls(f.ddevLog)
	if len(calls) < 3 || !strings.HasPrefix(calls[len(calls)-2], "wp eval") || calls[len(calls)-1] != "stop" {
		t.Fatalf("ddev calls = %q", calls)
	}
}

// AC-10 (Unit-Teil): eine vom Host abgelegte Hook- oder Host-Kommando-Datei → kein ddev-Aufruf.
func TestRunDeviationRunsNoDDEV(t *testing.T) {
	for _, rel := range []string{"config.audit.yaml", "commands/host/x", ddev.MailguardComposeFile} {
		t.Run(rel, func(t *testing.T) {
			f := pulledSite(t, "name: kunde\n", true)
			p := filepath.Join(f.site, ".ddev", filepath.FromSlash(rel))
			os.MkdirAll(filepath.Dir(p), 0o755)
			os.WriteFile(p, []byte("hooks:\n  post-start:\n    - exec-host: touch marker\n"), 0o644)
			err := Run(f.opts)
			var dev *ddev.DeviationError
			if !errors.As(err, &dev) || !strings.Contains(err.Error(), rel) {
				t.Fatalf("err = %v", err)
			}
			if calls := readCalls(f.ddevLog); len(calls) != 0 {
				t.Fatalf("ddev called: %q", calls)
			}
			if _, err := os.Stat(filepath.Join(f.site, "marker")); err == nil {
				t.Fatal("marker exists")
			}
		})
	}
}

// AC-14: bestehende Site ohne Soll-Zustand – kein ddev ohne bestätigte Übernahme; --yes zählt
// nicht, ohne Terminal keine stille Übernahme; Anzeige hebt hooks hervor.
func TestRunTakeoverNeedsConfirmation(t *testing.T) {
	const hooked = "name: kunde\nhooks:\n  post-start:\n    - exec-host: open .\n"

	f := pulledSite(t, hooked, false)
	err := Run(f.opts)
	if !errors.Is(err, ddev.ErrNotAdopted) || !strings.Contains(err.Error(), "wpsync trust kunde") {
		t.Fatalf("no terminal: err = %v", err)
	}
	if calls := readCalls(f.ddevLog); len(calls) != 0 {
		t.Fatalf("no terminal: ddev called %q", calls)
	}
	if !strings.Contains(f.out.String(), "! hooks: post-start") || !strings.Contains(f.out.String(), "! exec-host") {
		t.Fatalf("no terminal: output lacks highlights:\n%s", f.out.String())
	}
	if _, ok, _ := f.store.Load("kunde"); ok {
		t.Fatal("no terminal: state saved")
	}

	f.opts.Confirm = func(string) bool { return false }
	if err := Run(f.opts); !errors.Is(err, ErrAborted) {
		t.Fatalf("declined: err = %v", err)
	}
	if calls := readCalls(f.ddevLog); len(calls) != 0 {
		t.Fatalf("declined: ddev called %q", calls)
	}
	if _, ok, _ := f.store.Load("kunde"); ok {
		t.Fatal("declined: state saved")
	}

	var asked []string
	f.opts.Confirm = func(q string) bool { asked = append(asked, q); return true }
	Run(f.opts)
	if len(asked) != 1 || !strings.Contains(asked[0], ".ddev") {
		t.Fatalf("questions = %q", asked)
	}
	if _, ok, _ := f.store.Load("kunde"); !ok {
		t.Fatal("confirmed: state not saved")
	}
	if calls := readCalls(f.ddevLog); len(calls) == 0 || calls[0] != "start -y" {
		t.Fatalf("confirmed: ddev calls = %q", calls)
	}
}

// AC-5: laufende Container mit beschreibbarem .ddev werden per docker gestoppt, bevor ddev
// überhaupt aufgerufen wird.
func TestRunStopsUnhardenedContainersBeforeDDEV(t *testing.T) {
	f := pulledSite(t, "name: kunde\n", true)
	f.docker.hardened = false
	Run(f.opts)
	if got := strings.Join(f.docker.calls, " "); !strings.HasPrefix(got, "ps ps inspect stop rm") {
		t.Fatalf("docker calls = %s", got)
	}
	if f.docker.ddevBefore {
		t.Fatal("ddev ran before the unhardened containers were stopped")
	}
	if calls := readCalls(f.ddevLog); len(calls) == 0 || calls[0] != "start -y" {
		t.Fatalf("ddev calls = %q", calls)
	}
	if !strings.Contains(f.out.String(), "ohne ddev gestoppt") {
		t.Fatalf("output:\n%s", f.out.String())
	}
}

// SEC-103 + SEC-101: vor prepare kein docker- und kein ddev-Aufruf (auch nicht StopUnhardened).
func TestRunWithInvalidTableNameRunsNoDocker(t *testing.T) {
	ddevLog, dockerLog := fakeBin(t)
	var data int
	srv := anonServer(t, `{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"}`,
		`[{"name":"wp_users","checksum":"1","anonymized":true},{"name":"../../x","checksum":"2"}]`, nil, &data)
	defer srv.Close()
	root := t.TempDir()
	store, err := ddev.NewStore(t.TempDir(), root)
	if err != nil {
		t.Fatal(err)
	}
	err = Run(Options{
		Site:      sites.Site{Name: "kunde", URL: srv.URL, KeyID: "0123456789abcdef", RPS: 1000, Profile: anonProfile(t)},
		Secret:    "secret",
		SitesRoot: root,
		DDEVState: store,
		Yes:       true,
		Out:       &bytes.Buffer{},
	})
	if !errors.Is(err, ErrInvalidTableName) {
		t.Fatalf("err = %v, want ErrInvalidTableName", err)
	}
	if calls := readCalls(ddevLog); len(calls) != 0 {
		t.Errorf("ddev called: %q", calls)
	}
	if calls := readCalls(dockerLog); len(calls) != 0 {
		t.Errorf("docker called: %q", calls)
	}
	if _, err := os.Stat(store.Dir); err == nil {
		t.Error("state dir created")
	}
}
