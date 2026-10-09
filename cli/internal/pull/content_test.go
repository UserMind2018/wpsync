package pull

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/content"
	"github.com/usermind/wpsync/internal/localenv"
)

func sum(v string) *string { return &v }

func deltaTables() []agentapi.Table {
	var out []agentapi.Table
	for _, n := range []string{"posts", "postmeta", "terms", "term_taxonomy", "term_relationships", "termmeta", "options", "users", "wc_orders"} {
		out = append(out, agentapi.Table{Name: "wp_" + n, Checksum: sum("1"), Mode: "full"})
	}
	return out
}

func names(tables []agentapi.Table) []string {
	var out []string
	for _, t := range tables {
		out = append(out, t.Name)
	}
	return out
}

func TestContentTablesNeedsAllSevenWithData(t *testing.T) {
	got, err := contentTables(deltaTables(), "wp_")
	if err != nil || len(got) != 7 || got[0] != "wp_posts" || got[6] != "wp_options" {
		t.Fatalf("got=%v err=%v", got, err)
	}
	tables := deltaTables()
	tables[5].Checksum = nil // termmeta only as structure
	if _, err := contentTables(tables, "wp_"); !errors.Is(err, ErrContentScope) {
		t.Fatalf("err=%v", err)
	}
	if _, err := contentTables(deltaTables()[1:], "wp_"); !errors.Is(err, ErrContentScope) {
		t.Fatalf("missing posts: err=%v", err)
	}
}

func TestWithContentReloadsAllSevenOrNone(t *testing.T) {
	all := deltaTables()
	seven, _ := contentTables(all, "wp_")

	// nothing changed, state fresh: nothing to reload
	got, refresh := withContent(nil, all, seven, true)
	if len(got) != 0 || refresh {
		t.Fatalf("got=%v refresh=%v", names(got), refresh)
	}
	// only users changed: the content tables stay
	got, refresh = withContent(all[7:8], all, seven, true)
	if len(got) != 1 || refresh {
		t.Fatalf("got=%v refresh=%v", names(got), refresh)
	}
	// one content table changed: all seven, each once, in the order of the delta
	got, refresh = withContent([]agentapi.Table{all[6], all[7]}, all, seven, true)
	want := "wp_options wp_users wp_posts wp_postmeta wp_terms wp_term_taxonomy wp_term_relationships wp_termmeta"
	if !refresh || len(got) != 8 || joinNames(got) != want {
		t.Fatalf("got=%q refresh=%v", joinNames(got), refresh)
	}
	// no fresh content state: all seven although nothing changed
	got, refresh = withContent(nil, all, seven, false)
	if !refresh || len(got) != 7 {
		t.Fatalf("got=%v refresh=%v", names(got), refresh)
	}
}

func joinNames(tables []agentapi.Table) string {
	return strings.Join(names(tables), " ")
}

// pull --content against an agent before 0.7.0 ends before anything is set up or downloaded.
func TestRunContentRefusesAnAgentWithoutManifest(t *testing.T) {
	var routes []string
	srv := agentServer(t, func(route string) { routes = append(routes, route) })
	defer srv.Close()
	drv := newFakeDriver(false)
	o := pullOptions(t, srv.URL, drv)
	o.Content = true

	err := Run(o)
	var outdated *agentapi.OutdatedError
	if !errors.As(err, &outdated) || !errors.Is(err, ErrAgentNoContent) {
		t.Fatalf("err = %v", err)
	}
	if outdated.Installed != "0.3.1" || outdated.Required != agentapi.MinAgentContent {
		t.Fatalf("outdated = %+v", outdated)
	}
	if len(drv.calls) != 0 {
		t.Errorf("driver calls = %v", drv.calls)
	}
	if got := strings.Join(routes, ","); got != "/wpsync/v1/infosheet,/wpsync/v1/delta" {
		t.Errorf("routes = %s", got)
	}
}

// streamRunner is a fakeRunner that also answers the content export (`wp eval-file -`).
type streamRunner struct {
	*fakeRunner
	export string
}

func (s *streamRunner) Stream(stdin io.Reader, stdout io.Writer, args ...string) error {
	s.calls = append(s.calls, args)
	_, _ = io.Copy(io.Discard, stdin)
	_, err := io.WriteString(stdout, s.export)
	return err
}

type contentDriver struct {
	*fakeDriver
	runner localenv.Runner
}

func (d *contentDriver) Runner(string) localenv.Runner { return d.runner }

