package agentapi

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

const testLoginToken = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

// stagingServer answers the staging routes like agent 0.5.0; answers maps a route to its JSON,
// with %s standing for the address of the copy.
func stagingServer(t *testing.T, answers map[string]string) (*httptest.Server, *[]map[string]any) {
	t.Helper()
	var bodies []map[string]any
	var srv *httptest.Server
	srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		var body map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		bodies = append(bodies, body)
		answer, ok := answers[r.URL.Query().Get("rest_route")]
		if !ok {
			t.Errorf("unexpected route %q", r.URL.Query().Get("rest_route"))
		}
		w.Write([]byte(strings.ReplaceAll(answer, "%s", srv.URL+"/wpsync-staging-0123456789ab")))
	}))
	t.Cleanup(srv.Close)
	return srv, &bodies
}

func TestStagingCalls(t *testing.T) {
	srv, bodies := stagingServer(t, map[string]string{
		"/wpsync/v1/staging/begin": `{"need":{"code_bytes":10,"db_bytes":20,"disk_free":null,"warnings":["w"]},"url":"%s",
"probe":{"deny_url":"%s/wpsync-probe.txt","rewrite_url":"%s/wpsync-probe-rewrite","files_url":"%s/wpsync-probe.log","token":"t"}}`,
		"/wpsync/v1/staging/step": `{"status":"ready","phase":"","done":0,"total":0,"error":"","error_code":"",
"result":{"replaced":{"stgabc123_posts":3},"url":"%s","prefix":"stgabc123_","anonymized":true,"skipped_values":1,"files":9}}`,
		"/wpsync/v1/staging/status": `{"exists":true,"status":"locked","url":"%s","prefix":"stgabc123_","created":1,"copied_at":2,
"last_used":3,"anonymized":true,"db_bytes":4,"job":null,"error":"","pushes":[{"push_id":"p_20261005_0123456789ab","target":"staging","status":"confirmed"}]}`,
		"/wpsync/v1/staging/login": `{"url":"%s/?wpsync_login=` + testLoginToken + `","expires":5}`,
	})
	c, _ := newTestClient(srv.URL)
	copyURL := srv.URL + "/wpsync-staging-0123456789ab"

	begin, err := c.StagingBegin(StagingBeginRequest{Op: "create", Scope: &Scope{PlainPII: true}})
	if err != nil || *begin.Need.CodeBytes != 10 || begin.Need.DBBytes != 20 || begin.Need.DiskFree != nil ||
		begin.Need.Warnings[0] != "w" || begin.URL != copyURL || begin.Probe.Token != "t" || begin.Probe.FilesURL != copyURL+"/wpsync-probe.log" {
		t.Fatalf("begin = %+v, %v", begin, err)
	}
	if b := (*bodies)[0]; b["op"] != "create" || b["scope"].(map[string]any)["plain_pii"] != true {
		t.Errorf("begin body = %v", b)
	}
	if _, ok := (*bodies)[0]["dry"]; ok {
		t.Errorf("begin body carries dry: %v", (*bodies)[0])
	}
	if _, err := c.StagingBegin(StagingBeginRequest{Op: "refresh", Dry: true, Code: true}); err != nil {
		t.Fatal(err)
	}
	if b := (*bodies)[1]; b["dry"] != true || b["code"] != true || b["op"] != "refresh" {
		t.Errorf("dry begin body = %v", b)
	}

	step, err := c.StagingStep("ok")
	if err != nil || step.Status != StagingReady || !step.Finished() || step.Result.Replaced["stgabc123_posts"] != 3 ||
		step.Result.SkippedValues != 1 || step.Result.Files != 9 || !step.Result.Anonymized || step.Result.Prefix != "stgabc123_" {
		t.Fatalf("step = %+v, %v", step, err)
	}
	if b := (*bodies)[2]; b["probe"] != "ok" {
		t.Errorf("step body = %v", b)
	}
	if _, err := c.StagingStep(""); err != nil || len((*bodies)[3]) != 0 {
		t.Errorf("step without probe sends %v, %v", (*bodies)[3], err)
	}

	st, err := c.StagingStatus()
	if err != nil || !st.Exists || st.Status != StagingLocked || st.Job != nil || st.URL != copyURL || st.CopiedAt != 2 ||
		st.DBBytes != 4 || len(st.Pushes) != 1 || st.Pushes[0].Target != "staging" {
		t.Errorf("status = %+v, %v", st, err)
	}

	login, err := c.StagingLogin()
	if err != nil || login.Expires != 5 || login.URL != copyURL+"/?wpsync_login="+testLoginToken {
		t.Errorf("login = %v, %v", login.URL, err)
	}
}

