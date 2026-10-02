package pull

import (
	"bytes"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

const prepareSheet = `{"sheet":{"env":{"table_prefix":"wp_"},
"tables":[{"name":"wp_posts","class":"content","essential":true},{"name":"wp_e_submissions","class":"pii"}%s],
"post_types":[{"name":"revision","class":"log"},{"name":"page","class":"content"}],
"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`

func prepareServer(t *testing.T, extraTable string, deltaBody *map[string]any) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/infosheet":
			extra := ""
			if extraTable != "" {
				extra = `,{"name":"` + extraTable + `","class":"log"}`
			}
			w.Write([]byte(strings.Replace(prepareSheet, "%s", extra, 1)))
		case "/wpsync/v1/delta":
			if deltaBody != nil {
				json.NewDecoder(r.Body).Decode(deltaBody)
			}
			w.Write([]byte(`{"env":{"table_prefix":"wp_","anon":"1.abcd1234"},"tables":[{"name":"wp_posts","checksum":"1"},{"name":"wp_e_submissions"},{"name":"wp_brand_new","checksum":"2"}],"files":[],"skipped":[],"next":null}`))
		default:
			t.Errorf("unexpected request %s", r.URL.Query().Get("rest_route"))
		}
	}))
}

func prepareProfile(t *testing.T) *profile.Profile {
	t.Helper()
	sheet := &agentapi.Infosheet{
		Env:       agentapi.Env{TablePrefix: "wp_"},
		Tables:    []agentapi.TableInfo{{Name: "wp_posts", Class: "content", Essential: true}, {Name: "wp_e_submissions", Class: "pii"}},
		PostTypes: []agentapi.PostType{{Name: "revision", Class: "log"}, {Name: "page", Class: "content"}},
	}
	p, err := profile.New(sheet, profile.PresetNoTransactions)
	if err != nil {
		t.Fatal(err)
	}
	return p
}

func quickClient(url string) *agentapi.Client {
	c := agentapi.New(url, "0123456789abcdef", "secret", 1000)
	c.Sleep = func(time.Duration) {}
	return c
}

func TestPrepareSendsScopeAndTagsTables(t *testing.T) {
	var body map[string]any
	srv := prepareServer(t, "", &body)
	defer srv.Close()
	var out bytes.Buffer
	o := Options{Site: sites.Site{URL: srv.URL, Profile: prepareProfile(t)}, Out: &out}

	p, err := prepare(quickClient(srv.URL), &o, true)
	if err != nil {
		t.Fatal(err)
	}
	modes := map[string]string{}
	for _, tb := range p.delta.Tables {
		modes[tb.Name] = tb.Mode
	}
	want := map[string]string{"wp_posts": "filtered:revision", "wp_e_submissions": profile.ModeStructure, "wp_brand_new": profile.ModeFull}
	for name, mode := range want {
		if modes[name] != mode {
			t.Errorf("%s mode = %q, want %q", name, modes[name], mode)
		}
	}
	scope, _ := body["scope"].(map[string]any)
	tables, _ := scope["tables"].(map[string]any)
	if tables["wp_e_submissions"] != profile.ModeStructure || !strings.Contains(out.String(), "wp_brand_new") {
		t.Errorf("scope = %v\n%s", scope, out.String())
	}
}

func TestPrepareAsksBeforeUsingAnOutdatedProfile(t *testing.T) {
	srv := prepareServer(t, "wp_new_log", nil)
	defer srv.Close()

	o := Options{Site: sites.Site{URL: srv.URL, Profile: prepareProfile(t)}, Out: &bytes.Buffer{}}
	if _, err := prepare(quickClient(srv.URL), &o, true); err == nil || !strings.Contains(err.Error(), "--yes") {
		t.Fatalf("without terminal: err = %v", err)
	}

	o.Confirm = func(string) bool { return false }
	if _, err := prepare(quickClient(srv.URL), &o, true); !errors.Is(err, ErrAborted) {
		t.Fatalf("declined: err = %v", err)
	}

	var saved *sites.Site
	o.Confirm = nil
	o.Yes = true
	o.SaveSite = func(s *sites.Site) error { saved = s; return nil }
	if _, err := prepare(quickClient(srv.URL), &o, true); err != nil {
		t.Fatal(err)
	}
	if saved == nil || !slices.Contains(saved.Profile.Seen.Tables, "wp_new_log") {
		t.Fatalf("confirmed deviation must be recorded, saved = %+v", saved)
	}
}

func TestPrepareWithoutProfile(t *testing.T) {
	o := Options{Out: &bytes.Buffer{}}
	if _, err := prepare(quickClient("http://127.0.0.1:1"), &o, true); !errors.Is(err, ErrNoProfile) {
		t.Fatalf("err = %v", err)
	}
}

// anonServer answers infosheet and delta for the anonymization tests and counts table requests.
func anonServer(t *testing.T, deltaEnv, deltaTables string, deltaBody *map[string]any, dataRequests *int) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},
"tables":[{"name":"wp_users","class":"pii","essential":true},{"name":"wp_forms","class":"pii"},{"name":"wp_orders","class":"content"}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			if deltaBody != nil {
				json.NewDecoder(r.Body).Decode(deltaBody)
			}
			w.Write([]byte(`{"env":` + deltaEnv + `,"tables":` + deltaTables + `,"files":[],"skipped":[],"next":null}`))
		default:
			*dataRequests++
		}
	}))
}