// contentAgent is an agent 0.7.0 with the seven content tables and a manifest of two rows. With
// usersSum set the delta also carries wp_users – a table that is no content table.
type contentAgent struct {
	postsSum     string
	usersSum     string
	manifestFail bool
	routes       []string
}

func (a *contentAgent) count(route string) int {
	n := 0
	for _, r := range a.routes {
		if r == route {
			n++
		}
	}
	return n
}

func (a *contentAgent) serve(t *testing.T) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		route := r.URL.Query().Get("rest_route")
		a.routes = append(a.routes, route)
		switch route {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},"tables":[{"name":"wp_options","class":"config","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			var tables []string
			for _, n := range contentNames {
				sum := "c1"
				if n == "posts" {
					sum = a.postsSum
				}
				tables = append(tables, fmt.Sprintf(`{"name":"wp_%s","checksum":%q,"rows":1,"bytes":40}`, n, sum))
			}
			if a.usersSum != "" {
				tables = append(tables, fmt.Sprintf(`{"name":"wp_users","checksum":%q,"rows":1,"bytes":40}`, a.usersSum))
			}
			fmt.Fprintf(w, `{"env":{"php_version":"8.3.35","wp_version":"6.8.1","table_prefix":"wp_","home":"https://kunde.de","siteurl":"https://kunde.de","agent_version":"0.7.0","anon":"1.abcd1234"},
"tables":[%s],"files":[],"skipped":[],"next":null}`, strings.Join(tables, ","))
		case "/wpsync/v1/db-bundle":
			var req struct {
				Tables []string `json:"tables"`
			}
			if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
				t.Errorf("db-bundle body: %v", err)
			}
			for _, name := range req.Tables {
				sql := "INSERT INTO " + name + " VALUES (1);"
				fmt.Fprintf(w, "T %s\t1\t%d\n%s\n", name, len(sql), sql)
			}
			fmt.Fprint(w, "E\n")
		case "/wpsync/v1/content/manifest":
			if a.manifestFail {
				fmt.Fprintln(w, `{"error":"read"}`)
				return
			}
			fmt.Fprintf(w, `{"head":{"canon_version":%d,"list_version":1,"variants":["plain","esc1","esc2"],"origins":{"home":"https://kunde.de","siteurl":"https://kunde.de"},"id_max":{"posts":7},"pushable":true}}`+"\n", content.CanonVersion)
			fmt.Fprintln(w, `{"t":"posts","k":"1","h":"aa"}`)
			fmt.Fprintln(w, `{"t":"options","k":"blogname","h":"bb"}`)
			fmt.Fprintln(w, `{"next":null}`)
		default:
			t.Errorf("unexpected request %s", route)
			http.Error(w, "unexpected", http.StatusNotFound)
		}
	}))
}

const localExport = `{"t":"posts","k":"1","h":"aa","p":true}` + "\n" +
	`{"t":"options","k":"blogname","h":"other","p":true}` + "\n" +
	`{"end":true,"rows":2}` + "\n"