// Was der Agent tatsächlich liefert, wenn es nichts zu berichten gibt (Rest.php, Staging.php).
func TestStagingAnswersWithoutCopy(t *testing.T) {
	srv, _ := stagingServer(t, map[string]string{
		"/wpsync/v1/staging/begin":  `{"need":null,"probe":null,"url":"%s"}`,
		"/wpsync/v1/staging/step":   `{"status":"deleted","phase":"","done":0,"total":0,"error":"","error_code":"","result":null}`,
		"/wpsync/v1/staging/status": `{"exists":false}`,
	})
	c, _ := newTestClient(srv.URL)

	begin, err := c.StagingBegin(StagingBeginRequest{Op: "delete"})
	if err != nil || begin.Need != nil || begin.Probe != nil {
		t.Errorf("begin = %+v, %v", begin, err)
	}
	step, err := c.StagingStep("")
	if err != nil || step.Status != StagingDeleted || !step.Finished() || step.Result != nil {
		t.Errorf("step = %+v, %v", step, err)
	}
	st, err := c.StagingStatus()
	if err != nil || st.Exists || st.Status != "" || st.Pushes != nil {
		t.Errorf("status = %+v, %v", st, err)
	}
}

func TestStagingStepReportsRunningAndFailedJobs(t *testing.T) {
	srv, _ := stagingServer(t, map[string]string{
		"/wpsync/v1/staging/step": `{"status":"creating","phase":"tables","done":3,"total":12,"error":"","error_code":"","result":null}`,
	})
	c, _ := newTestClient(srv.URL)
	step, err := c.StagingStep("")
	if err != nil || step.Finished() || step.Phase != "tables" || step.Done != 3 || step.Total != 12 {
		t.Errorf("running = %+v, %v", step, err)
	}

	// Ein gescheiterter Job ist eine 200 mit status failed, kein HTTP-Fehler.
	srv, _ = stagingServer(t, map[string]string{
		"/wpsync/v1/staging/step": `{"status":"failed","phase":"","done":0,"total":0,"error":"kein Platz","error_code":"disk_full","result":null}`,
	})
	c, _ = newTestClient(srv.URL)
	step, err = c.StagingStep("")
	if err != nil || !step.Finished() || step.Status != StagingFailed || step.ErrorCode != StagingErrDiskFull || step.Error != "kein Platz" {
		t.Errorf("failed = %+v, %v", step, err)
	}
}

