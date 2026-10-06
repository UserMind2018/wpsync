package ddev

import (
	"errors"
	"io"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/sites"
)

// recorder is a fake ddev for global calls: it records every call together with its directory.
type recorder struct {
	dir   string
	calls *[]string
	out   string
	err   error
}

func (r *recorder) log(args []string) { *r.calls = append(*r.calls, r.dir+"|"+strings.Join(args, " ")) }
func (r *recorder) Run(args ...string) error {
	r.log(args)
	return r.err
}
func (r *recorder) Output(args ...string) (string, error) {
	r.log(args)
	return r.out, r.err
}
func (r *recorder) RunStdin(_ io.Reader, args ...string) error {
	r.log(args)
	return r.err
}

func listDriver(root, out string, err error) (*Driver, *[]string) {
	calls := &[]string{}
	return &Driver{SitesRoot: root, NewRunner: func(dir string) Runner {
		return &recorder{dir: dir, calls: calls, out: out, err: err}
	}}, calls
}

const ddevList = `{"level":"info","msg":"","raw":[
 {"name":"kunde-a","status":"running","approot":"/home/u/wpsync-sites/kunde-a","httpsurl":"https://kunde-a.ddev.site:8443","primary_url":"http://kunde-a.ddev.site:8480"},
 {"name":"alt","status":"paused","approot":"/home/u/wpsync-sites/alt","httpsurl":"https://alt.ddev.site:8443"},
 {"name":"fremd","status":"running","approot":"/home/u/projekte/fremd","httpsurl":"https://fremd.ddev.site"},
 {"name":"praefix","status":"running","approot":"/home/u/wpsync-sites-other/praefix","httpsurl":"https://praefix.ddev.site"}
]}`

// Former localenv test: same projects, same result after Merge (plus Ref).
func TestListMergesProjectsAndPairedSites(t *testing.T) {
	d, _ := listDriver("/home/u/wpsync-sites", ddevList, nil)
	found, err := d.List()
	if err != nil {
		t.Fatal(err)
	}
	paired := []sites.Site{{Name: "kunde-a", URL: "https://www.kunde-a.de"}, {Name: "neu", URL: "https://neu.de"}}
	want := []localenv.Env{
		{Name: "alt", Status: localenv.StatusPaused, LocalURL: "https://alt.ddev.site:8443", Ref: "alt"},
		{Name: "kunde-a", Status: localenv.StatusRunning, LocalURL: "https://kunde-a.ddev.site:8443", LiveURL: "https://www.kunde-a.de", Ref: "kunde-a"},
		{Name: "neu", Status: localenv.StatusMissing, LiveURL: "https://neu.de"},
	}
	if got := localenv.Merge(found, paired); !reflect.DeepEqual(got, want) {
		t.Fatalf("List =\n%+v\nwant\n%+v", got, want)
	}
}

func TestListWithoutProjects(t *testing.T) {
	for _, out := range []string{`{"raw":null}`, `{"raw":[]}`} {
		d, _ := listDriver("/r", out, nil)
		got, err := d.List()
		if err != nil || len(got) != 0 {
			t.Fatalf("List(%s) = %v, %v", out, got, err)
		}
	}
}

func TestListReportsDDEVError(t *testing.T) {
	d, _ := listDriver("/r", "", errors.New("boom"))
	if _, err := d.List(); err == nil {
		t.Fatal("ddev failure must be reported")
	}
}

// D1: ddev list führt pre-describe-Hooks laufender Projekte aus – nur mit --skip-hooks, nie im Projektordner.
func TestListSkipsHooks(t *testing.T) {
	d, calls := listDriver("/r", `{"raw":[]}`, nil)
	if _, err := d.List(); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(*calls, []string{"/|list -j --skip-hooks"}) {
		t.Fatalf("calls = %v", *calls)
	}
}

type fakeGuard struct {
	deviate map[string]error
	haltErr error
	checked []string
	halted  []string
}

func (g *fakeGuard) Check(name string) error {
	g.checked = append(g.checked, name)
	return g.deviate[name]
}