// pull --content: the first pull builds the content state, an unchanged second one only reads it,
// and a failed manifest keeps the old baseline so that the next pull reloads the tables.
func TestRunContentBuildsReusesAndProtectsTheBaseline(t *testing.T) {
	agent := &contentAgent{postsSum: "c1"}
	srv := agent.serve(t)
	defer srv.Close()
	fake := newFakeDriver(false)
	runner := &streamRunner{fakeRunner: fake.runner, export: localExport}
	o := pullOptions(t, srv.URL, &contentDriver{fakeDriver: fake, runner: runner})
	o.Content = true
	var phases []string
	o.Progress = func(p Progress) {
		phases = append(phases, fmt.Sprintf("%s %d/%d", p.Phase, p.Done, p.Total))
	}
	var res Result
	o.Report = &res
	siteDir := filepath.Join(o.SitesRoot, "kunde")

	if err := Run(o); err != nil {
		t.Fatalf("first pull: %v\n%s", err, o.Out)
	}
	if res.Content == nil || res.Content.Rows != 2 || res.Content.Unfaithful != 1 || !res.Content.Reloaded || res.Content.IDMax["posts"] != 7 || res.TablesLoaded != 7 {
		t.Fatalf("first pull: content = %+v, tables = %d", res.Content, res.TablesLoaded)
	}
	if m, _, err := content.ReadMap(siteDir); err != nil || m.Local != "http://kunde.local" || m.Live.Home != "https://kunde.de" {
		t.Fatalf("map = %+v, %v", m, err)
	}
	// What the export needs of the source to run offline, as /delta named it.
	if env, err := content.ReadEnv(siteDir); err != nil || env.PHPVersion != "8.3.35" || env.TablePrefix != "wp_" {
		t.Fatalf("env = %+v, %v", env, err)
	}
	if stored, err := content.LoadSummary(siteDir); err != nil || stored.Reloaded {
		t.Fatalf("summary.json = %+v, %v", stored, err)
	}
	if got := strings.Join(phases[len(phases)-3:], ","); got != "mailguard 1/1,content 0/1,content 1/1" {
		t.Errorf("phases = %v", phases)
	}
	if !strings.Contains(runner.joined(), "wp eval-file - http://kunde.local all") {
		t.Errorf("no export in\n%s", runner.joined())
	}

	// Nothing changed: no table, no manifest, the stored numbers.
	bundles, manifests := agent.count("/wpsync/v1/db-bundle"), agent.count("/wpsync/v1/content/manifest")
	res = Result{}
	if err := Run(o); err != nil {
		t.Fatalf("second pull: %v\n%s", err, o.Out)
	}
	if res.Content == nil || res.Content.Rows != 2 || res.Content.Unfaithful != 1 || res.Content.Reloaded || res.TablesLoaded != 0 {
		t.Fatalf("second pull: content = %+v, tables = %d", res.Content, res.TablesLoaded)
	}
	if agent.count("/wpsync/v1/db-bundle") != bundles || agent.count("/wpsync/v1/content/manifest") != manifests {
		t.Errorf("second pull asked the agent again: %v", agent.routes)
	}

	// One content table changed and the manifest fails: all seven were reloaded, the baseline is the old one.
	agent.postsSum, agent.manifestFail = "c2", true
	err := Run(o)
	if !errors.Is(err, agentapi.ErrManifest) {
		t.Fatalf("third pull: err = %v", err)
	}
	base, err := baseline.Load(siteDir)
	if err != nil || base.Tables["wp_posts"] != "c1" {
		t.Fatalf("baseline after failed manifest: %v, %v", base.Tables, err)
	}
	if content.Fresh(siteDir) {
		t.Error("content state still counts as fresh after a failed manifest")
	}

	// The next pull reloads all seven and rebuilds the state.
	agent.manifestFail = false
	res = Result{}
	if err := Run(o); err != nil {
		t.Fatalf("fourth pull: %v\n%s", err, o.Out)
	}
	if res.Content == nil || !res.Content.Reloaded || res.TablesLoaded != 7 {
		t.Fatalf("fourth pull: content = %+v, tables = %d", res.Content, res.TablesLoaded)
	}
	if base, err := baseline.Load(siteDir); err != nil || base.Tables["wp_posts"] != "c2" {
		t.Fatalf("baseline after the fourth pull: %v, %v", base.Tables, err)
	}
}

// Without --content a pull neither asks for the manifest nor reports content.
func TestRunWithoutContentLeavesContentAlone(t *testing.T) {
	agent := &contentAgent{postsSum: "c1"}
	srv := agent.serve(t)
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	var res Result
	o.Report = &res
	if err := Run(o); err != nil {
		t.Fatalf("Run: %v\n%s", err, o.Out)
	}
	if res.Content != nil || agent.count("/wpsync/v1/content/manifest") != 0 {
		t.Fatalf("content = %+v, routes = %v", res.Content, agent.routes)
	}
	// B11 on a site without content state: the seven tables were loaded, nothing to drop, no folder.
	if res.TablesLoaded != 7 {
		t.Fatalf("tables = %d", res.TablesLoaded)
	}
	if _, err := os.Lstat(content.Paths(filepath.Join(o.SitesRoot, "kunde")).Dir); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf(".wpsync/content after a pull without --content: %v", err)
	}
}

func TestReloadsContent(t *testing.T) {
	all := deltaTables()
	if reloadsContent(nil, "wp_") || reloadsContent(all[7:], "wp_") {
		t.Fatal("users and wc_orders are no content tables")
	}
	for i := 0; i < 7; i++ {
		if !reloadsContent([]agentapi.Table{all[8], all[i]}, "wp_") {
			t.Errorf("%s is a content table", all[i].Name)
		}
	}
	if reloadsContent(all[:7], "other_") {
		t.Fatal("tables of another prefix are no content tables")
	}
}

