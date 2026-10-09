package pull

import (
	"bytes"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

// fakeDriver records the driver calls of a pull; its runner answers the mailguard check with ok.
type fakeDriver struct {
	exists bool
	calls  []string
	runner *fakeRunner
	env    agentapi.Env
}

func newFakeDriver(exists bool) *fakeDriver {
	return &fakeDriver{exists: exists, runner: &fakeRunner{output: "ok"}}
}

func (f *fakeDriver) Exists(string) (bool, error) {
	f.calls = append(f.calls, "exists")
	return f.exists, nil
}
func (f *fakeDriver) Configure(env agentapi.Env) error {
	f.calls = append(f.calls, "configure "+env.PHPVersion)
	f.env = env
	return nil
}
func (f *fakeDriver) Setup(site string) error {
	f.calls = append(f.calls, "setup "+site)
	f.exists = true
	return nil
}
func (f *fakeDriver) Start(site string) error         { f.calls = append(f.calls, "start "+site); return nil }
func (f *fakeDriver) Runner(string) localenv.Runner   { return f.runner }
func (f *fakeDriver) LocalURL(string) (string, error) { return "http://kunde.local", nil }
func (f *fakeDriver) Stop(refs ...string) (int, error) {
	f.calls = append(f.calls, "stop "+strings.Join(refs, " "))
	return len(refs), nil
}
func (f *fakeDriver) List() ([]localenv.Env, error) { return nil, nil }

const (
	fileBody  = "<?php // a"
	fileMTime = 1700000000
	tableSQL  = "INSERT INTO wp_options VALUES (1);"
)

// agentServer is a complete agent for one pull: one plugin file and one table.
func agentServer(t *testing.T, onRoute func(route string)) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		route := r.URL.Query().Get("rest_route")
		if onRoute != nil {
			onRoute(route)
		}
		switch route {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},"tables":[{"name":"wp_options","class":"config","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			fmt.Fprintf(w, `{"env":{"php_version":"8.3.35","wp_version":"6.8.1","table_prefix":"wp_","home":"https://kunde.de","siteurl":"https://kunde.de","agent_version":"0.3.1","anon":"1.abcd1234"},
"tables":[{"name":"wp_options","checksum":"c1","rows":1,"bytes":40}],
"files":[{"path":"wp-content/plugins/a/a.php","size":%d,"mtime":%d}],"skipped":[],"next":null}`, len(fileBody), fileMTime)
		case "/wpsync/v1/files":
			fmt.Fprintf(w, "F wp-content/plugins/a/a.php\t%d\t%d\n%s\nE\n", len(fileBody), fileMTime, fileBody)
		case "/wpsync/v1/db-bundle":
			fmt.Fprintf(w, "T wp_options\t1\t%d\n%s\nE\n", len(tableSQL), tableSQL)
		default:
			t.Errorf("unexpected request %s", route)
			http.Error(w, "unexpected", http.StatusNotFound)
		}
	}))
}

func pullOptions(t *testing.T, url string, drv localenv.Driver) Options {
	t.Helper()
	sheet := &agentapi.Infosheet{Tables: []agentapi.TableInfo{{Name: "wp_options", Class: "config", Essential: true}}}
	prof, err := profile.New(sheet, profile.PresetFull)
	if err != nil {
		t.Fatal(err)
	}
	return Options{
		Site:            sites.Site{Name: "kunde", URL: url, KeyID: "0123456789abcdef", RPS: 1000, Profile: prof},
		Secret:          "secret",
		SitesRoot:       t.TempDir(),
		Driver:          drv,
		Out:             &bytes.Buffer{},
		RowsPerChunk:    2000,
		FileBundleBytes: 16 << 20,
		DBBundleBytes:   8 << 20,
	}
}

// Erst-Pull: Configure → Setup, Dateien und Tabelle landen lokal, Import, Post-Setup und Mailguard laufen über den Runner.
func TestRunFirstPullGoesThroughTheDriver(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	drv := newFakeDriver(false)
	o := pullOptions(t, srv.URL, drv)

	if err := Run(o); err != nil {
		t.Fatalf("Run: %v\n%s", err, o.Out)
	}
	if got := strings.Join(drv.calls, ","); got != "configure 8.3.35,exists,setup kunde" {
		t.Errorf("driver calls = %s", got)
	}
	data, err := os.ReadFile(filepath.Join(o.SitesRoot, "kunde", "public", "wp-content", "plugins", "a", "a.php"))
	if err != nil || string(data) != fileBody {
		t.Fatalf("file = %q, %v", data, err)
	}
	runs := drv.runner.joined()
	for _, want := range []string{"mysql --user=db --password=db --database=db --binary-mode --local-infile=0", "wp search-replace https://kunde.de http://kunde.local",
		`wp search-replace https:\/\/kunde.de http:\/\/kunde.local`, `wp search-replace https:\\\/\\\/kunde.de http:\\\/\\\/kunde.local`, "local_mailguard_collect"} {
		if !strings.Contains(runs, want) {
			t.Errorf("missing %q in runner calls\n%s", want, runs)
		}
	}
	if _, err := os.Stat(localgit.GitDir(o.SitesRoot, "kunde")); err != nil {
		t.Errorf("snapshot repo missing: %v", err)
	}
}

// Folge-Pull: Start statt Setup, nichts geändert → kein Datei- und kein Tabellen-Transfer.
func TestRunFollowUpPullStartsAndTransfersNothing(t *testing.T) {
	var routes []string
	srv := agentServer(t, func(r string) { routes = append(routes, r) })
	defer srv.Close()
	drv := newFakeDriver(false)
	o := pullOptions(t, srv.URL, drv)
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	routes, drv.calls = nil, nil

	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(drv.calls, ","); got != "configure 8.3.35,exists,start kunde" {
		t.Errorf("driver calls = %s", got)
	}
	for _, r := range routes {
		if r == "/wpsync/v1/files" || r == "/wpsync/v1/db-bundle" {
			t.Errorf("follow-up pull transferred %s", r)
		}
	}
}

// Server-Modus: Docroot und Site-Ordner kommen von aussen, der Docroot heisst nicht public/,
// das Snapshot-Repo liegt in <site>/.wpsync/history.git.
func TestRunUsesGivenDocroot(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	o.SiteDir = t.TempDir()
	o.Docroot = filepath.Join(o.SiteDir, "docroot")

	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(o.Docroot, "wp-content", "plugins", "a", "a.php")); err != nil {
		t.Fatalf("file not in docroot: %v", err)
	}
	if _, err := os.Stat(filepath.Join(o.SiteDir, ".wpsync", "baseline.json")); err != nil {
		t.Fatalf("baseline not in site dir: %v", err)
	}
	if _, err := os.Stat(localgit.TreeGitDir(o.SiteDir)); err != nil {
		t.Fatalf("snapshot repo not in site dir: %v", err)
	}
	if entries, _ := os.ReadDir(o.SitesRoot); len(entries) != 0 {
		t.Fatalf("sites root touched: %v", entries)
	}

	o.Docroot = filepath.Join(t.TempDir(), "docroot")
	if err := Run(o); err == nil || !strings.Contains(err.Error(), "directly below") {
		t.Fatalf("docroot outside the site dir: err = %v", err)
	}
}
