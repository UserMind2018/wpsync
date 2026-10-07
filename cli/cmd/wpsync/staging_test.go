package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/keychain"
	"github.com/usermind/wpsync/internal/sites"
	"github.com/usermind/wpsync/internal/staging"
)

const (
	// testCopy is the folder of the staging copy on the fake site.
	testCopy = "/wpsync-staging-0123456789ab"
	// testToken is the token of a login link; it must reach the browser or stdout, nothing else.
	testToken  = "L0G1N-T0K3N-0123456789abcdef"
	testPushID = "p_20261005_0123456789ab"
)

// stagingFake plays agent 0.5.0 and the staging copy as the CLI sees it from the outside.
type stagingFake struct {
	t   *testing.T
	srv *httptest.Server

	version string
	status  string   // answer of /staging/status
	busy    bool     // /staging/begin answers 423 wpsync_staging_busy
	closed  bool     // /staging/begin and /staging/login answer 403 wpsync_push_window (before busy, like the agent)
	steps   []string // answers of /staging/step in order; empty: one progress line, then ready
	result  string   // url of the finished copy; empty: the copy on this site
	login   string   // url of the login link; empty: a link into the copy
	list    string   // answer of /push/list; empty: one confirmed push to staging
	confirm int      // HTTP status of /push/confirm
	shut    bool     // /push/begin reports the push window closed

	routes []string
	begins []map[string]any
	seen   bytes.Buffer // every request as the server saw it: line, headers, body
}

func newStagingFake(t *testing.T) *stagingFake {
	t.Helper()
	f := &stagingFake{t: t, version: "0.5.0", status: `{"exists":false}`, confirm: 200}
	f.srv = httptest.NewServer(http.HandlerFunc(f.handle))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *stagingFake) copyURL() string { return f.srv.URL + testCopy }

func (f *stagingFake) windowClosed(w http.ResponseWriter) {
	w.WriteHeader(http.StatusForbidden)
	w.Write([]byte(`{"code":"wpsync_push_window","message":"Das Push-Fenster ist geschlossen – im WP-Admin unter Werkzeuge → wpsync öffnen."}`))
}

func (f *stagingFake) handle(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	fmt.Fprintf(&f.seen, "%s %s %v %s\n", r.Method, r.URL, r.Header, body)
	route := r.URL.Query().Get("rest_route")
	if route == "" { // the copy from the outside: only the rewrite probe answers
		if _, err := r.Cookie("wpsync_stg"); err == nil && r.URL.Path == testCopy+"/wpsync-probe-rewrite" {
			w.Write([]byte("probe-token"))
			return
		}
		w.WriteHeader(http.StatusForbidden)
		return
	}
	f.routes = append(f.routes, strings.TrimPrefix(route, "/wpsync/v1/"))
	env := fmt.Sprintf(`{"php_version":"8.3.35","wp_version":"6.8.1","db_server":"10.11.14-MariaDB","table_prefix":"wp_","home":%q,"siteurl":%q,"agent_version":%q,"anon":"1.abcd1234"}`,
		f.srv.URL, f.srv.URL, f.version)
	switch route {
	case "/wpsync/v1/ping":
		w.Write([]byte(env))
	case "/wpsync/v1/infosheet":
		fmt.Fprintf(w, `{"sheet":{"env":%s,"tables":[{"name":"wp_options","class":"config","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`, env)
	case "/wpsync/v1/staging/begin":
		var req map[string]any
		json.Unmarshal(body, &req)
		f.begins = append(f.begins, req)
		if f.closed {
			f.windowClosed(w)
			return
		}
		if f.busy {
			w.WriteHeader(http.StatusLocked)
			w.Write([]byte(`{"code":"wpsync_staging_busy","message":"Auf der Staging-Kopie läuft gerade ein Job."}`))
			return
		}
		if req["dry"] == true || req["op"] != "create" {
			w.Write([]byte(`{"need":{"code_bytes":1000,"db_bytes":2000,"disk_free":null,"warnings":[]},"probe":null,"url":""}`))
			return
		}
		fmt.Fprintf(w, `{"need":null,"url":%q,"probe":{"deny_url":%q,"rewrite_url":%q,"files_url":%q,"token":"probe-token"}}`,
			f.copyURL(), f.copyURL()+"/wpsync-probe-deny.txt", f.copyURL()+"/wpsync-probe-rewrite", f.copyURL()+"/wpsync-probe.log")
	case "/wpsync/v1/staging/step":
		if len(f.steps) == 0 {
			url := f.result
			if url == "" {
				url = f.copyURL()
			}
			f.steps = []string{
				`{"status":"creating","phase":"tables","done":1,"total":4}`,
				fmt.Sprintf(`{"status":"ready","phase":"","result":{"replaced":{"stg0123ab_posts":3},"url":%q,"prefix":"stg0123ab_","anonymized":true,"skipped_values":0,"files":9}}`, url),
			}
		}
		w.Write([]byte(f.steps[0]))
		if len(f.steps) > 1 {
			f.steps = f.steps[1:]
		}
	case "/wpsync/v1/staging/status":
		w.Write([]byte(f.status))
	case "/wpsync/v1/staging/login":
		if f.closed {
			f.windowClosed(w)
			return
		}
		url := f.login
		if url == "" {
			url = f.copyURL() + "/?wpsync_login=" + testToken
		}
		fmt.Fprintf(w, `{"url":%q,"expires":1791158700}`, url)
	case "/wpsync/v1/push/begin":
		var req agentapi.PushBeginRequest
		json.Unmarshal(body, &req)
		fmt.Fprintf(w, `{"push_id":"","target":%q,"agent_version":%q,"health_urls":[],"window_open":%t,"pending":null,
"units":[{"path":"plugins/x","exists":true,"version":"1.0","conflicts":[],"need":["x.php"],"writable":true}],"rescue":{"url":"","salt":""}}`, req.Target, f.version, !f.shut)
	case "/wpsync/v1/push/list":
		if f.list != "" {
			w.Write([]byte(f.list))
			return
		}
		w.Write([]byte(`{"pushes":[{"push_id":"` + testPushID + `","device":"mac","target":"staging","status":"confirmed","forced":false,"pruned":false,"units":[{"path":"plugins/x","files":2,"uploaded":1}],"created":1791158400}]}`))
	case "/wpsync/v1/push/confirm":
		if f.confirm != 200 {
			w.WriteHeader(f.confirm)
			w.Write([]byte(`{"code":"wpsync_push_state","message":"abgelehnt"}`))
			return
		}
		w.Write([]byte(`{"ok":true}`))
	case "/wpsync/v1/push/rollback":
		w.Write([]byte(`{"ok":true}`))
	default:
		http.Error(w, `{"code":"rest_no_route","message":"no route"}`, http.StatusNotFound)
	}
}