// Plan B11: a pull without --content that reloads a content table drops the content state – the
// baseline no longer belongs to the working copy. The next pull with --content reloads all seven
// and says so. A pull without --content that only reloads other tables leaves the state alone.
func TestRunWithoutContentDropsTheContentStateItOutdates(t *testing.T) {
	agent := &contentAgent{postsSum: "c1", usersSum: "u1"}
	srv := agent.serve(t)
	defer srv.Close()
	fake := newFakeDriver(false)
	runner := &streamRunner{fakeRunner: fake.runner, export: localExport}
	o := pullOptions(t, srv.URL, &contentDriver{fakeDriver: fake, runner: runner})
	var res Result
	o.Report = &res
	siteDir := filepath.Join(o.SitesRoot, "kunde")
	pull := func(step string, withContent bool) {
		t.Helper()
		o.Content = withContent
		o.Out = &bytes.Buffer{}
		res = Result{}
		if err := Run(o); err != nil {
			t.Fatalf("%s: %v\n%s", step, err, o.Out)
		}
	}

	pull("first pull with --content", true)
	if res.Content == nil || !res.Content.Reloaded || res.TablesLoaded != 8 || !content.Fresh(siteDir) {
		t.Fatalf("first pull: content = %+v, tables = %d, fresh = %v", res.Content, res.TablesLoaded, content.Fresh(siteDir))
	}

	// Only users changed: the content tables stay, so does the state.
	agent.usersSum = "u2"
	pull("pull without --content, users changed", false)
	if res.TablesLoaded != 1 || !content.Fresh(siteDir) {
		t.Fatalf("users only: tables = %d, fresh = %v", res.TablesLoaded, content.Fresh(siteDir))
	}
	if strings.Contains(fmt.Sprint(o.Out), "Inhaltsstand") {
		t.Errorf("nothing was dropped:\n%s", o.Out)
	}
	pull("pull with --content, nothing changed", true)
	if res.Content == nil || res.Content.Reloaded || res.TablesLoaded != 0 {
		t.Fatalf("unchanged: content = %+v, tables = %d", res.Content, res.TablesLoaded)
	}

	// posts changed and the pull runs without --content: the baseline is outdated.
	agent.postsSum = "c2"
	manifests := agent.count("/wpsync/v1/content/manifest")
	pull("pull without --content, posts changed", false)
	if res.TablesLoaded != 1 || res.Content != nil || agent.count("/wpsync/v1/content/manifest") != manifests {
		t.Fatalf("posts changed: tables = %d, content = %+v", res.TablesLoaded, res.Content)
	}
	if content.Fresh(siteDir) {
		t.Fatal("the content state still counts as fresh although posts was reloaded")
	}
	if !strings.Contains(fmt.Sprint(o.Out), "Inhaltsstand verworfen") {
		t.Errorf("the pull does not say that it dropped the state:\n%s", o.Out)
	}

	// For the delta nothing changed any more – the dropped state alone makes the pull reload all seven.
	pull("next pull with --content", true)
	if res.Content == nil || !res.Content.Reloaded || res.TablesLoaded != 7 || !content.Fresh(siteDir) {
		t.Fatalf("after the drop: content = %+v, tables = %d, fresh = %v", res.Content, res.TablesLoaded, content.Fresh(siteDir))
	}
}

// The state is dropped before the import: if the import fails, the tables may be half replaced.
func TestRunWithoutContentDropsTheStateBeforeTheImport(t *testing.T) {
	agent := &contentAgent{postsSum: "c1"}
	srv := agent.serve(t)
	defer srv.Close()
	fake := newFakeDriver(false)
	runner := &streamRunner{fakeRunner: fake.runner, export: localExport}
	o := pullOptions(t, srv.URL, &contentDriver{fakeDriver: fake, runner: runner})
	o.Content = true
	if err := Run(o); err != nil {
		t.Fatalf("first pull: %v\n%s", err, o.Out)
	}
	siteDir := filepath.Join(o.SitesRoot, "kunde")

	agent.postsSum = "c2"
	o.Content = false
	failing := &failingImport{streamRunner: runner, siteDir: siteDir}
	o.Driver = &contentDriver{fakeDriver: fake, runner: failing}
	if err := Run(o); err == nil || !failing.asked {
		t.Fatalf("expected the import to fail, err = %v", err)
	}
	if failing.freshAtImport || content.Fresh(siteDir) {
		t.Fatalf("fresh at the import = %v, afterwards = %v", failing.freshAtImport, content.Fresh(siteDir))
	}
}

// failingImport fails every SQL import and notes whether the content state was still fresh then.
type failingImport struct {
	*streamRunner
	siteDir       string
	asked         bool
	freshAtImport bool
}

func (f *failingImport) RunStdin(stdin io.Reader, args ...string) error {
	f.asked = true
	f.freshAtImport = content.Fresh(f.siteDir)
	return errors.New("import failed")
}
