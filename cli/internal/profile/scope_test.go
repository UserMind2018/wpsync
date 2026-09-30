package profile

import (
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
