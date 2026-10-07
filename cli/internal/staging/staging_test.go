package staging

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync/atomic"
	"syscall"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

const stagingPath = "/wpsync-staging-0123456789ab"

var cookieValue = strings.Repeat("c", 64)

// fakeAgent plays agent and staging copy of one site.
type fakeAgent struct {
	t             *testing.T
	srv           *httptest.Server
	version       string
	need          agentapi.StagingNeed
	steps         []agentapi.StagingStep // answers of /staging/step, in order
	begins        []agentapi.StagingBeginRequest
	probes        []string
	status        string // body of /staging/status
	login         string // URL of /staging/login; "": the copy itself
	htaccessWorks bool
	probeBase     string // base of the probe URLs; "": the copy itself
	probeToken    string
	redirect      string // the copy redirects the rewrite probe and the login link here
	copyCookies   []string
}

func newFake(t *testing.T) *fakeAgent {
	f := &fakeAgent{t: t, version: "0.5.0", htaccessWorks: true, status: `{"exists":false}`, probeToken: "probe-token"}
	f.srv = httptest.NewServer(http.HandlerFunc(f.handle))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeAgent) handle(w http.ResponseWriter, r *http.Request) {
	if strings.HasPrefix(r.URL.Path, stagingPath) {
		f.copy(w, r)
		return
	}
	switch r.URL.Query().Get("rest_route") {
	case "/wpsync/v1/ping":
		fmt.Fprintf(w, `{"agent_version":%q,"table_prefix":"wp_"}`, f.version)
	case "/wpsync/v1/infosheet":
		w.Write([]byte(`{"sheet":{"env":{},"tables":[{"name":"wp_options","class":"config","essential":true}],"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
	case "/wpsync/v1/staging/begin":
		var req agentapi.StagingBeginRequest
		json.NewDecoder(r.Body).Decode(&req)
		f.begins = append(f.begins, req)
		res := agentapi.StagingBegin{Need: &f.need, URL: f.srv.URL + stagingPath}
		if req.Op == "create" && !req.Dry {
			base := f.srv.URL + stagingPath
			if f.probeBase != "" {
				base = f.probeBase
			}
			res.Probe = &agentapi.StagingProbe{DenyURL: base + "/wpsync-probe-deny.txt", RewriteURL: base + "/wpsync-probe-rewrite", FilesURL: base + "/wpsync-probe.log", Token: f.probeToken}
		}
		json.NewEncoder(w).Encode(res)
	case "/wpsync/v1/staging/step":
		var req struct {
			Probe string `json:"probe"`
		}
		json.NewDecoder(r.Body).Decode(&req)
		f.probes = append(f.probes, req.Probe)
		if len(f.steps) == 0 {
			f.t.Errorf("unexpected step")
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		json.NewEncoder(w).Encode(f.steps[0])
		f.steps = f.steps[1:]
	case "/wpsync/v1/staging/status":
		w.Write([]byte(f.status))
	case "/wpsync/v1/staging/login":
		u := f.login
		if u == "" {
			u = f.srv.URL + stagingPath + "/?wpsync_login=tok"
		}
		fmt.Fprintf(w, `{"url":%q,"expires":1}`, u)
	default:
		http.Error(w, `{"code":"rest_no_route","message":"no route"}`, http.StatusNotFound)
	}
}

// copy answers like the .htaccess and the bolt of a staging copy.
func (f *fakeAgent) copy(w http.ResponseWriter, r *http.Request) {
	f.copyCookies = append(f.copyCookies, r.Header.Get("Cookie"))
	cookie, _ := r.Cookie("wpsync_stg")
	switch {
	case r.URL.Query().Get("wpsync_login") == "tok":
		// The bolt logs the staging admin in on the way: these cookies are not for the CLI.
		http.SetCookie(w, &http.Cookie{Name: "wordpress_logged_in_0123", Value: "wpsync%7Cadmin", Path: stagingPath + "/"})
		http.SetCookie(w, &http.Cookie{Name: "wpsync_stg", Value: cookieValue, Path: stagingPath + "/", Domain: "127.0.0.1"})
		target := stagingPath + "/wp-admin/"
		if f.redirect != "" {
			target = f.redirect
		}
		http.Redirect(w, r, target, http.StatusFound)
	case !f.htaccessWorks:
		w.Write([]byte("deny\n"))
	case cookie == nil:
		w.WriteHeader(http.StatusForbidden)
	case strings.HasSuffix(r.URL.Path, "/wpsync-probe-rewrite"):
		if f.redirect != "" {
			http.Redirect(w, r, f.redirect, http.StatusFound)
			return
		}
		w.Write([]byte(f.probeToken + "\n"))
	case strings.HasSuffix(r.URL.Path, ".log"):
		w.WriteHeader(http.StatusForbidden)
	default:
		w.Write([]byte("<html>staging</html>"))
	}
}

func options(f *fakeAgent) (Options, *bytes.Buffer) {
	out := &bytes.Buffer{}
	c := agentapi.New(f.srv.URL, "0123456789abcdef", "secret", 1000)
	c.Sleep = func(time.Duration) {}
	prof := &profile.Profile{Preset: profile.PresetFull, Uploads: profile.Uploads{Proxy: true}, Seen: profile.Seen{Tables: []string{"wp_options"}}}
	return Options{Site: sites.Site{Name: "kunde", URL: f.srv.URL, Profile: prof}, Client: c, HTTP: f.srv.Client(), Out: out, Yes: true}, out
}

func ready(f *fakeAgent) agentapi.StagingStep {
	return agentapi.StagingStep{Status: "ready", Result: &agentapi.StagingResult{URL: f.srv.URL + stagingPath, Prefix: "stgabc123_", Anonymized: true}}
}

// foreign is a host outside the paired site that counts what reaches it.
func foreign(t *testing.T) (*httptest.Server, *atomic.Int32) {
	hits := &atomic.Int32{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
		w.Write([]byte("probe-token\n"))
	}))
	t.Cleanup(srv.Close)
	return srv, hits
}

// AC-80, Spec 6.3, T4
func TestCreateProbesThenFollowsTheJob(t *testing.T) {
	f := newFake(t)
	f.steps = []agentapi.StagingStep{
		{Status: "creating", Phase: "files", Done: 1, Total: 3},
		{Status: "creating", Phase: "tables", Done: 2, Total: 9},
		ready(f),
	}
	o, out := options(f)
	var phases []string
	o.Progress = func(name string, done, total int) {
		phases = append(phases, fmt.Sprintf("%s %d/%d", name, done, total))
	}
	res, err := Create(o)
	if err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if res.Prefix != "stgabc123_" || !res.Anonymized || res.URL != f.srv.URL+stagingPath {
		t.Errorf("result = %+v", res)
	}
	if len(f.begins) != 2 || !f.begins[0].Dry || f.begins[1].Dry || f.begins[1].Scope == nil || f.begins[1].Scope.PlainPII {
		t.Errorf("begins = %+v", f.begins)
	}
	if strings.Join(f.probes, ",") != "ok,," {
		t.Errorf("probes = %q", f.probes)
	}
	if strings.Join(phases, " ") != "files 1/3 tables 2/9" {
		t.Errorf("phases = %v", phases)
	}
	// V6: the deny probe goes without a cookie, the other two with the access cookie only.
	if len(f.copyCookies) != 3 || f.copyCookies[0] != "" || f.copyCookies[1] != "wpsync_stg=probe" || f.copyCookies[2] != "wpsync_stg=probe" {
		t.Errorf("cookies of the probes = %q", f.copyCookies)
	}
}

// AC-84, S5
func TestCreateReportsAFailedProbe(t *testing.T) {
	f := newFake(t)
	f.htaccessWorks = false
	f.steps = []agentapi.StagingStep{
		{Status: "creating", Phase: "drop"},
		{Status: "failed", ErrorCode: "staging_unsupported", Error: "probe: Staging braucht diese Regel:\nlocation ^~ /wpsync-staging-0123456789ab/ {\n}"},
	}
	o, out := options(f)
	_, err := Create(o)
	if !errors.Is(err, ErrUnsupported) || !strings.Contains(err.Error(), "location ^~") {
		t.Fatalf("err = %v", err)
	}
	if f.probes[0] != "fail" || !strings.Contains(out.String(), "403") {
		t.Errorf("probes = %v, out = %s", f.probes, out)
	}
}

// S5: a cleanup that already carries the error is stepped to its end, not left on the server.
func TestFollowStepsACleanupToItsEnd(t *testing.T) {
	f := newFake(t)
	f.steps = []agentapi.StagingStep{
		{Status: "creating", Phase: "tables"},
		{Status: "creating", Phase: "drop", ErrorCode: "failed", Error: "tables: kaputt"},
		{Status: "creating", Phase: "remove", ErrorCode: "failed", Error: "tables: kaputt"},
		{Status: "failed", ErrorCode: "failed", Error: "tables: kaputt"},
	}
	o, _ := options(f)
	if _, err := Create(o); !errors.Is(err, ErrFailed) || !strings.Contains(err.Error(), "kaputt") {
		t.Fatalf("err = %v", err)
	}
	if len(f.steps) != 0 {
		t.Errorf("left %d steps of the cleanup on the server", len(f.steps))
	}
}

// T1: probe URLs outside the site are never requested, and the job that already exists on the
// server is taken down.
func TestCreateTakesDownAJobWithForeignProbeURLs(t *testing.T) {
	f := newFake(t)
	evil, hits := foreign(t)
	f.probeBase = evil.URL + stagingPath
	f.steps = []agentapi.StagingStep{
		{Status: "creating", Phase: "drop"},
		{Status: "failed", ErrorCode: "staging_unsupported", Error: "probe: nginx?"},
	}
	o, _ := options(f)
	_, err := Create(o)
	if !errors.Is(err, agentapi.ErrForeignURL) {
		t.Fatalf("err = %v", err)
	}
	if hits.Load() != 0 {
		t.Errorf("%d requests left the paired site", hits.Load())
	}
	if strings.Join(f.probes, ",") != "fail," || len(f.steps) != 0 {
		t.Errorf("job left on the server: probes = %q, steps left = %d", f.probes, len(f.steps))
	}
}

// T1: a redirect of the copy is an answer, not an address – cookie and token stay on the site.
func TestNoRedirectIsFollowed(t *testing.T) {
	f := newFake(t)
	evil, hits := foreign(t)
	f.redirect = evil.URL + "/wpsync-probe-rewrite"
	f.steps = []agentapi.StagingStep{{Status: "failed", ErrorCode: "staging_unsupported", Error: "probe: nginx?"}}
	o, out := options(f)
	jar, _ := cookiejar.New(nil)
	o.HTTP.Jar = jar
	// A client that would follow: the package must not rely on the caller's redirect policy.
	o.HTTP.CheckRedirect = nil
	if _, err := Create(o); !errors.Is(err, ErrUnsupported) || f.probes[0] != "fail" {
		t.Errorf("err = %v, probes = %q, out = %s", err, f.probes, out)
	}
	base, cookie, err := Access(o.Client, o.HTTP, o.Site.URL)
	if err != nil || base != f.srv.URL+stagingPath || cookie.Value != cookieValue {
		t.Errorf("base = %q, cookie = %+v, err = %v", base, cookie, err)
	}
	if hits.Load() != 0 {
		t.Errorf("%d requests followed a redirect off the site", hits.Load())
	}
	if u := f.srv.URL + stagingPath + "/"; len(jar.Cookies(mustURL(t, u))) != 0 {
		t.Errorf("cookies of the copy ended up in the caller's jar")
	}
}

func TestProbeNeedsAToken(t *testing.T) {
	f := newFake(t)
	f.probeToken = ""
	base := f.srv.URL + stagingPath
	p := agentapi.StagingProbe{DenyURL: base + "/wpsync-probe-deny.txt", RewriteURL: base + "/wpsync-probe-rewrite", FilesURL: base + "/wpsync-probe.log"}
	if err := Probe(context.Background(), f.srv.Client(), f.srv.URL, p); err == nil {
		t.Error("an empty answer passed as the rewrite probe")
	}
	if err := Probe(context.Background(), f.srv.Client(), "https://example.test", p); !errors.Is(err, agentapi.ErrForeignURL) || len(f.copyCookies) != 0 {
		t.Errorf("err = %v, requests = %d", err, len(f.copyCookies))
	}
}

// Spec 5.7, AC-104
func TestCreateAsksAboveOneGigabyte(t *testing.T) {
	f := newFake(t)
	code := int64(600 << 20)
	f.need = agentapi.StagingNeed{CodeBytes: &code, DBBytes: 600 << 20}
	o, _ := options(f)
	o.Yes = false
	if _, err := Create(o); !errors.Is(err, ErrNeedsYes) {
		t.Fatalf("err = %v", err)
	}
	if len(f.begins) != 1 || !f.begins[0].Dry {
		t.Errorf("created although the question was not answered: %+v", f.begins)
	}
	asked := ""
	o.Confirm = func(q string) bool { asked = q; return false }
	if _, err := Create(o); !errors.Is(err, ErrAborted) || !strings.Contains(asked, "1.4 GB") {
		t.Errorf("err = %v, asked = %q", err, asked)
	}
}

// Spec 5.7: numbers of the agent that overflow or are negative never skip the question.
func TestAbsurdNeedStillAsks(t *testing.T) {
	for _, db := range []int64{math.MaxInt64, math.MaxInt64 / 2, -1 << 40} {
		f := newFake(t)
		code := int64(math.MaxInt64)
		f.need = agentapi.StagingNeed{CodeBytes: &code, DBBytes: db}
		o, _ := options(f)
		o.Yes = false
		if _, err := Create(o); !errors.Is(err, ErrNeedsYes) || len(f.begins) != 1 {
			t.Errorf("db_bytes %d: err = %v, begins = %+v", db, err, f.begins)
		}
	}
}

// T2
func TestPlainTextNeedsConfirmation(t *testing.T) {
	f := newFake(t)
	o, _ := options(f)
	o.Yes, o.NoAnonymize = false, true
	if _, err := Create(o); !errors.Is(err, ErrNeedsYes) {
		t.Fatalf("err = %v", err)
	}
	// refresh: agreeing to the data loss is not agreeing to plain text
	asked := 0
	o.Confirm = func(string) bool { asked++; return asked == 1 }
	if _, err := Refresh(o); !errors.Is(err, ErrAborted) || asked != 2 {
		t.Fatalf("err = %v, asked = %d", err, asked)
	}
	if len(f.begins) != 0 {
		t.Errorf("the agent was asked before the questions were answered: %+v", f.begins)
	}
	f.steps = []agentapi.StagingStep{ready(f)}
	o.Yes = true
	if _, err := Create(o); err != nil || !f.begins[1].Scope.PlainPII {
		t.Errorf("err = %v, begins = %+v", err, f.begins)
	}
}

func TestCreateNeedsProfileAndNewAgent(t *testing.T) {
	f := newFake(t)
	o, _ := options(f)
	o.Site.Profile = nil
	if _, err := Create(o); !errors.Is(err, ErrNoProfile) {
		t.Errorf("err = %v", err)
	}
	f.version = "0.4.1"
	o, _ = options(f)
	var outdated *agentapi.OutdatedError
	if _, err := Create(o); !errors.As(err, &outdated) || outdated.Required != MinAgent {
		t.Errorf("err = %v", err)
	}
}

func TestDiskFullDuringTheJob(t *testing.T) {
	f := newFake(t)
	f.steps = []agentapi.StagingStep{{Status: "failed", ErrorCode: "disk_full", Error: "tables: Datenbank voll"}}
	o, _ := options(f)
	if _, err := Create(o); !errors.Is(err, syscall.ENOSPC) {
		t.Errorf("err = %v", err)
	}
}

// AC-101
func TestRefreshAsksBeforeLosingData(t *testing.T) {
	f := newFake(t)
	o, _ := options(f)
	o.Yes = false
	if _, err := Refresh(o); !errors.Is(err, ErrNeedsYes) || len(f.begins) != 0 {
		t.Fatalf("err = %v, begins = %+v", err, f.begins)
	}
	o.Confirm = func(string) bool { return false }
	if _, err := Refresh(o); !errors.Is(err, ErrAborted) || len(f.begins) != 0 {
		t.Fatalf("err = %v, begins = %+v", err, f.begins)
	}
	f.steps = []agentapi.StagingStep{ready(f)}
	o.Yes, o.Code = true, true
	if _, err := Refresh(o); err != nil {
		t.Fatal(err)
	}
	if f.begins[1].Op != "refresh" || !f.begins[1].Code || f.begins[1].Dry {
		t.Errorf("begins = %+v", f.begins)
	}
}

// AC-103
func TestDeleteFollowsUntilGone(t *testing.T) {
	f := newFake(t)
	f.steps = []agentapi.StagingStep{{Status: "deleting", Phase: "remove"}, {Status: "deleted"}}
	o, _ := options(f)
	if err := Delete(o); err != nil || f.begins[0].Op != "delete" {
		t.Errorf("err = %v, begins = %+v", err, f.begins)
	}
	f.begins = nil
	o.Yes = false
	if err := Delete(o); !errors.Is(err, ErrNeedsYes) || f.begins != nil {
		t.Errorf("deleted without confirmation: %v", err)
	}
}

// A job whose CLI died is picked up again; without a job there is nothing to resume.
func TestResume(t *testing.T) {
	f := newFake(t)
	f.steps = []agentapi.StagingStep{{Status: "refreshing", Phase: "urls", Done: 1, Total: 2}, ready(f)}
	o, _ := options(f)
	res, err := Resume(o)
	if err != nil || res == nil || res.Prefix != "stgabc123_" || len(f.begins) != 0 || strings.Join(f.probes, ",") != "," {
		t.Errorf("res = %+v, err = %v, begins = %+v, probes = %q", res, err, f.begins, f.probes)
	}
	f.steps = []agentapi.StagingStep{{Status: "locked"}}
	if _, err := Resume(o); err == nil {
		t.Error("a copy without a job counted as a finished job")
	}
}

// AC-102, Spec 6.4
func TestStatusMissingAndLocked(t *testing.T) {
	f := newFake(t)
	o, out := options(f)
	if _, err := Status(o); !errors.Is(err, ErrMissing) {
		t.Errorf("err = %v", err)
	}
	f.status = `{"exists":true,"status":"locked","url":"` + f.srv.URL + stagingPath + `","copied_at":1700000000,"last_used":1700000000,"anonymized":true}`
	st, err := Status(o)
	if !errors.Is(err, ErrLocked) || st == nil || st.Status != "locked" || !strings.Contains(out.String(), "wpsync staging open kunde") {
		t.Errorf("st = %+v, err = %v, out = %s", st, err, out)
	}
}

// T1: an address outside the site is neither shown nor handed on (status, result).
func TestForeignAddressesAreDropped(t *testing.T) {
	f := newFake(t)
	o, out := options(f)
	f.status = `{"exists":true,"status":"ready","url":"https://evil.example/wpsync-staging-0123456789ab","anonymized":true,"error":"a\u001b[2Jb"}`
	st, err := Status(o)
	if err != nil || st.URL != "" || strings.Contains(out.String(), "evil.example") || strings.Contains(out.String(), "\x1b") {
		t.Errorf("st = %+v, err = %v, out = %q", st, err, out)
	}
	step := ready(f)
	step.Result.URL = "https://evil.example/"
	f.steps = []agentapi.StagingStep{step}
	out.Reset()
	res, err := Create(o)
	if err != nil || res.URL != "" || strings.Contains(out.String(), "evil.example") {
		t.Errorf("res = %+v, err = %v, out = %q", res, err, out)
	}
}

// T1
func TestOpenRejectsForeignLinks(t *testing.T) {
	f := newFake(t)
	o, out := options(f)
	l, err := Open(o)
	if err != nil || !strings.HasSuffix(l.URL, "?wpsync_login=tok") {
		t.Fatalf("l = %+v, err = %v", l, err)
	}
	if strings.Contains(out.String(), "wpsync_login") {
		t.Errorf("the link was written to the output: %s", out)
	}
	f.login = "https://evil.example/?wpsync_login=tok"
	_, err = Open(o)
	if !errors.Is(err, agentapi.ErrForeignURL) {
		t.Fatalf("followed a link outside the site: %v", err)
	}
	if strings.Contains(err.Error(), "wpsync_login") || strings.Contains(err.Error(), "evil.example") {
		t.Errorf("the error names the link: %v", err)
	}
}

// Spec 6.2, V20
func TestAccessReturnsOnlyTheAccessCookie(t *testing.T) {
	f := newFake(t)
	o, _ := options(f)
	base, cookie, err := Access(o.Client, o.HTTP, o.Site.URL)
	if err != nil || base != f.srv.URL+stagingPath || cookie.Name != "wpsync_stg" || cookie.Value != cookieValue {
		t.Fatalf("base = %q, cookie = %+v, err = %v", base, cookie, err)
	}
	if cookie.Domain != "" || cookie.Path != "" || cookie.String() != "wpsync_stg="+cookieValue {
		t.Errorf("the cookie carries attributes of the server: %s", cookie)
	}
	if len(f.copyCookies) != 1 || f.copyCookies[0] != "" {
		t.Errorf("cookies sent with the login link = %q", f.copyCookies)
	}
}

type failingTransport struct{}

func (failingTransport) RoundTrip(r *http.Request) (*http.Response, error) {
	return nil, errors.New("dial tcp: connection refused")
}

// T1: the token of the link appears in no error.
func TestAccessErrorsDoNotCarryTheToken(t *testing.T) {
	f := newFake(t)
	o, _ := options(f)
	_, _, err := Access(o.Client, &http.Client{Transport: failingTransport{}}, o.Site.URL)
	if err == nil || strings.Contains(err.Error(), "wpsync_login") || strings.Contains(err.Error(), "tok") {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(err.Error(), "connection refused") || !strings.Contains(err.Error(), stagingPath) {
		t.Errorf("the error says neither where nor why: %v", err)
	}
	// the copy answers without the cookie: the token was used up, nothing to show
	f.login = f.srv.URL + stagingPath + "/?wpsync_login=other"
	_, _, err = Access(o.Client, o.HTTP, o.Site.URL)
	if err == nil || strings.Contains(err.Error(), "wpsync_login") {
		t.Errorf("err = %v", err)
	}
	if _, _, err := Access(o.Client, o.HTTP, "https://example.test"); !errors.Is(err, agentapi.ErrForeignURL) {
		t.Errorf("link of another site than the one named: %v", err)
	}
}

func TestAgentRefusalsKeepTheirReason(t *testing.T) {
	for code, want := range map[string]error{
		"wpsync_staging_missing": ErrMissing, "wpsync_staging_exists": ErrExists, "wpsync_staging_unsupported": ErrUnsupported,
		"wpsync_staging_locked": ErrLocked, "wpsync_staging_busy": ErrBusy,
	} {
		api := &agentapi.APIError{Status: 409, Code: code, Message: "Grund"}
		err := agentError(api)
		var got *agentapi.APIError
		if !errors.Is(err, want) || !errors.As(err, &got) || !strings.Contains(err.Error(), "Grund") {
			t.Errorf("%s: err = %v", code, err)
		}
	}
	if err := agentError(agentapi.ErrUnreachable); err != agentapi.ErrUnreachable {
		t.Errorf("err = %v", err)
	}
}

func mustURL(t *testing.T, raw string) *url.URL {
	u, err := url.Parse(raw)
	if err != nil {
		t.Fatal(err)
	}
	return u
}
