package scan

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

const sheetJSON = `{"sheet":{"generated_at":1700000000,"duration":12,"steps":4,
"env":{"wp_version":"6.8.2","table_prefix":"wp_"},
"plugins":[{"slug":"elementor","active":true,"bytes":100}],
"themes":[{"slug":"astra","active":true,"bytes":30}],
"tables":[{"name":"wp_posts","bytes":100,"class":"content","essential":true}],
"post_types":[{"name":"page","count":2,"bytes":10,"class":"content"}],
"orphan_meta":{"rows":0,"bytes":0},"uploads":[],"findings":[]},
"job":{"running":false,"phase":"done","generated_at":1700000000}}`

func testClient(url string) *agentapi.Client {
	c := agentapi.New(url, "0123456789abcdef", "secret", 1000)
	c.Sleep = func(time.Duration) {}
	return c
}

// AC-7: aktuelles Infosheet → genau ein Request.
func TestRunWithCurrentSheetNeedsOneRequest(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if route := r.URL.Query().Get("rest_route"); route != "/wpsync/v1/infosheet" {
			t.Errorf("unexpected request %s", route)
		}
		w.Write([]byte(sheetJSON))
	}))
	defer srv.Close()
	c := testClient(srv.URL)
	current := &profile.Profile{Preset: profile.PresetNoTransactions}
	var out bytes.Buffer
	p, err := Run(Options{Client: c, Current: current, Out: &out, Now: time.Unix(1700003600, 0)})
	if err != nil {
		t.Fatal(err)
	}
	if p != current || c.Stats.Requests != 1 || !strings.Contains(out.String(), "Requests: 1\n") {
		t.Fatalf("profile = %p (want %p), requests = %d\n%s", p, current, c.Stats.Requests, out.String())
	}
}

// AC-9: ohne Infosheet (WP-Cron läuft nicht) erzeugt der Scan es selbst, mit sichtbarem Fortschritt.
func TestRunRefreshesMissingSheet(t *testing.T) {
	var sheetCalls, refreshCalls int
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/infosheet":
			sheetCalls++
			if sheetCalls == 1 {
				w.Write([]byte(`{"sheet":null,"job":{"running":false,"phase":"done"}}`))
				return
			}
			w.Write([]byte(sheetJSON))
		case "/wpsync/v1/infosheet/refresh":
			refreshCalls++
			var body map[string]any
			json.NewDecoder(r.Body).Decode(&body)
			if (refreshCalls == 1) != (body["start"] == true) {
				t.Errorf("call %d: start = %v", refreshCalls, body["start"])
			}
			fmt.Fprintf(w, `{"running":%t,"phase":"postmeta","done":%d,"total":60000}`, refreshCalls < 3, refreshCalls*20000)
		}
	}))
	defer srv.Close()
	var out bytes.Buffer
	p, err := Run(Options{
		Client: testClient(srv.URL), Out: &out, Now: time.Unix(1700000000, 0),
		Preset: profile.PresetNoTransactions, Adjust: Adjust{ExcludePlugins: []string{"elementor"}, UploadsSince: "2024"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if refreshCalls != 3 || sheetCalls != 2 {
		t.Errorf("refresh calls = %d, sheet calls = %d", refreshCalls, sheetCalls)
	}
	for _, want := range []string{"Infosheet wird erstellt", "Metadaten 20.000/60.000", "Pull-Profil"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("missing %q in\n%s", want, out.String())
		}
	}
	if p.Preset != profile.PresetNoTransactions || !slices.Contains(p.Plugins.Exclude, "elementor") || p.Uploads.Since != "2024" {
		t.Fatalf("profile = %+v", p)
	}
}

func TestRunWithoutTerminalNeedsPreset(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(sheetJSON)) }))
	defer srv.Close()
	_, err := Run(Options{Client: testClient(srv.URL), Out: &bytes.Buffer{}, Now: time.Now()})
	if err == nil || !strings.Contains(err.Error(), "--preset") {
		t.Fatalf("err = %v", err)
	}
}