func TestStagingErrorsKeepTheAgentCode(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInsufficientStorage)
		w.Write([]byte(`{"code":"wpsync_staging_space","message":"zu wenig Platz","data":{"status":507}}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	_, err := c.StagingBegin(StagingBeginRequest{Op: "create"})
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 507 || apiErr.Code != "wpsync_staging_space" {
		t.Errorf("err = %v", err)
	}
}

// Ein manipulierter Agent darf die CLI nicht zu einem fremden Host schicken: Probe-URLs und der
// Login-Link werden abgerufen, der Login-Link bringt ein Zugangs-Cookie zurück.
func TestStagingRejectsURLsOutsideThePairedSite(t *testing.T) {
	probe := func(deny, rewrite, files string) string {
		return fmt.Sprintf(`{"need":null,"url":"%%s","probe":{"deny_url":%q,"rewrite_url":%q,"files_url":%q,"token":"t"}}`, deny, rewrite, files)
	}
	for name, answer := range map[string]string{
		"deny":    probe("https://evil.example/wpsync-probe.txt", "%s/r", "%s/f"),
		"rewrite": probe("%s/d", "//evil.example/r", "%s/f"),
		"files":   probe("%s/d", "%s/r", "http://169.254.169.254/latest/meta-data/"),
		"empty":   probe("%s/d", "", "%s/f"),
	} {
		srv, _ := stagingServer(t, map[string]string{"/wpsync/v1/staging/begin": answer})
		c, _ := newTestClient(srv.URL)
		begin, err := c.StagingBegin(StagingBeginRequest{Op: "create"})
		if !errors.Is(err, ErrForeignURL) || begin != nil {
			t.Errorf("%s: begin = %+v, %v", name, begin, err)
		}
	}

	for _, link := range []string{
		"https://evil.example/?wpsync_login=" + testLoginToken,
		"http://user@127.0.0.1/?wpsync_login=" + testLoginToken,
		"/?wpsync_login=" + testLoginToken,
		"",
	} {
		srv, _ := stagingServer(t, map[string]string{"/wpsync/v1/staging/login": fmt.Sprintf(`{"url":%q,"expires":5}`, link)})
		c, _ := newTestClient(srv.URL)
		login, err := c.StagingLogin()
		if !errors.Is(err, ErrForeignURL) || login != nil {
			t.Errorf("login %q = %+v, %v", link, login, err)
		}
		if err != nil && strings.Contains(err.Error(), testLoginToken) {
			t.Errorf("error leaks the login token: %v", err)
		}
	}
}

// Der Login-Link ist ein Geheimnis: wer die Antwort mit %v in ein Log oder eine Fehlermeldung
// schreibt, gibt den Token nicht preis.
func TestStagingLoginDoesNotPrintItsToken(t *testing.T) {
	login := &StagingLogin{URL: "https://kunde.de/wpsync-staging-0123456789ab/?wpsync_login=" + testLoginToken, Expires: 5}
	for _, out := range []string{
		fmt.Sprint(login), fmt.Sprint(*login), fmt.Sprintf("%+v", login), fmt.Sprintf("%#v", login), fmt.Sprintf("%#v", *login),
		fmt.Sprintf("%s", login), fmt.Sprintf("%q", login), fmt.Errorf("login %v", login).Error(), login.Redacted(),
	} {
		if strings.Contains(out, testLoginToken) || strings.Contains(out, testLoginToken[:16]) {
			t.Errorf("token printed: %s", out)
		}
	}
	if got := login.Redacted(); got != "https://kunde.de/wpsync-staging-0123456789ab/" {
		t.Errorf("Redacted = %q", got)
	}
	if got := (StagingLogin{URL: "::kaputt?wpsync_login=" + testLoginToken}).Redacted(); got != "" {
		t.Errorf("Redacted of a broken URL = %q", got)
	}
}

func TestEnvCarriesStaging(t *testing.T) {
	var env Env
	if err := json.Unmarshal([]byte(`{"agent_version":"0.5.0","staging":{"status":"ready","url":"https://example.test/wpsync-staging-0123456789ab","last_used":1791234567}}`), &env); err != nil {
		t.Fatal(err)
	}
	if env.Staging == nil || env.Staging.Status != StagingReady || env.Staging.LastUsed != 1791234567 {
		t.Errorf("staging = %+v", env.Staging)
	}
	// Ohne Kopie (null) und vor Agent 0.5.0 (kein Feld) gibt es nichts – und nichts wird ausgegeben.
	for _, raw := range []string{`{"agent_version":"0.5.0","staging":null}`, `{"agent_version":"0.4.0"}`} {
		var old Env
		if err := json.Unmarshal([]byte(raw), &old); err != nil || old.Staging != nil {
			t.Errorf("%s: staging = %+v, %v", raw, old.Staging, err)
		}
		out, _ := json.Marshal(old)
		if strings.Contains(string(out), "staging") {
			t.Errorf("env without a copy prints staging: %s", out)
		}
	}
}

func TestSameOrigin(t *testing.T) {
	cases := []struct {
		site, url string
		ok        bool
	}{
		{"https://kunde.de", "https://kunde.de/wpsync-staging-x/?wpsync_login=t", true},
		{"https://kunde.de", "https://KUNDE.de/x", true},
		{"https://Kunde.DE/", "https://kunde.de", true},
		{"https://kunde.de", "HTTPS://kunde.de/x", true},
		{"https://kunde.de", "https://kunde.de./x", true},
		{"https://kunde.de.", "https://kunde.de/x", true},
		{"https://kunde.de", "https://kunde.de:443/x", true},
		{"https://kunde.de:443", "https://kunde.de/x", true},
		{"http://kunde.de", "http://kunde.de:80/x", true},
		{"https://kunde.de/blog", "https://kunde.de/blog/wpsync-staging-x", true},
		{"http://src.ddev.site:8080", "http://src.ddev.site:8080/x", true},
		{"http://127.0.0.1:8080", "http://127.0.0.1:8080/x", true},
		{"https://[::1]:8443", "https://[::1]:8443/x", true},

		{"https://kunde.de", "http://kunde.de/x", false},
		{"http://kunde.de", "https://kunde.de/x", false},
		{"https://kunde.de", "https://kunde.de.evil.org/x", false},
		{"https://kunde.de", "https://evilkunde.de/x", false},
		{"https://kunde.de", "https://sub.kunde.de/x", false},
		{"https://kunde.de:8443", "https://kunde.de/x", false},
		{"https://kunde.de", "https://kunde.de:8443/x", false},
		{"https://kunde.de", "https://kunde.de:80/x", false},
		{"http://kunde.de", "http://kunde.de:443/x", false},
		{"https://kunde.de", "https://kunde.de../x", false},
		{"https://kunde.de", "/relative", false},
		{"https://kunde.de", "//kunde.de/x", false},
		{"https://kunde.de", "kunde.de/x", false},
		{"https://kunde.de", "", false},
		{"", "", false},
		{"", "https://kunde.de/x", false},
		{"kunde.de", "https://kunde.de/x", false},
		{"https://kunde.de", "https:kunde.de/x", false},
		{"https://kunde.de", "https:///x", false},
		{"https://kunde.de", "https://:443/x", false},

		// Userinfo: der Host steht hinter dem @.
		{"https://kunde.de", "https://kunde.de@evil.org/x", false},
		{"https://kunde.de", "https://kunde.de:443@evil.org/x", false},
		{"https://kunde.de", "https://user:pass@kunde.de/x", false},
		{"https://kunde.de", "https://@kunde.de/x", false},
		{"https://user@kunde.de", "https://kunde.de/x", false},

		// Backslashes liest ein Browser als Slash, Go nicht.
		{"https://kunde.de", `https://evil.org\@kunde.de/x`, false},
		{"https://kunde.de", `https://kunde.de\.evil.org/x`, false},
		{"https://kunde.de", `https:\\kunde.de/x`, false},
		{"https://kunde.de", `https://kunde.de/x\..\y`, false},

		// Steuerzeichen und Leerraum entfernt ein Browser stillschweigend.
		{"https://kunde.de", "https://kunde.de/x\n", false},
		{"https://kunde.de", "https://kun\tde.de/x", false},
		{"https://kunde.de", " https://kunde.de/x", false},
		{"https://kunde.de", "https://kunde.de/x y", false},
		{"https://kunde.de", "https://kunde.de/\x00", false},
		{"https://kunde.de", "https://kunde.de/\x7f", false},
		{"https://kunde.de", "https://kunde.de/‮", false},
		{"https://kunde.de", "https://kunde.de/\xff", false},

		// Andere Schemata, auch wenn beide Seiten übereinstimmen.
		{"https://kunde.de", "javascript://kunde.de/%0aalert(1)", false},
		{"https://kunde.de", "file://kunde.de/etc/passwd", false},
		{"ftp://kunde.de", "ftp://kunde.de/x", false},
		{"file:///etc", "file:///etc/passwd", false},

		// Kodierte Hosts und Punycode gelten nicht als derselbe Host.
		{"https://kunde.de", "https://kunde%2ede/x", false},
		{"https://kunde.de", "https://kunde。de/x", false},
		{"https://müller.de", "https://xn--mller-kva.de/x", false},
	}
	for _, c := range cases {
		if got := SameOrigin(c.site, c.url); got != c.ok {
			t.Errorf("SameOrigin(%q, %q) = %v", c.site, c.url, got)
		}
	}
}
