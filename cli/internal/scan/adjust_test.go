package scan

import (
	"bytes"
	"errors"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"

	"github.com/usermind/wpsync/internal/profile"
)

// --uploads-since alle: alle Upload-Jahre ziehen – Pflicht im Container-Modus ohne Uploads-Proxy.
func TestAdjustAllUploadsClearsSince(t *testing.T) {
	p := &profile.Profile{Uploads: profile.Uploads{Since: "2024", Proxy: true}}
	Adjust{AllUploads: true}.apply(p)
	if p.Uploads.Since != "" {
		t.Fatalf("since = %q", p.Uploads.Since)
	}
}

// W1: --table <name>=structure|skip, wiederholbar.
func TestParseTables(t *testing.T) {
	got, err := ParseTables([]string{"wp_wffilemods=skip", "wp_wfhits=structure", "wp_wffilemods=skip"})
	if err != nil {
		t.Fatal(err)
	}
	if want := map[string]string{"wp_wffilemods": "skip", "wp_wfhits": "structure"}; !reflect.DeepEqual(got, want) {
		t.Fatalf("tables = %v", got)
	}
	if got, err := ParseTables(nil); err != nil || got != nil {
		t.Fatalf("no flag: %v, %v", got, err)
	}
	for spec, want := range map[string]string{
		"wp_wffilemods":           "<tabelle>=structure|skip",
		"=skip":                   "<tabelle>=structure|skip",
		"wp_wffilemods=":          "structure oder skip",
		"wp_wffilemods=full":      "structure oder skip",
		"wp_wffilemods=Skip":      "structure oder skip",
		"wp_wffilemods=skip=skip": "structure oder skip",
	} {
		if _, err := ParseTables([]string{spec}); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%q: err = %v, want %q", spec, err, want)
		}
	}
	_, err = ParseTables([]string{"wp_wffilemods=skip", "wp_wfhits=skip", "wp_wffilemods=structure"})
	if err == nil || !strings.Contains(err.Error(), "wp_wffilemods") || !strings.Contains(err.Error(), "verschiedenen Modi") {
		t.Errorf("conflict: err = %v", err)
	}
}

func TestAdjustTablesBecomeOverrides(t *testing.T) {
	p := &profile.Profile{Preset: profile.PresetFull}
	Adjust{Tables: map[string]string{"wp_wffilemods": profile.ModeSkip}}.apply(p)
	if want := map[string]string{"wp_wffilemods": profile.ModeSkip}; !reflect.DeepEqual(p.Tables.Overrides, want) {
		t.Fatalf("overrides = %v", p.Tables.Overrides)
	}
	Adjust{}.apply(p)
	if len(p.Tables.Overrides) != 1 {
		t.Fatalf("overrides = %v", p.Tables.Overrides)
	}
}

const wordfenceSheet = `{"sheet":{"generated_at":1700000000,"env":{"table_prefix":"wp_"},
"plugins":[{"slug":"wordfence","active":true,"bytes":100}],"themes":[],
"tables":[{"name":"wp_posts","bytes":100,"class":"content","essential":true},
{"name":"wp_wffilemods","bytes":400000000,"class":"log","plugin":"wordfence"},
{"name":"wp_wfhits","bytes":5000,"class":"log","plugin":"wordfence"}],
"post_types":[],"orphan_meta":{},"uploads":[],"findings":[]},"job":{}}`

func runPreset(t *testing.T, a Adjust) (*profile.Profile, *agentapi.Infosheet, error) {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(wordfenceSheet)) }))
	defer srv.Close()
	var sheet *agentapi.Infosheet
	p, err := Run(Options{Client: testClient(srv.URL), Out: &bytes.Buffer{}, Now: time.Unix(1700000000, 0),
		Preset: profile.PresetFull, Adjust: a, OnSheet: func(s *agentapi.Infosheet) { sheet = s }})
	return p, sheet, err
}

// W1: eine Kern-Tabelle lässt sich nicht herabstufen – kein Profil.
func TestRunRefusesOverrideOfEssentialTable(t *testing.T) {
	p, _, err := runPreset(t, Adjust{Tables: map[string]string{"wp_posts": profile.ModeStructure, "wp_wfhits": profile.ModeSkip}})
	if !errors.Is(err, ErrEssentialTable) || !strings.Contains(err.Error(), "wp_posts") || strings.Contains(err.Error(), "wp_wfhits") || p != nil {
		t.Fatalf("profile = %v, err = %v", p, err)
	}
}

// W1: eine Tabelle, die das Infosheet nicht kennt, ist kein Fehler – sie wird gemeldet.
func TestRunKeepsOverrideOfUnknownTable(t *testing.T) {
	a := Adjust{Tables: map[string]string{"wp_zzz": profile.ModeSkip, "wp_aaa": profile.ModeStructure, "wp_wffilemods": profile.ModeSkip}}
	p, sheet, err := runPreset(t, a)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(p.Tables.Overrides, a.Tables) {
		t.Errorf("overrides = %v", p.Tables.Overrides)
	}
	if got := a.UnknownTables(sheet); !reflect.DeepEqual(got, []string{"wp_aaa", "wp_zzz"}) {
		t.Errorf("unknown = %v", got)
	}
	if got := (Adjust{}).UnknownTables(sheet); got != nil {
		t.Errorf("unknown without --table = %v", got)
	}
}

// W1 mit --exclude-plugin: ein ausgeschlossenes Plugin lässt seine Tabellen beim Preset (hier:
// mit Daten); --table stuft sie herab. Beides gilt nebeneinander, keines hebt das andere auf.
func TestRunTableOverrideNextToExcludedPlugin(t *testing.T) {
	p, sheet, err := runPreset(t, Adjust{ExcludePlugins: []string{"wordfence"}, Tables: map[string]string{"wp_wffilemods": profile.ModeSkip}})
	if err != nil {
		t.Fatal(err)
	}
	modes := p.TableModes(sheet.Tables)
	if modes["wp_wffilemods"] != profile.ModeSkip || modes["wp_wfhits"] != profile.ModeFull {
		t.Errorf("modes = %v", modes)
	}
	scope := p.Scope(sheet)
	if !reflect.DeepEqual(scope.ExcludePlugins, []string{"wordfence"}) || !reflect.DeepEqual(scope.Tables, map[string]string{"wp_wffilemods": profile.ModeSkip}) {
		t.Errorf("scope = %+v", scope)
	}
}
