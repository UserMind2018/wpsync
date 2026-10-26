package profile

import (
	"encoding/json"
	"os"
	"reflect"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

func TestScope(t *testing.T) {
	s := testSheet()
	got := mustNew(t, PresetNoTransactions).Scope(s)
	want := agentapi.Scope{
		Tables: map[string]string{
			"wp_comments": ModeStructure, "wp_e_submissions_values": ModeStructure, "wp_actionscheduler_logs": ModeStructure,
			"wp_yoast_indexable": ModeStructure, "wp_wptc_processed_files": ModeStructure,
		},
		ExcludePostTypes: []string{"jobpost_applicants", "revision"},
		ExcludePlugins:   []string{"duplicator-pro"},
		ExcludeThemes:    []string{"twentytwentyfive"},
		UploadsSince:     "2025",
	}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("scope = %+v\nwant   %+v", got, want)
	}
	if full := mustNew(t, PresetFull).Scope(s); !reflect.DeepEqual(full, agentapi.Scope{}) {
		t.Fatalf("vollstaendig must send an empty scope, got %+v", full)
	}
}

// Spiegel von Scope::excludesPath im Agent.
func TestInScope(t *testing.T) {
	s := agentapi.Scope{ExcludePlugins: []string{"drop", "single"}, ExcludeThemes: []string{"old"}, UploadsSince: "2020"}
	cases := map[string]bool{
		"wp-content/plugins/keep/k.php":          true,
		"wp-content/plugins/drop/d.php":          false,
		"wp-content/plugins/single.php":          false,
		"wp-content/plugins/index.php":           true,
		"wp-content/themes/old/style.css":        false,
		"wp-content/themes/astra/style.css":      true,
		"wp-content/uploads/2019/01/a.jpg":       false,
		"wp-content/uploads/2020/01/a.jpg":       true,
		"wp-content/uploads/elementor/css/x.css": true,
		"wp-content/uploads/2019":                true,
		"wp-content/object-cache.php":            true,
	}
	for path, want := range cases {
		if got := InScope(s, path); got != want {
			t.Errorf("InScope(%q) = %v, want %v", path, got, want)
		}
	}
}

func TestScopeCarriesExcludePaths(t *testing.T) {
	p := mustNew(t, PresetFull)
	p.Paths.Exclude = []string{"*.mp4", "uploads/videos/"}
	if got := p.Scope(testSheet()).ExcludePaths; !reflect.DeepEqual(got, []string{"*.mp4", "uploads/videos/"}) {
		t.Fatalf("ExcludePaths = %q", got)
	}
	p.Paths.Exclude = []string{}
	if got := p.Scope(testSheet()); !reflect.DeepEqual(got, agentapi.Scope{}) {
		t.Fatalf("an empty list must keep the scope empty, got %+v", got)
	}
}

func TestInScopeExcludePaths(t *testing.T) {
	s := agentapi.Scope{ExcludePaths: []string{"uploads/videos/", "*.MP4"}}
	cases := map[string]bool{
		"wp-content/uploads/videos/a.webm":     false,
		"wp-content/uploads/videosammlung/a.x": true,
		"wp-content/uploads/2024/a.mp4":        false,
		"wp-content/uploads/2024/a.mp4.txt":    true,
		"wp-admin/a.mp4":                       true, // outside wp-content: no exclusions (A1)
	}
	keep := Filter(s)
	for path, want := range cases {
		if got := keep(path); got != want {
			t.Errorf("Filter(%q) = %v, want %v", path, got, want)
		}
		if got := InScope(s, path); got != want {
			t.Errorf("InScope(%q) = %v, want %v", path, got, want)
		}
	}
}

// AC-266: InScope and the agent's Scope agree on the shared cases in pathmatch.json.
func TestInScopeMirrorsAgent(t *testing.T) {
	raw, err := os.ReadFile("../../../agent/tests/fixtures/pathmatch.json")
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		Scope struct {
			Scope agentapi.Scope `json:"scope"`
			Cases []struct {
				Path string `json:"path"`
				In   bool   `json:"in"`
			} `json:"cases"`
		} `json:"scope"`
	}
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	if len(f.Scope.Cases) == 0 || len(f.Scope.Scope.ExcludePaths) == 0 {
		t.Fatal("fixture has no scope cases")
	}
	for _, c := range f.Scope.Cases {
		if got := InScope(f.Scope.Scope, c.Path); got != c.In {
			t.Errorf("InScope(%q) = %v, want %v", c.Path, got, c.In)
		}
	}
}
