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
			w.Write([]byte(`{"env":{"table_prefix":"wp_"},"tables":[{"name":"wp_posts","checksum":"1"},{"name":"wp_e_submissions"},{"name":"wp_brand_new","checksum":"2"}],"files":[],"skipped":[],"next":null}`))
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