func (g *fakeGuard) Halt(name string) error {
	g.halted = append(g.halted, name)
	return g.haltErr
}

// AC-6/AC-10: `wpsync stop` ruft ddev stop nur für geprüfte Sites; eine abweichende Site wird
// ohne ddev angehalten, gemeldet (Fehler) und blockiert die übrigen nicht.
func TestStopChecksEverySiteBeforeDDEV(t *testing.T) {
	d, calls := listDriver("/r", "", nil)
	g := &fakeGuard{deviate: map[string]error{
		"boese":  &DeviationError{Site: "boese", Changes: []Change{{Path: "config.audit.yaml", Kind: ChangeAdded}}},
		"vorher": ErrNotAdopted,
	}}
	d.guard = g
	n, err := d.Stop("a", "boese", "c", "vorher")
	if !reflect.DeepEqual(*calls, []string{"/|stop a c"}) {
		t.Fatalf("ddev calls = %v", *calls)
	}
	if !reflect.DeepEqual(g.checked, []string{"a", "boese", "c", "vorher"}) || !reflect.DeepEqual(g.halted, []string{"boese", "vorher"}) {
		t.Fatalf("checked = %v, halted = %v", g.checked, g.halted)
	}
	if n != 4 {
		t.Fatalf("stopped = %d", n)
	}
	if err == nil || !strings.Contains(err.Error(), "config.audit.yaml") || !strings.Contains(err.Error(), "boese wurde ohne ddev angehalten") {
		t.Fatalf("err = %v", err)
	}
	if strings.Contains(err.Error(), "vorher") {
		t.Fatalf("site without state reported as deviation: %v", err)
	}
}

func TestStopSingleDeviatingSiteRunsNoDDEV(t *testing.T) {
	d, calls := listDriver("/r", "", nil)
	g := &fakeGuard{deviate: map[string]error{"boese": &DeviationError{Site: "boese", Changes: []Change{{Path: "commands/host/x", Kind: ChangeAdded}}}}}
	d.guard = g
	_, err := d.Stop("boese")
	if err == nil || len(*calls) != 0 || !strings.Contains(err.Error(), "commands/host/x") || len(g.halted) != 1 {
		t.Fatalf("err = %v, ddev calls = %v, halted = %v", err, *calls, g.halted)
	}

	g.haltErr = errors.New("docker down")
	g.halted = nil
	_, err = d.Stop("boese")
	if err == nil || !strings.Contains(err.Error(), "boese nicht gestoppt: docker down") || len(*calls) != 0 {
		t.Fatalf("err = %v, ddev calls = %v", err, *calls)
	}
}

// writableWeb is a web container from before the hardening: .ddev is writable through /var/www/html.
func writableWeb(site string) Container {
	return Container{ID: "web1", Service: "web", Running: true, Mounts: []Mount{
		{Source: site, Destination: "/var/www/html", RW: true},
		{Source: filepath.Join(site, ".ddev"), Destination: "/mnt/ddev_config", RW: false},
	}}
}

