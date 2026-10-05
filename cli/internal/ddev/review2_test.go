package ddev

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

// labelDocker answers `docker ps --filter <f>` per filter, so the union of both lookups and the
// labels in inspect are tested as docker would answer.
type labelDocker struct {
	byFilter   map[string][]string
	containers map[string]Container
	calls      []string
}

func (d *labelDocker) Output(args ...string) (string, error) {
	d.calls = append(d.calls, strings.Join(args, " "))
	switch args[0] {
	case "ps":
		return strings.Join(d.byFilter[args[len(args)-1]], "\n"), nil
	case "inspect":
		type entry struct {
			ID    string `json:"Id"`
			State struct {
				Running bool `json:"Running"`
			} `json:"State"`
			Config struct {
				Labels map[string]string `json:"Labels"`
			} `json:"Config"`
			Mounts []Mount `json:"Mounts"`
		}
		var out []entry
		for _, id := range args[1:] {
			c := d.containers[id]
			e := entry{ID: id, Mounts: c.Mounts}
			e.State.Running = c.Running
			e.Config.Labels = map[string]string{"com.docker.compose.service": c.Service}
			if c.AppRoot != "" {
				e.Config.Labels["com.ddev.approot"] = c.AppRoot
			}
			out = append(out, e)
		}
		data, err := json.Marshal(out)
		return string(data), err
	}
	return "", nil
}

func (d *labelDocker) did(verb string) bool {
	for _, c := range d.calls {
		if strings.HasPrefix(c, verb+" ") {
			return true
		}
	}
	return false
}

const (
	ddevLabel    = "label=com.ddev.site-name=kunde"
	composeLabel = "label=com.docker.compose.project=ddev-kunde"
)

// M1: ein Symlink an .ddev/db_snapshots – auf .ddev selbst oder auf einen Ordner des Macs – ist
// eine Abweichung, nicht freigebbar, und die Container gelten nicht als gehärtet.
func TestSymlinkedDBSnapshots(t *testing.T) {
	outside := t.TempDir()
	for name, target := range map[string]string{"auf .ddev": ".", "absolut ausserhalb": outside} {
		t.Run(name, func(t *testing.T) {
			p, _, docker, _, _ := newSite(t)
			snap := filepath.Join(p.Dir, ".ddev", "db_snapshots")
			os.RemoveAll(snap)
			if err := os.Symlink(target, snap); err != nil {
				t.Fatal(err)
			}

			var dev *DeviationError
			if err := p.Check("start"); !errors.As(err, &dev) || len(dev.Changes) != 1 || dev.Changes[0].Path != "db_snapshots" || !dev.Changes[0].Unsafe {
				t.Fatalf("Check = %v", err)
			}
			if err := p.Accept(); !errors.Is(err, ErrUnsafeEntry) {
				t.Fatalf("Accept = %v", err)
			}
			for _, c := range docker.containers {
				if Hardened(c, p.Dir) {
					t.Errorf("%s counts as hardened with db_snapshots -> %s", c.Service, target)
				}
			}
			var out bytes.Buffer
			changes, _, content, err := p.Review()
			if err != nil {
				t.Fatal(err)
			}
			DescribeContent(&out, changes, content, nil, true)
			if !strings.Contains(out.String(), `"db_snapshots"`) || !strings.Contains(out.String(), "nicht freigebbar") {
				t.Fatalf("takeover display:\n%s", out.String())
			}
		})
	}
}

// M1: ein echter Ordner db_snapshots bleibt die erlaubte Ausnahme.
func TestRealDBSnapshotsDirIsHardened(t *testing.T) {
	site := t.TempDir()
	os.MkdirAll(filepath.Join(site, ".ddev", "db_snapshots"), 0o755)
	for _, c := range hardenedDocker(site).containers {
		if !Hardened(c, site) {
			t.Errorf("%s not hardened", c.Service)
		}
	}
}