// called counts the requests to one route.
func (f *stagingFake) called(route string) int {
	n := 0
	for _, r := range f.routes {
		if r == route {
			n++
		}
	}
	return n
}

// stagingSite pairs "kunde" with a fake agent.
func stagingSite(t *testing.T) *stagingFake {
	t.Helper()
	env(t)
	f := newStagingFake(t)
	paired(t, "kunde", f.srv.URL)
	return f
}

// stg runs wpsync staging … with the secret on stdin.
func stg(t *testing.T, args ...string) result {
	t.Helper()
	return run(t, context.Background(), testSecret+"\n", append(append([]string{"staging"}, args...), "--secret-stdin")...)
}

func keys(m map[string]any) string {
	var out []string
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return strings.Join(out, " ")
}

// Spec 2b 6.4, AC-104
func TestStagingStatusLockedJSON(t *testing.T) {
	f := stagingSite(t)
	f.status = fmt.Sprintf(`{"exists":true,"status":"locked","url":%q,"anonymized":true}`, f.copyURL())
	res := stg(t, "status", "kunde", "--json")
	m := lastResult(t, res, "staging status", cliout.ExitStagingLocked)
	if d := m["data"].(map[string]any); d["status"] != "locked" || d["url"] != f.copyURL() || m["error"].(map[string]any)["code"] != "staging_locked" {
		t.Errorf("result = %v", m)
	}
}

// AC-104: --json fragt nie
func TestStagingDeleteJSONNeedsYes(t *testing.T) {
	f := stagingSite(t)
	res := stg(t, "delete", "kunde", "--json")
	m := lastResult(t, res, "staging delete", cliout.ExitUsage)
	if len(jsonLines(t, res.stdout)) != 1 || f.called("staging/begin") != 0 || !strings.Contains(m["error"].(map[string]any)["message"].(string), "--yes") {
		t.Errorf("routes = %v\n%s", f.routes, res.stdout)
	}
}

func TestStagingDeleteJSON(t *testing.T) {
	f := stagingSite(t)
	f.steps = []string{`{"status":"deleting","phase":"drop","done":0,"total":0}`, `{"status":"deleted","phase":""}`}
	res := stg(t, "delete", "kunde", "--json", "--yes")
	m := lastResult(t, res, "staging delete", 0)
	if d := m["data"].(map[string]any); d["status"] != "deleted" || d["site"] != "kunde" || f.begins[0]["op"] != "delete" {
		t.Errorf("result = %v, begins = %v", m, f.begins)
	}
}

