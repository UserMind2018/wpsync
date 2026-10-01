package profile

import (
	"reflect"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

func testSheet() *agentapi.Infosheet {
	return &agentapi.Infosheet{
		Env: agentapi.Env{TablePrefix: "wp_"},
		Tables: []agentapi.TableInfo{
			{Name: "wp_posts", Bytes: 1000, Class: "content", Essential: true},
			{Name: "wp_postmeta", Bytes: 2000, Class: "content", Essential: true},
			{Name: "wp_users", Bytes: 10, Class: "pii", Essential: true},
			{Name: "wp_comments", Bytes: 20, Class: "pii"},
			{Name: "wp_e_submissions_values", Bytes: 300, Class: "pii"},
			{Name: "wp_actionscheduler_logs", Bytes: 400, Class: "log"},
			{Name: "wp_yoast_indexable", Bytes: 50, Class: "cache"},
			{Name: "wp_wptc_processed_files", Bytes: 600, Class: "backup"},
			{Name: "wp_borlabs_cookie_cookies", Bytes: 5, Class: "config"},
			{Name: "wp_custom_thing", Bytes: 70, Class: "unknown"},
		},
		PostTypes: []agentapi.PostType{
			{Name: "revision", Bytes: 700, MetaBytes: 1500, Class: "log"},
			{Name: "jobpost_applicants", Bytes: 50, MetaBytes: 100, Class: "pii"},
			{Name: "page", Bytes: 200, MetaBytes: 300, Class: "content"},
		},
		Plugins: []agentapi.Component{
			{Slug: "elementor", Active: true, Bytes: 100},
			{Slug: "duplicator-pro", Active: false, Bytes: 40},
		},
		Themes: []agentapi.Component{
			{Slug: "astra", Active: true, Bytes: 30},
			{Slug: "twentytwentyfive", Active: false, Bytes: 20},
		},
		Uploads: []agentapi.UploadYear{
			{Year: "2023", Bytes: 700 << 20},
			{Year: "2024", Bytes: 600 << 20},
			{Year: "2025", Bytes: 500 << 20},
			{Year: "other", Bytes: 1 << 20},
		},
	}
}

func mustNew(t *testing.T, preset string) *Profile {
	t.Helper()
	p, err := New(testSheet(), preset)
	if err != nil {
		t.Fatal(err)
	}
	return p
}

func TestDefaultPreset(t *testing.T) {
	s := testSheet()
	p := mustNew(t, PresetNoTransactions)
	want := map[string]string{
		"wp_posts": ModeFull, "wp_postmeta": ModeFull, "wp_users": ModeFull,
		"wp_comments": ModeStructure, "wp_e_submissions_values": ModeStructure, "wp_actionscheduler_logs": ModeStructure,
		"wp_yoast_indexable": ModeStructure, "wp_wptc_processed_files": ModeStructure,
		"wp_borlabs_cookie_cookies": ModeFull, "wp_custom_thing": ModeFull,
	}
	if got := p.TableModes(s.Tables); !reflect.DeepEqual(got, want) {
		t.Errorf("modes = %v", got)
	}
	if got := p.ExcludedPostTypes(s.PostTypes); !reflect.DeepEqual(got, []string{"jobpost_applicants", "revision"}) {
		t.Errorf("post types = %v", got)
	}
	if got := p.ExcludedPlugins(s.Plugins); !reflect.DeepEqual(got, []string{"duplicator-pro"}) {
		t.Errorf("plugins = %v", got)
	}
	if got := p.ExcludedThemes(s.Themes); !reflect.DeepEqual(got, []string{"twentytwentyfive"}) {
		t.Errorf("themes = %v", got)
	}
	if p.Uploads != (Uploads{Since: "2025", Proxy: true}) {
		t.Errorf("uploads = %+v", p.Uploads)
	}
}

func TestContentAndFullPresets(t *testing.T) {
	s := testSheet()
	if m := mustNew(t, PresetContent).TableModes(s.Tables)["wp_custom_thing"]; m != ModeStructure {
		t.Errorf("nur-content: unknown table = %s", m)
	}
	full := mustNew(t, PresetFull)
	for name, m := range full.TableModes(s.Tables) {
		if m != ModeFull {
			t.Errorf("vollstaendig: %s = %s", name, m)
		}
	}
	if full.ExcludedPostTypes(s.PostTypes) != nil || full.ExcludedPlugins(s.Plugins) != nil || full.ExcludedThemes(s.Themes) != nil || full.Uploads.Since != "" {
		t.Errorf("vollstaendig must not exclude anything: %+v", full)
	}
	if _, err := New(s, "alles"); err == nil {
		t.Error("unknown preset accepted")
	}
}

func TestDefaultSince(t *testing.T) {
	cases := []struct {
		uploads []agentapi.UploadYear
		want    string
	}{
		{testSheet().Uploads, "2025"},
		{[]agentapi.UploadYear{{Year: "2023", Bytes: 1}, {Year: "2024", Bytes: 1}}, ""},
		{[]agentapi.UploadYear{{Year: "2020", Bytes: 5 << 30}}, ""},
		{nil, ""},
	}
	for _, c := range cases {
		if got := DefaultSince(c.uploads, UploadsBudget); got != c.want {
			t.Errorf("DefaultSince(%v) = %q, want %q", c.uploads, got, c.want)
		}
	}
}

func TestExplicitChoicesWin(t *testing.T) {
	s := testSheet()
	p := mustNew(t, PresetNoTransactions)
	p.Tables.Overrides = map[string]string{"wp_comments": ModeFull, "wp_custom_thing": ModeSkip}
	p.PostTypes.Include = []string{"revision"}
	p.Plugins.Exclude = []string{"elementor"}
	p.Themes.Exclude = []string{"astra"}

	modes := p.TableModes(s.Tables)
	if modes["wp_comments"] != ModeFull || modes["wp_custom_thing"] != ModeSkip {
		t.Errorf("modes = %v", modes)
	}
	if got := p.ExcludedPostTypes(s.PostTypes); !reflect.DeepEqual(got, []string{"jobpost_applicants"}) {
		t.Errorf("post types = %v", got)
	}
	if got := p.ExcludedPlugins(s.Plugins); !reflect.DeepEqual(got, []string{"duplicator-pro", "elementor"}) {
		t.Errorf("plugins = %v (an active plugin may be excluded, AC-15)", got)
	}
	if got := p.ExcludedThemes(s.Themes); !reflect.DeepEqual(got, []string{"twentytwentyfive"}) {
		t.Errorf("themes = %v (the active theme is never excluded)", got)
	}
}

func TestDeviations(t *testing.T) {
	s := testSheet()
	p := mustNew(t, PresetNoTransactions)
	if d := p.Deviations(s); !d.Empty() {
		t.Fatalf("fresh profile reports %+v", d)
	}
	s.Tables = append(s.Tables, agentapi.TableInfo{Name: "wp_new_log", Class: "log"})
	s.Plugins = append(s.Plugins, agentapi.Component{Slug: "new-plugin"})
	d := p.Deviations(s)
	if !reflect.DeepEqual(d.Tables, []string{"wp_new_log"}) || !reflect.DeepEqual(d.Plugins, []string{"new-plugin"}) || len(d.Lines()) != 2 {
		t.Fatalf("deviations = %+v", d)
	}
	p.MarkSeen(s)
	if !p.Deviations(s).Empty() {
		t.Fatal("MarkSeen must clear deviations")
	}
}

func TestApplyStoresOnlyDifferencesFromPreset(t *testing.T) {
	s := testSheet()
	p := mustNew(t, PresetNoTransactions)
	sel := p.Preselected(s)
	if !reflect.DeepEqual(sel.Tables, []string{"wp_borlabs_cookie_cookies", "wp_custom_thing"}) ||
		!reflect.DeepEqual(sel.PostTypes, []string{"page"}) || !reflect.DeepEqual(sel.Plugins, []string{"elementor"}) ||
		sel.Themes != nil || sel.UploadsSince != "2025" {
		t.Fatalf("preselected = %+v", sel)
	}

	p.Apply(s, sel)
	if len(p.Tables.Overrides) != 0 || !reflect.DeepEqual(p.PostTypes, Choice{}) || !reflect.DeepEqual(p.Plugins, Choice{}) || !reflect.DeepEqual(p.Themes, Choice{}) {
		t.Fatalf("unchanged selection stored choices: %+v", p)
	}

	sel.Tables = []string{"wp_custom_thing", "wp_comments"}
	sel.PostTypes = nil
	sel.Plugins = []string{"elementor", "duplicator-pro"}
	sel.Themes = []string{"twentytwentyfive"}
	sel.UploadsSince = "2024"
	p.Apply(s, sel)

	if want := map[string]string{"wp_comments": ModeFull, "wp_borlabs_cookie_cookies": ModeStructure}; !reflect.DeepEqual(p.Tables.Overrides, want) {
		t.Errorf("overrides = %v", p.Tables.Overrides)
	}
	if !reflect.DeepEqual(p.PostTypes, Choice{Exclude: []string{"page"}}) {
		t.Errorf("post types = %+v", p.PostTypes)
	}
	if !reflect.DeepEqual(p.Plugins, Choice{Include: []string{"duplicator-pro"}}) {
		t.Errorf("plugins = %+v", p.Plugins)
	}
	if !reflect.DeepEqual(p.Themes, Choice{Include: []string{"twentytwentyfive"}}) {
		t.Errorf("themes = %+v", p.Themes)
	}
	if p.Uploads.Since != "2024" {
		t.Errorf("since = %s", p.Uploads.Since)
	}
}

func TestEstimate(t *testing.T) {
	db, files := mustNew(t, PresetNoTransactions).Estimate(testSheet())
	// posts 1000-700-50, postmeta 2000-1500-100, users 10, borlabs 5, custom 70
	if db != 250+400+10+5+70 {
		t.Errorf("db = %d", db)
	}
	if want := int64(100 + 30 + 500<<20 + 1<<20); files != want {
		t.Errorf("files = %d, want %d", files, want)
	}
}

// AC-39: PII-Tabellen, die mit Daten und ohne Regel gezogen werden.
func TestPlainPII(t *testing.T) {
	s := testSheet()
	for i := range s.Tables {
		if s.Tables[i].Name == "wp_users" || s.Tables[i].Name == "wp_comments" {
			s.Tables[i].Anonymized = true
		}
	}
	if got := mustNew(t, PresetNoTransactions).PlainPII(s.Tables); len(got) != 0 {
		t.Errorf("standard preset pulls no uncovered pii table, got %v", got)
	}
	if got, want := mustNew(t, PresetFull).PlainPII(s.Tables), []string{"wp_e_submissions_values"}; !reflect.DeepEqual(got, want) {
		t.Errorf("PlainPII = %v, want %v", got, want)
	}
}