// M2: ein erfolgreicher Aufruf mit Site-Code (wp eval), der eine geschützte Datei anlegt, wird
// nicht als neuer Soll-Zustand übernommen; der Aufruf meldet die Abweichung, der nächste läuft nicht.
func TestSiteCodeChangeIsNotAccepted(t *testing.T) {
	p, inner, _, store, _ := newSite(t)
	before, _, _ := store.Load("kunde")
	inner.hook = func(args []string) {
		if args[0] == "wp" {
			writeDDEV(t, p.Dir, "config.evil.yaml", "hooks:\n  post-start:\n    - exec-host: touch marker\n")
		}
	}
	r := p.Runner(inner)
	var dev *DeviationError
	if _, err := r.Output("wp", "eval", "echo 1;"); !errors.As(err, &dev) {
		t.Fatalf("wp eval: err = %v, want DeviationError", err)
	}
	after, _, _ := store.Load("kunde")
	if !reflect.DeepEqual(before, after) {
		t.Fatal("change made by site code landed in the store")
	}
	n := inner.n
	if _, err := LocalURL(r); !errors.As(err, &dev) || inner.n != n {
		t.Fatalf("next call: err = %v, ddev calls %d → %d", err, n, inner.n)
	}
}

// M3: die Anzeige stammt aus demselben Lesevorgang wie der Hash, nicht aus einem zweiten.
func TestReviewShowsTheHashedContent(t *testing.T) {
	site := t.TempDir()
	writeDDEV(t, site, "config.yaml", "name: kunde\n")
	writeDDEV(t, site, "config.local.yaml", "performance_mode: none\n")
	p, err := OpenProject("kunde", site, Store{Dir: filepath.Join(t.TempDir(), "s")}, &fakeDocker{})
	if err != nil {
		t.Fatal(err)
	}
	changes, shown, content, err := p.Review()
	if err != nil {
		t.Fatal(err)
	}
	writeDDEV(t, site, "config.local.yaml", "hooks:\n  post-start:\n    - exec-host: touch x\n")
	var out bytes.Buffer
	DescribeContent(&out, changes, content, nil, false)
	if strings.Contains(out.String(), "exec-host") || !strings.Contains(out.String(), "performance_mode") {
		t.Fatalf("display reread the file:\n%s", out.String())
	}
	if shown["config.local.yaml"].SHA256 != hashString("performance_mode: none\n") {
		t.Fatal("hash and display differ")
	}
}

// M4: auch ein gestoppter Container mit rw-Mount auf den Elternordner der Site und ein Add-on ohne
// DDEV-Label (nur Compose-Projekt) werden geprüft.
func TestVerifyHardenedSeesStoppedAndUnlabelledContainers(t *testing.T) {
	site := filepath.Join(t.TempDir(), "kunde")
	os.MkdirAll(filepath.Join(site, ".ddev", "db_snapshots"), 0o755)
	parent := filepath.Dir(site)
	base := map[string]Container{
		"web1": {ID: "web1", Service: "web", Running: true, Mounts: hardenedWeb(site)},
		"db1":  {ID: "db1", Service: "db", Running: true, Mounts: hardenedDB(site)},
	}
	cases := map[string]struct {
		extra    Container
		filtered []string // which lookups find it
	}{
		"gestoppt, rw auf ..":    {Container{ID: "x1", Service: "addon", Running: false, Mounts: []Mount{{Source: parent, Destination: "/all", RW: true}}}, []string{ddevLabel, composeLabel}},
		"Add-on ohne DDEV-Label": {Container{ID: "x1", Service: "addon", Running: true, Mounts: []Mount{{Source: filepath.Join(site, ".ddev"), Destination: "/cfg", RW: true}}}, []string{composeLabel}},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			d := &labelDocker{byFilter: map[string][]string{ddevLabel: {"web1", "db1"}, composeLabel: {"web1", "db1"}}, containers: map[string]Container{}}
			for id, c := range base {
				d.containers[id] = c
			}
			d.containers["x1"] = tc.extra
			for _, f := range tc.filtered {
				d.byFilter[f] = append(d.byFilter[f], "x1")
			}
			cs, err := Containers(d, "kunde")
			if err != nil || len(cs) != 3 {
				t.Fatalf("Containers = %d, %v (want web1, db1, x1 once each)", len(cs), err)
			}
			if err := VerifyHardened(d, "kunde", site); !errors.Is(err, ErrNotHardened) || !strings.Contains(err.Error(), "addon") {
				t.Fatalf("err = %v", err)
			}
			if !d.did("rm") {
				t.Fatal("containers were not removed")
			}
		})
	}
}