func TestStagingMissingAndUsage(t *testing.T) {
	f := stagingSite(t)
	if res := stg(t, "status", "kunde"); res.code != cliout.ExitStagingMissing || !strings.Contains(res.stderr, "wpsync staging create kunde") {
		t.Errorf("missing: exit = %d\n%s", res.code, res.stderr)
	}
	f.routes = nil
	for _, args := range [][]string{
		{"staging"},
		{"staging", "--json"},
		{"staging", "frobnicate", "kunde"},
		{"staging", "status"},
		{"staging", "status", "kunde", "extra"},
		{"staging", "status", "kunde", "--code"},
		{"staging", "create", "kunde", "--code"},
		{"staging", "open", "kunde", "--no-anonymize"},
		{"staging", "delete", "kunde", "--print"},
		{"staging", "create", "kunde", "--driver", "container"},
	} {
		if res := run(t, context.Background(), "", args...); res.code != cliout.ExitUsage {
			t.Errorf("%v: exit = %d\n%s", args, res.code, res.stderr)
		}
	}
	if len(f.routes) != 0 {
		t.Errorf("a usage error reached the agent: %v", f.routes)
	}
	// An unknown subcommand is answered as "staging", in JSON when asked for.
	lastResult(t, run(t, context.Background(), "", "staging", "frobnicate", "kunde", "--json"), "staging", cliout.ExitUsage)
}

// T1: --print gibt nur den Link aus
func TestStagingOpenPrint(t *testing.T) {
	f := stagingSite(t)
	res := stg(t, "open", "kunde", "--print")
	if res.code != 0 || strings.TrimSpace(res.stdout) != f.copyURL()+"/?wpsync_login="+testToken || len(res.opened) != 0 {
		t.Errorf("exit = %d, stdout = %q, opened = %v, stderr = %s", res.code, res.stdout, res.opened, res.stderr)
	}
}

// T1: der Link geht an den Browser, sonst nirgends hin; mit --json nur ins Ergebnis.
func TestStagingOpen(t *testing.T) {
	f := stagingSite(t)
	link := f.copyURL() + "/?wpsync_login=" + testToken

	res := stg(t, "open", "kunde")
	if res.code != 0 || len(res.opened) != 1 || res.opened[0] != link || strings.Contains(res.stdout+res.stderr, testToken) {
		t.Errorf("exit = %d, opened = %v\n%s\n%s", res.code, res.opened, res.stdout, res.stderr)
	}

	if !strings.Contains(res.stdout, staging.BrowserWarning) {
		t.Errorf("no browser warning:\n%s", res.stdout)
	}

	res = stg(t, "open", "kunde", "--json")
	m := lastResult(t, res, "staging open", 0)
	d := m["data"].(map[string]any)
	if keys(d) != "expires url warnings" || d["url"] != link || d["expires"] != float64(1791158700) || len(res.opened) != 0 || strings.Contains(res.stderr, testToken) {
		t.Errorf("data = %v, opened = %v\n%s", m["data"], res.opened, res.stderr)
	}
	if w, _ := d["warnings"].([]any); len(w) != 1 || w[0] != staging.BrowserWarning {
		t.Errorf("warnings = %v", d["warnings"])
	}
}

// U44 (Review H2): ohne Push-Fenster legt kein Staging-Befehl etwas an und es gibt keinen Link;
// Exit 40 push_window_closed mit dem Weg zum Fenster. Ein Job wird dabei auch nicht fortgesetzt.
func TestStagingNeedsThePushWindow(t *testing.T) {
	for _, args := range [][]string{
		{"create", "kunde", "--yes"}, {"refresh", "kunde", "--yes"}, {"delete", "kunde", "--yes"},
		{"open", "kunde"}, {"open", "kunde", "--print"},
	} {
		f := stagingSite(t)
		f.closed, f.busy = true, true
		f.status = fmt.Sprintf(`{"exists":true,"status":"creating","url":%q,"anonymized":true,"job":{"status":"creating","phase":"tables"}}`, f.copyURL())
		res := stg(t, args...)
		if res.code != cliout.ExitPushWindowClosed || len(res.opened) != 0 || strings.Contains(res.stdout+res.stderr, testToken) ||
			!strings.Contains(res.stderr, f.srv.URL+"/wp-admin/tools.php?page=wpsync") {
			t.Errorf("%v: exit = %d, opened = %v\n%s\n%s", args, res.code, res.opened, res.stdout, res.stderr)
		}
		for _, r := range f.routes {
			if r == "staging/step" {
				t.Errorf("%v: routes = %v", args, f.routes)
			}
		}

		res = stg(t, append(args, "--json")...)
		m := lastResult(t, res, "staging "+args[0], cliout.ExitPushWindowClosed)
		if m["error"].(map[string]any)["code"] != "push_window_closed" {
			t.Errorf("%v: result = %v", args, m)
		}
	}
	// status needs no window
	f := stagingSite(t)
	f.closed = true
	if res := stg(t, "status", "kunde", "--json"); res.code != cliout.ExitStagingMissing {
		t.Errorf("status: exit = %d\n%s", res.code, res.stderr)
	}
}