func anonProfile(t *testing.T) *profile.Profile {
	t.Helper()
	sheet := &agentapi.Infosheet{Tables: []agentapi.TableInfo{
		{Name: "wp_users", Class: "pii", Essential: true}, {Name: "wp_forms", Class: "pii"}, {Name: "wp_orders", Class: "content"},
	}}
	p, err := profile.New(sheet, profile.PresetFull)
	if err != nil {
		t.Fatal(err)
	}
	return p
}

const anonTables = `[{"name":"wp_users","checksum":"1","anonymized":true},{"name":"wp_forms","checksum":"2"},{"name":"wp_orders","checksum":"3"}]`

// AC-37, AC-39: pseudonymisierte Tabellen bekommen einen eigenen Modus-Schlüssel, PII ohne Regel wird genannt.
func TestPrepareTagsAnonymizedTablesAndNamesPlainPII(t *testing.T) {
	var body map[string]any
	var data int
	srv := anonServer(t, `{"table_prefix":"wp_","anon":"1.abcd1234"}`, anonTables, &body, &data)
	defer srv.Close()
	var out bytes.Buffer
	o := Options{Site: sites.Site{URL: srv.URL, Profile: anonProfile(t)}, Out: &out}

	p, err := prepare(quickClient(srv.URL), &o, true)
	if err != nil {
		t.Fatal(err)
	}
	modes := map[string]string{}
	for _, tb := range p.delta.Tables {
		modes[tb.Name] = tb.Mode
	}
	want := map[string]string{"wp_users": "anon:1.abcd1234+full", "wp_forms": profile.ModeFull, "wp_orders": profile.ModeFull}
	for name, mode := range want {
		if modes[name] != mode {
			t.Errorf("%s mode = %q, want %q", name, modes[name], mode)
		}
	}
	scope, _ := body["scope"].(map[string]any)
	if _, sent := scope["plain_pii"]; sent {
		t.Errorf("plain_pii must not be sent by default, scope = %v", scope)
	}
	got := out.String()
	if !strings.Contains(got, "keine Anonymisierungsregel") || !strings.Contains(got, "wp_forms") {
		t.Errorf("uncovered pii table not named:\n%s", got)
	}
	if strings.Contains(got, "wp_orders") || strings.Contains(got, "wp_users") {
		t.Errorf("only pii tables without a rule belong into the warning:\n%s", got)
	}
}

// AC-36: Ein Agent ohne Anonymisierung lieferte Klartext – abbrechen, bevor Tabellendaten fliessen.
func TestPrepareRefusesAgentsThatCannotAnonymize(t *testing.T) {
	var data int
	srv := anonServer(t, `{"table_prefix":"wp_"}`, `[{"name":"wp_users","checksum":"1"}]`, nil, &data)
	defer srv.Close()
	o := Options{Site: sites.Site{URL: srv.URL, Profile: anonProfile(t)}, Out: &bytes.Buffer{}}

	if _, err := prepare(quickClient(srv.URL), &o, true); !errors.Is(err, ErrAgentCannotAnonymize) {
		t.Fatalf("err = %v", err)
	}
	if data != 0 {
		t.Fatalf("%d table requests before the abort", data)
	}
}

// AC-38: --no-anonymize verlangt Klartext ausdrücklich und funktioniert auch mit altem Agent.
func TestPrepareWithNoAnonymizeAsksForPlainData(t *testing.T) {
	var body map[string]any
	var data int
	srv := anonServer(t, `{"table_prefix":"wp_"}`, `[{"name":"wp_users","checksum":"1"},{"name":"wp_orders","checksum":"3"}]`, &body, &data)
	defer srv.Close()
	var out bytes.Buffer
	o := Options{Site: sites.Site{URL: srv.URL, Profile: anonProfile(t)}, Out: &out, NoAnonymize: true}

	p, err := prepare(quickClient(srv.URL), &o, true)
	if err != nil {
		t.Fatal(err)
	}
	scope, _ := body["scope"].(map[string]any)
	if scope["plain_pii"] != true {
		t.Errorf("scope = %v", scope)
	}
	if p.delta.Tables[0].Mode != profile.ModeFull {
		t.Errorf("plain tables keep the plain mode key, got %q", p.delta.Tables[0].Mode)
	}
	if !strings.Contains(out.String(), "--no-anonymize") || !strings.Contains(out.String(), "wp_users") {
		t.Errorf("plain pull must name the pii tables:\n%s", out.String())
	}
}

// AC-38: Klartext nur nach ausdrücklicher Bestätigung – geprüft, bevor irgendetwas angefasst wird.
func TestRunAsksBeforePullingPlainPII(t *testing.T) {
	o := Options{
		Site:        sites.Site{Name: "kunde", URL: "http://127.0.0.1:1", Profile: &profile.Profile{}},
		SitesRoot:   t.TempDir(),
		Out:         &bytes.Buffer{},
		NoAnonymize: true,
	}
	if err := Run(o); !errors.Is(err, ErrPlainNeedsConfirmation) {
		t.Fatalf("without terminal and without --yes: err = %v", err)
	}
	var asked string
	o.Confirm = func(q string) bool { asked = q; return false }
	if err := Run(o); !errors.Is(err, ErrAborted) {
		t.Fatalf("declined: err = %v", err)
	}
	if !strings.Contains(strings.ToLower(asked), "klartext") {
		t.Errorf("question = %q", asked)
	}
}