// M3 (bisher cmd/wpsync): die eigene Prüfung von stop sieht neben .ddev auch die Container; kann
// einer .ddev noch beschreiben, geht die Site über Halt (docker) statt über ddev stop.
func TestStopGuardRefusesWritableContainers(t *testing.T) {
	root := t.TempDir()
	site := filepath.Join(root, "kunde")
	writeDDEV(t, site, "config.yaml", "name: kunde\n")
	store := Store{Dir: filepath.Join(t.TempDir(), "ddev-state")}
	p, err := OpenProject("kunde", site, store, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := p.Accept(); err != nil {
		t.Fatal(err)
	}
	d := &Driver{SitesRoot: root, State: store, Docker: &fakeDocker{}}
	if err := (ownGuard{d}).Check("kunde"); err != nil {
		t.Fatalf("no containers: %v", err)
	}
	d.Docker = &fakeDocker{containers: []Container{writableWeb(site)}}
	if err := (ownGuard{d}).Check("kunde"); !errors.Is(err, ErrNotHardened) {
		t.Fatalf("writable container: %v", err)
	}
	if err := (ownGuard{d}).Check("../x"); err == nil {
		t.Fatal("invalid project name must be refused")
	}
}

// Verhalten wie vor dem Interface: Setup legt das Projekt an (config, start, core download,
// Präfix), Start startet, LocalURL fragt describe – alles über den geprüften Projekt-Runner.
func TestDriverRunsTheFormerDDEVCommandsThroughTheGuard(t *testing.T) {
	root := t.TempDir()
	site := filepath.Join(root, "kunde")
	inner := &fakeDDEV{dir: site}
	d := &Driver{
		SitesRoot: root, MailguardSource: mailguardFile(t), State: Store{Dir: filepath.Join(t.TempDir(), "ddev-state")},
		Docker: hardenedDocker(site), Out: io.Discard, NewRunner: func(string) Runner { return inner },
	}
	if err := d.Configure(agentapi.Env{PHPVersion: "8.3.35", WPVersion: "6.8.1", DBServer: "10.11.14-MariaDB", TablePrefix: "abc_"}); err != nil {
		t.Fatal(err)
	}
	if ok, _ := d.Exists("kunde"); ok {
		t.Fatal("no project before setup")
	}
	if err := d.Setup("kunde"); err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"config --project-name=kunde --project-type=wordpress --docroot=public --php-version=8.3 --database=mariadb:10.11 --performance-mode=none",
		"start -y",
		"wp core download --version=6.8.1 --skip-content --force",
		"wp config set table_prefix abc_ --type=variable",
	} {
		if !strings.Contains(strings.Join(argsOf(inner.calls), "\n"), want) {
			t.Errorf("missing %q in\n%v", want, argsOf(inner.calls))
		}
	}
	if ok, _ := d.Exists("kunde"); !ok {
		t.Fatal("project must exist after setup")
	}

	inner.calls = nil
	if err := d.Start("kunde"); err != nil {
		t.Fatal(err)
	}
	url, err := d.LocalURL("kunde")
	if err != nil || url != "http://kunde.ddev.site" {
		t.Fatalf("LocalURL = %q, %v", url, err)
	}
	if got := argsOf(inner.calls); !reflect.DeepEqual(got, []string{"start -y", "describe -j"}) {
		t.Fatalf("calls = %v", got)
	}

	// Ein Hook, den Site-Code in .ddev ablegt, stoppt jeden weiteren Aufruf über den Runner.
	writeDDEV(t, site, "config.evil.yaml", "hooks:\n  pre-stop:\n    - exec-host: touch marker\n")
	inner.calls = nil
	var dev *DeviationError
	if err := d.Runner("kunde").Run("wp", "eval", "x"); !errors.As(err, &dev) || len(inner.calls) != 0 {
		t.Fatalf("err = %v, calls = %v", err, argsOf(inner.calls))
	}
}

func TestDriverUploadsProxyRestartsOnlyOnChange(t *testing.T) {
	root := t.TempDir()
	site := filepath.Join(root, "kunde")
	inner := &fakeDDEV{dir: site}
	d := &Driver{
		SitesRoot: root, MailguardSource: mailguardFile(t), State: Store{Dir: filepath.Join(t.TempDir(), "ddev-state")},
		Docker: hardenedDocker(site), Out: io.Discard, NewRunner: func(string) Runner { return inner },
	}
	d.Configure(agentapi.Env{PHPVersion: "8.3.35", WPVersion: "6.8.1", DBServer: "10.11.14-MariaDB", TablePrefix: "wp_"})
	if err := d.Setup("kunde"); err != nil {
		t.Fatal(err)
	}
	inner.calls = nil
	for i := 0; i < 2; i++ {
		if err := d.UploadsProxy("kunde", "https://kunde.de", "wpsync/test", true); err != nil {
			t.Fatal(err)
		}
	}
	if got := argsOf(inner.calls); !reflect.DeepEqual(got, []string{"restart"}) {
		t.Fatalf("calls = %v", got)
	}
}