// K1: Container gleichen Namens aus einem anderen Ordner werden nicht entfernt.
func TestStopUnhardenedLeavesForeignProject(t *testing.T) {
	site := filepath.Join(t.TempDir(), "kunde")
	other := filepath.Join(t.TempDir(), "kunde")
	d := &labelDocker{
		byFilter:   map[string][]string{ddevLabel: {"web1"}},
		containers: map[string]Container{"web1": {ID: "web1", Service: "web", AppRoot: other, Running: true, Mounts: []Mount{{Source: other, Destination: "/var/www/html", RW: true}}}},
	}
	if _, err := StopUnhardened(d, "kunde", site); !errors.Is(err, ErrForeignProject) {
		t.Fatalf("err = %v", err)
	}
	if d.did("stop") || d.did("rm") {
		t.Fatalf("foreign containers touched: %v", d.calls)
	}
	d.containers["web1"] = Container{ID: "web1", Service: "web", AppRoot: site, Running: true, Mounts: []Mount{{Source: site, Destination: "/var/www/html", RW: true}}}
	if stopped, err := StopUnhardened(d, "kunde", site); err != nil || !stopped {
		t.Fatalf("own project: %v, %v", stopped, err)
	}
}

// K2: Docker Desktop meldet Quellen mit /host_mnt – ein rw-Mount auf den Elternordner bleibt
// erkennbar.
func TestHostMntPrefix(t *testing.T) {
	site := filepath.Join(t.TempDir(), "kunde")
	parent := filepath.Dir(site)
	data := `[{"Id":"x1","State":{"Running":true},"Config":{"Labels":{"com.docker.compose.service":"addon","com.ddev.approot":"/host_mnt` + site + `"}},
"Mounts":[{"Source":"/host_mnt` + parent + `","Destination":"/all","RW":true}]}]`
	cs, err := parseInspect([]byte(data))
	if err != nil {
		t.Fatal(err)
	}
	if cs[0].Mounts[0].Source != parent || cs[0].AppRoot != site {
		t.Fatalf("source = %q, approot = %q", cs[0].Mounts[0].Source, cs[0].AppRoot)
	}
	if Hardened(cs[0], site) {
		t.Fatal("rw mount of the site's parent counts as hardened")
	}
}

// K3: include, extends, env_file und build-Kontext ausserhalb werden hervorgehoben; exec-host
// zählt nur als Hook-Aufgabe, nicht als Wort im Kommentar.
func TestComposeAndConfigNotesFromYAML(t *testing.T) {
	compose := []byte(`include:
  - /Users/u/other.yaml
services:
  helper:
    extends:
      file: ../../evil.yaml
      service: x
    env_file: /Users/u/.secrets
    build:
      context: ../..
`)
	notes := strings.Join(composeNotes(compose), "\n")
	for _, want := range []string{"! include", "! helper: extends aus Datei ../../evil.yaml", "! helper: env_file", "! helper: build context ../.."} {
		if !strings.Contains(notes, want) {
			t.Errorf("missing %q in\n%s", want, notes)
		}
	}
	if n := composeNotes([]byte("services:\n  web:\n    build: ./web-build\n")); len(n) != 0 {
		t.Errorf("build inside .ddev flagged: %v", n)
	}
	if n := strings.Join(configNotes([]byte("# no exec-host here\nname: kunde\n")), "\n"); strings.Contains(n, "exec-host") {
		t.Errorf("comment counted as exec-host: %s", n)
	}
	if n := strings.Join(configNotes([]byte("hooks:\n  post-start:\n    - exec-host: id\n")), "\n"); !strings.Contains(n, "! exec-host") {
		t.Errorf("exec-host task missed: %s", n)
	}
}

// K4: der Fingerprint ist der volle SHA-256.
func TestFingerprintIsFullHash(t *testing.T) {
	got := Tree{"config.x.yaml": {Kind: KindFile, SHA256: hashString("x")}}
	if fp := Fingerprint(Diff(Tree{}, got), got); len(fp) != 64 {
		t.Fatalf("fingerprint %q has %d characters", fp, len(fp))
	}
}