// Ohne Browser (Server, SSH) bleibt der Link nutzbar.
func TestStagingOpenWithoutBrowserPrintsTheLink(t *testing.T) {
	f := stagingSite(t)
	var out, errOut bytes.Buffer
	a := &app{ctx: context.Background(), stdin: strings.NewReader(testSecret + "\n"), stdout: &out, stderr: &errOut, kc: keychain.NewMemory()}
	a.browse = func(string) error { return errors.New("kein Browser") }
	if code := a.main([]string{"staging", "open", "kunde", "--secret-stdin"}); code != 0 || !strings.Contains(out.String(), f.copyURL()+"/?wpsync_login="+testToken) {
		t.Errorf("exit = %d\n%s\n%s", code, out.String(), errOut.String())
	}
}

// Ein Link ausserhalb der gekoppelten Site wird weder geöffnet noch ausgegeben.
func TestStagingOpenRefusesAForeignLink(t *testing.T) {
	f := stagingSite(t)
	f.login = "https://evil.example/?wpsync_login=" + testToken
	for _, args := range [][]string{{"open", "kunde"}, {"open", "kunde", "--print"}, {"open", "kunde", "--json"}} {
		res := stg(t, args...)
		if res.code != cliout.ExitUnknown || len(res.opened) != 0 || strings.Contains(res.stdout+res.stderr, testToken) || strings.Contains(res.stdout, "evil.example") {
			t.Errorf("%v: exit = %d, opened = %v\n%s\n%s", args, res.code, res.opened, res.stdout, res.stderr)
		}
	}
}

// Spec 2b 6.5: Phasen-Zeilen wie beim Pull, Ergebnis mit dem Befehl samt Unterbefehl.
func TestStagingCreateJSON(t *testing.T) {
	f := stagingSite(t)
	res := stg(t, "create", "kunde", "--json")
	m := lastResult(t, res, "staging create", 0)
	lines := jsonLines(t, res.stdout)
	if len(lines) != 2 {
		t.Fatalf("lines = %v", lines)
	}
	// The same line pull --json writes.
	var want bytes.Buffer
	cliout.NewWriter(&want).Phase("tables", 1, 4)
	if first := strings.SplitN(res.stdout, "\n", 2)[0] + "\n"; first != want.String() {
		t.Errorf("phase line = %q, want %q", first, want.String())
	}
	d := m["data"].(map[string]any)
	if keys(d) != "anonymized files prefix replaced skipped_values url" || d["url"] != f.copyURL() || d["prefix"] != "stg0123ab_" ||
		d["anonymized"] != true || d["replaced"].(map[string]any)["stg0123ab_posts"] != float64(3) || d["files"] != float64(9) {
		t.Errorf("data = %v", d)
	}
	if len(f.begins) != 2 || f.begins[0]["dry"] != true || f.begins[1]["dry"] == true || f.begins[1]["op"] != "create" {
		t.Errorf("begins = %v", f.begins)
	}
	if scope, _ := f.begins[1]["scope"].(map[string]any); scope == nil || scope["plain_pii"] == true {
		t.Errorf("scope = %v", f.begins[1]["scope"])
	}
	// V11: the secret comes from stdin and shows up nowhere – not in the output, not on the wire.
	for name, text := range map[string]string{"stdout": res.stdout, "stderr": res.stderr, "requests": f.seen.String()} {
		if strings.Contains(text, testSecret) {
			t.Errorf("secret in %s", name)
		}
	}
	if !strings.Contains(res.stderr, "wpsync staging open kunde") {
		t.Errorf("human lines belong on stderr:\n%s", res.stderr)
	}
}

func TestStagingRefreshJSON(t *testing.T) {
	f := stagingSite(t)
	lastResult(t, stg(t, "refresh", "kunde", "--json"), "staging refresh", cliout.ExitUsage) // would ask
	if len(f.begins) != 0 {
		t.Fatalf("begin before the confirmation: %v", f.begins)
	}
	f.steps = []string{
		`{"status":"refreshing","phase":"remove-code","done":2,"total":9}`,
		fmt.Sprintf(`{"status":"ready","phase":"","result":{"replaced":{},"url":%q,"prefix":"stg0123ab_","anonymized":false,"skipped_values":2,"files":9}}`, f.copyURL()),
	}
	res := stg(t, "refresh", "kunde", "--json", "--yes", "--code", "--no-anonymize")
	m := lastResult(t, res, "staging refresh", 0)
	if d := m["data"].(map[string]any); d["anonymized"] != false || d["skipped_values"] != float64(2) {
		t.Errorf("data = %v", d)
	}
	last := f.begins[len(f.begins)-1]
	if last["op"] != "refresh" || last["code"] != true || last["scope"].(map[string]any)["plain_pii"] != true {
		t.Errorf("begin = %v", last)
	}
	if lines := jsonLines(t, res.stdout); lines[0]["name"] != "remove-code" || lines[0]["done"] != float64(2) || lines[0]["total"] != float64(9) {
		t.Errorf("lines = %v", lines)
	}
}

func TestStagingCreateForHumans(t *testing.T) {
	f := stagingSite(t)
	res := stg(t, "create", "kunde")
	if res.code != 0 || !strings.Contains(res.stdout, "✓ Staging angelegt: "+f.copyURL()) ||
		!strings.Contains(res.stdout, "wpsync staging open kunde") || !strings.Contains(res.stdout, "wpsync push kunde code <einheit> --to staging") {
		t.Errorf("exit = %d\n%s\n%s", res.code, res.stdout, res.stderr)
	}
}

// Eine Adresse ausserhalb der gekoppelten Site kommt leer an: sie wird weder gezeigt noch weitergereicht.
func TestStagingCreateWithoutAnAddress(t *testing.T) {
	f := stagingSite(t)
	f.result = "https://evil.example/wpsync-staging-0123456789ab"
	res := stg(t, "create", "kunde")
	if res.code != 0 || !strings.Contains(res.stdout, "✓ Staging angelegt\n") || strings.Contains(res.stdout+res.stderr, "evil.example") {
		t.Errorf("exit = %d\n%s\n%s", res.code, res.stdout, res.stderr)
	}
	f.steps = nil
	res = stg(t, "create", "kunde", "--json")
	if m := lastResult(t, res, "staging create", 0); m["data"].(map[string]any)["url"] != "" || strings.Contains(res.stdout+res.stderr, "evil.example") {
		t.Errorf("result = %v\n%s", m, res.stderr)
	}
}

// Ein Lauf, der mitten im Job endete, hinterlässt den Job auf dem Server (423). Derselbe Befehl
// setzt ihn fort – aber nur den eigenen, und nie ein create, das noch auf seine Probe wartet.
func TestStagingPicksUpItsOwnJob(t *testing.T) {
	job := func(status, phase string, anonymized bool) string {
		return fmt.Sprintf(`{"exists":true,"status":%q,"anonymized":%v,"job":{"status":%q,"phase":%q,"done":1,"total":4}}`, status, anonymized, status, phase)
	}
	cases := []struct {
		name   string
		args   []string
		status string
		exit   int
	}{
		{"create", []string{"create", "kunde", "--json"}, job("creating", "tables", true), 0},
		{"refresh", []string{"refresh", "kunde", "--json", "--yes"}, job("refreshing", "anonymize", true), 0},
		{"plain create", []string{"create", "kunde", "--json", "--yes", "--no-anonymize"}, job("creating", "tables", false), 0},
		{"create waits for its probe", []string{"create", "kunde", "--json"}, job("creating", "probe", true), cliout.ExitBusy},
		{"create meets a refresh", []string{"create", "kunde", "--json"}, job("refreshing", "tables", true), cliout.ExitBusy},
		{"refresh meets a delete", []string{"refresh", "kunde", "--json", "--yes"}, job("deleting", "drop", true), cliout.ExitBusy},
		{"job copies plain text", []string{"create", "kunde", "--json"}, job("creating", "tables", false), cliout.ExitBusy},
		{"job anonymizes", []string{"create", "kunde", "--json", "--yes", "--no-anonymize"}, job("creating", "tables", true), cliout.ExitBusy},
		{"cleanup of a failed job", []string{"create", "kunde", "--json"}, job("failed", "drop", true), cliout.ExitBusy},
		{"a push to staging", []string{"refresh", "kunde", "--json", "--yes"}, `{"exists":true,"status":"ready","anonymized":true}`, cliout.ExitBusy},
		{"no copy", []string{"create", "kunde", "--json"}, `{"exists":false}`, cliout.ExitBusy},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := stagingSite(t)
			f.busy, f.status = true, c.status
			res := stg(t, c.args...)
			m := lastResult(t, res, "staging "+c.args[0], c.exit)
			if steps := f.called("staging/step"); (c.exit == 0) != (steps > 0) {
				t.Errorf("steps = %d for exit %d (routes %v)", steps, c.exit, f.routes)
			}
			if len(f.begins) != 1 || f.begins[0]["dry"] != true {
				t.Errorf("begins = %v", f.begins)
			}
			if c.exit == 0 {
				if d := m["data"].(map[string]any); d["url"] != f.copyURL() || !strings.Contains(res.stderr, "fortgesetzt") {
					t.Errorf("data = %v\n%s", d, res.stderr)
				}
			} else if e := m["error"].(map[string]any); e["code"] != "busy" || !strings.Contains(e["message"].(string), "wpsync staging status kunde") {
				t.Errorf("error = %v", e)
			}
		})
	}
}

func TestStagingDeletePicksUpItsOwnJob(t *testing.T) {
	f := stagingSite(t)
	f.busy = true
	f.status = `{"exists":true,"status":"deleting","anonymized":true,"job":{"status":"deleting","phase":"remove","done":3,"total":9}}`
	f.steps = []string{`{"status":"deleting","phase":"remove","done":6,"total":9}`, `{"status":"deleted","phase":""}`}
	m := lastResult(t, stg(t, "delete", "kunde", "--json", "--yes"), "staging delete", 0)
	if m["data"].(map[string]any)["status"] != "deleted" {
		t.Errorf("result = %v", m)
	}
}

// Der Hinweis ersetzt nur den Text, der Exit-Code folgt dem ursprünglichen Fehler (Spec 2b 6.4).
func TestStagingErrorKeepsExitCode(t *testing.T) {
	site := &sites.Site{Name: "kunde", URL: "https://kunde.example"}
	api := func(status int, code, msg string) error {
		return &agentapi.APIError{Status: status, Code: code, Message: msg}
	}
	cases := []struct {
		name string
		err  error
		exit int
		text string
	}{
		{"missing", fmt.Errorf("%w: %w", staging.ErrMissing, api(409, "wpsync_staging_missing", "x")), cliout.ExitStagingMissing, "wpsync staging create kunde"},
		{"exists", fmt.Errorf("%w: %w", staging.ErrExists, api(409, "wpsync_staging_exists", "x")), cliout.ExitStagingExists, "wpsync staging refresh kunde"},
		{"locked", staging.ErrLocked, cliout.ExitStagingLocked, "wpsync staging open kunde"},
		{"busy", fmt.Errorf("%w: %w", staging.ErrBusy, api(423, "wpsync_staging_busy", "x")), cliout.ExitBusy, "wpsync staging status kunde"},
		{"window", fmt.Errorf("%w: %w", staging.ErrWindowClosed, api(403, "wpsync_push_window", "x")), cliout.ExitPushWindowClosed, "https://kunde.example/wp-admin/tools.php?page=wpsync"},
		{"window_unwrapped", api(403, "wpsync_push_window", "Das Push-Fenster ist geschlossen"), cliout.ExitPushWindowClosed, "Push-Fenster"},
		{"unsupported", fmt.Errorf("%w: %w", staging.ErrUnsupported, api(422, "wpsync_staging_unsupported", "Multisite wird nicht unterstützt.")), cliout.ExitStagingUnsupported, "Multisite wird nicht unterstützt."},
		{"pending", api(409, "wpsync_staging_pending", "Ein Push nach Staging ist getauscht, aber nicht bestätigt."), cliout.ExitPushPending, "wpsync pushes kunde"},
		{"space", api(507, "wpsync_staging_space", "Zu wenig Platz."), cliout.ExitDiskFull, "Zu wenig Platz."},
		{"needs_yes", staging.ErrNeedsYes, cliout.ExitUsage, "--yes"},
		{"no_profile", staging.ErrNoProfile, cliout.ExitUsage, "wpsync scan kunde"},
		{"no_infosheet", staging.ErrNoInfosheet, cliout.ExitUnknown, "wpsync scan kunde --refresh"},
		{"outdated", &agentapi.OutdatedError{Installed: "0.4.0", Required: staging.MinAgent, Err: errors.New("der Agent kennt noch kein Staging")}, cliout.ExitAgentOutdated, "0.5.0"},
		{"unpaired", api(401, "wpsync_unpaired", "x"), cliout.ExitAuthFailed, "neu koppeln"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			err := stagingError(c.err, site)
			if got := cliout.Classify(err).Exit; got != c.exit || !strings.Contains(err.Error(), c.text) {
				t.Fatalf("exit = %d, message = %q, want %d with %q", got, err, c.exit, c.text)
			}
		})
	}
	// Agent text reaches the terminal without control characters, line breaks stay (nginx rule).
	err := stagingError(api(422, "wpsync_staging_unsupported", "Regel:\nlocation /x {\x1b[2J}"), site)
	if strings.Contains(err.Error(), "\x1b") || !strings.Contains(err.Error(), "Regel:\nlocation") {
		t.Errorf("message = %q", err)
	}
}

func TestStagingNeedsAgent050(t *testing.T) {
	f := stagingSite(t)
	f.version = "0.4.0"
	m := lastResult(t, stg(t, "status", "kunde", "--json"), "staging status", cliout.ExitAgentOutdated)
	if e := m["error"].(map[string]any); e["installed"] != "0.4.0" || e["required"] != "0.5.0" {
		t.Errorf("error = %v", e)
	}
}

// pulledSite lays a pulled site with one edited plugin below the sites root and returns a
// keychain that holds the secret: push, pushes and rollback know no --secret-stdin (V11).
func pulledSite(t *testing.T) (*stagingFake, keychain.Store) {
	t.Helper()
	_, sitesDir := env(t)
	f := newStagingFake(t)
	paired(t, "kunde", f.srv.URL)
	siteDir := filepath.Join(sitesDir, "kunde")
	plugin := filepath.Join(siteDir, "public", "wp-content", "plugins", "x")
	if err := os.MkdirAll(plugin, 0o755); err != nil {
		t.Fatal(err)
	}
	base := baseline.New(f.srv.URL)
	base.Files["wp-content/plugins/x/x.php"] = baseline.FileStamp{Size: 5, MTime: testMTime}
	base.PulledAt = time.Unix(testMTime, 0)
	if err := baseline.Save(siteDir, base); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(plugin, "x.php"), []byte("<?php\n/* Plugin Name: X\n * Version: 1.0 */\n// edited"), 0o644); err != nil {
		t.Fatal(err)
	}
	kc := keychain.NewMemory()
	if err := kc.Set(sites.KeychainService("kunde"), "kunde", testSecret); err != nil {
		t.Fatal(err)
	}
	return f, kc
}

// Spec 2b 6.5: push --json schreibt Ereignisse {"event":…,"data":{…}} und ein Ergebnis.
func TestPushDryRunJSON(t *testing.T) {
	f, kc := pulledSite(t)
	res := runKC(t, context.Background(), kc, "", "push", "kunde", "code", "--to", "staging", "--dry-run", "--json")
	m := lastResult(t, res, "push", 0)
	lines := jsonLines(t, res.stdout)
	if len(lines) != 2 || keys(lines[0]) != "data event" || lines[0]["event"] != "plan" {
		t.Fatalf("lines = %v\n%s", lines, res.stderr)
	}
	plan := lines[0]["data"].(map[string]any)
	if plan["target"] != "staging" || plan["window_open"] != true || plan["units"].([]any)[0].(map[string]any)["path"] != "plugins/x" {
		t.Errorf("plan = %v", plan)
	}
	d := m["data"].(map[string]any)
	if keys(d) != "push_id status target units" || d["push_id"] != "" || d["target"] != "staging" || d["status"] != "dry_run" || fmt.Sprint(d["units"]) != "[plugins/x]" {
		t.Errorf("data = %v", d)
	}
	if !strings.Contains(res.stderr, "plugins/x") || strings.Contains(res.stdout+res.stderr+f.seen.String(), testSecret) {
		t.Errorf("stderr = %s", res.stderr)
	}
}

// AC-104: --json fragt nie – ohne --yes ist der Push ein Aufruf-Fehler, übertragen wird nichts.
func TestPushJSONNeverAsks(t *testing.T) {
	f, kc := pulledSite(t)
	res := runKC(t, context.Background(), kc, "j\n", "push", "kunde", "code", "--json")
	m := lastResult(t, res, "push", cliout.ExitUsage)
	if d := m["data"].(map[string]any); d["target"] != "live" || d["status"] != "" || d["push_id"] != "" {
		t.Errorf("data = %v", d)
	}
	if f.called("push/begin") != 1 || !strings.Contains(m["error"].(map[string]any)["message"].(string), "--yes") {
		t.Errorf("routes = %v, result = %v", f.routes, m)
	}
}

func TestPushRefusesAnUnknownTarget(t *testing.T) {
	f, kc := pulledSite(t)
	for _, args := range [][]string{
		{"push", "kunde", "code", "--to", "stagin", "--yes"},
		{"push", "kunde", "code", "--to", "Staging", "--yes", "--json"},
		{"push", "kunde", "code", "--to", "", "--yes"},
		{"rollback", "kunde", "--to", "stagin"},
		{"rollback", "kunde", testPushID, "--to", "LIVE", "--json"},
	} {
		if res := runKC(t, context.Background(), kc, "", args...); res.code != cliout.ExitUsage {
			t.Errorf("%v: exit = %d\n%s", args, res.code, res.stderr)
		}
	}
	if len(f.routes) != 0 {
		t.Errorf("an unknown target reached the agent: %v", f.routes)
	}
}

func TestPushesJSON(t *testing.T) {
	f, kc := pulledSite(t)
	res := runKC(t, context.Background(), kc, "", "pushes", "kunde", "--json")
	m := lastResult(t, res, "pushes", 0)
	pushes := m["data"].(map[string]any)["pushes"].([]any)
	if p := requireKeys(t, pushes[0], "push_id", "device", "target", "status", "forced", "pruned", "units", "created"); len(pushes) != 1 || p["target"] != "staging" || p["push_id"] != testPushID {
		t.Errorf("pushes = %v", pushes)
	}
	if len(jsonLines(t, res.stdout)) != 1 {
		t.Errorf("stdout = %s", res.stdout)
	}

	f.list = `{"pushes":[]}`
	m = lastResult(t, runKC(t, context.Background(), kc, "", "pushes", "kunde", "--json"), "pushes", 0)
	if list, ok := m["data"].(map[string]any)["pushes"].([]any); !ok || len(list) != 0 {
		t.Errorf("an empty log is an empty array: %v", m["data"])
	}

	m = lastResult(t, runKC(t, context.Background(), kc, "", "pushes", "kunde", "--confirm", testPushID, "--json"), "pushes", 0)
	if d := m["data"].(map[string]any); d["push_id"] != testPushID || d["status"] != "confirmed" {
		t.Errorf("data = %v", d)
	}
	f.confirm = http.StatusConflict
	m = lastResult(t, runKC(t, context.Background(), kc, "", "pushes", "kunde", "--confirm", testPushID, "--json"), "pushes", cliout.ExitUnknown)
	if m["data"] != nil {
		t.Errorf("a refused confirmation reports no status: %v", m["data"])
	}
}

// V10: ohne --to entscheidet der Push selbst, wohin der Rollback geht; ohne ID ist es Live.
func TestRollbackJSONAndTarget(t *testing.T) {
	f, kc := pulledSite(t) // the agent's log holds one push, to staging; this machine has no journal

	res := runKC(t, context.Background(), kc, "", "rollback", "kunde", testPushID, "--json")
	m := lastResult(t, res, "rollback", 0)
	d := m["data"].(map[string]any)
	if keys(d) != "push_id status target units" || d["push_id"] != testPushID || d["target"] != "staging" || d["status"] != "rolled_back" || fmt.Sprint(d["units"]) != "[plugins/x]" {
		t.Errorf("data = %v", d)
	}
	if len(jsonLines(t, res.stdout)) != 1 || !strings.Contains(res.stderr, "zurückgerollt") {
		t.Errorf("stdout = %s\nstderr = %s", res.stdout, res.stderr)
	}

	f.routes = nil
	lastResult(t, runKC(t, context.Background(), kc, "", "rollback", "kunde", "--to", "staging", "--json"), "rollback", 0)
	if f.called("push/rollback") != 1 {
		t.Errorf("routes = %v", f.routes)
	}

	f.routes = nil
	for _, args := range [][]string{
		{"rollback", "kunde", testPushID, "--to", "live", "--json"}, // a staging push never goes back as live
		{"rollback", "kunde", "--json"},                             // no live push to take back
	} {
		res := runKC(t, context.Background(), kc, "", args...)
		if m := lastResult(t, res, "rollback", cliout.ExitUnknown); m["data"] != nil {
			t.Errorf("%v: data = %v", args, m["data"])
		}
	}
	if f.called("push/rollback") != 0 {
		t.Errorf("routes = %v", f.routes)
	}

	// For humans the same call prints text to stdout.
	res = runKC(t, context.Background(), kc, "", "rollback", "kunde", testPushID)
	if res.code != 0 || !strings.Contains(res.stdout, "Push nach Staging") {
		t.Errorf("exit = %d\n%s\n%s", res.code, res.stdout, res.stderr)
	}
}

// wpsync status nennt die Staging-Kopie – zusätzlich, die übrigen Felder bleiben.
func TestStatusShowsTheStagingCopy(t *testing.T) {
	_, sitesDir := env(t)
	ag := newAgent(t)
	paired(t, "kunde", ag.URL())
	base := baseline.New(ag.URL())
	base.PulledAt = time.Unix(testMTime, 0)
	if err := baseline.Save(filepath.Join(sitesDir, "kunde"), base); err != nil {
		t.Fatal(err)
	}
	status := func(args ...string) result {
		return run(t, context.Background(), testSecret+"\n", append([]string{"status", "kunde", "--secret-stdin"}, args...)...)
	}

	d := requireKeys(t, lastResult(t, status("--json"), "status", 0)["data"], "pulled", "source", "files_changed", "files_deleted", "tables_changed", "requests")
	if _, ok := d["staging"]; ok {
		t.Errorf("no copy, no field: %v", d)
	}

	ag.Staging = fmt.Sprintf(`{"status":"locked","url":%q,"last_used":1791158400}`, ag.URL()+testCopy)
	d = requireKeys(t, lastResult(t, status("--json"), "status", 0)["data"], "pulled", "source", "files_changed", "files_deleted", "tables_changed", "requests", "staging")
	if s := d["staging"].(map[string]any); keys(s) != "last_used status url" || s["status"] != "locked" || s["url"] != ag.URL()+testCopy {
		t.Errorf("staging = %v", s)
	}
	res := status()
	if res.code != 0 || !strings.Contains(res.stdout, "Staging: gesperrt (14 Tage ungenutzt) – "+ag.URL()+testCopy) || !strings.Contains(res.stdout, "wpsync staging open kunde") {
		t.Errorf("exit = %d\n%s", res.code, res.stdout)
	}

	// An address outside the paired site is not handed on.
	ag.Staging = `{"status":"ready","url":"https://evil.example/x","last_used":0}`
	res = status("--json")
	if s := lastResult(t, res, "status", 0)["data"].(map[string]any)["staging"].(map[string]any); s["url"] != "" || s["status"] != "ready" {
		t.Errorf("staging = %v", s)
	}
	if res = status(); !strings.Contains(res.stdout, "Staging: bereit\n") || strings.Contains(res.stdout, "evil.example") {
		t.Errorf("stdout = %s", res.stdout)
	}
}
