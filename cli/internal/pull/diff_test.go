package pull

import (
	"reflect"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/profile"
)

func sp(s string) *string { return &s }

func TestDiffFiles(t *testing.T) {
	b := baseline.New("x")
	b.Files["wp-content/same"] = baseline.FileStamp{Size: 1, MTime: 1}
	b.Files["wp-content/changed"] = baseline.FileStamp{Size: 1, MTime: 1}
	b.Files["wp-content/deleted"] = baseline.FileStamp{Size: 1, MTime: 1}
	files := []agentapi.File{
		{Path: "wp-content/same", Size: 1, MTime: 1},
		{Path: "wp-content/changed", Size: 2, MTime: 1},
		{Path: "wp-content/new-present", Size: 5, MTime: 5},
		{Path: "wp-content/new-missing", Size: 5, MTime: 5},
	}
	present := func(f agentapi.File) bool { return f.Path == "wp-content/new-present" }

	changed, deleted := DiffFiles(files, b, present)

	var paths []string
	for _, f := range changed {
		paths = append(paths, f.Path)
	}
	if want := []string{"wp-content/changed", "wp-content/new-missing"}; !reflect.DeepEqual(paths, want) {
		t.Fatalf("changed = %v, want %v", paths, want)
	}
	if want := []string{"wp-content/deleted"}; !reflect.DeepEqual(deleted, want) {
		t.Fatalf("deleted = %v, want %v", deleted, want)
	}
}

func TestChangedTables(t *testing.T) {
	b := baseline.New("x")
	b.Tables["wp_same"] = "1"
	b.Tables["wp_changed"] = "1"
	tables := []agentapi.Table{
		{Name: "wp_same", Checksum: sp("1")},
		{Name: "wp_changed", Checksum: sp("2")},
		{Name: "wp_new", Checksum: sp("3")},
		{Name: "wp_nochecksum"},
	}
	var names []string
	for _, tb := range ChangedTables(tables, b) {
		names = append(names, tb.Name)
	}
	if want := []string{"wp_changed", "wp_new", "wp_nochecksum"}; !reflect.DeepEqual(names, want) {
		t.Fatalf("changed = %v, want %v", names, want)
	}
}

func TestChangedTablesRespectsModes(t *testing.T) {
	b := baseline.New("x")
	b.Tables["wp_posts"] = "1"
	b.Modes["wp_posts"] = profile.ModeFull
	b.Modes["wp_log"] = profile.ModeStructure
	b.Tables["wp_postmeta"] = "5"
	b.Modes["wp_postmeta"] = "filtered:revision"
	tables := []agentapi.Table{
		{Name: "wp_posts", Checksum: sp("1"), Mode: "filtered:revision"}, // Profil filtert jetzt → neu laden
		{Name: "wp_log", Mode: profile.ModeStructure},                    // nur Struktur, Modus gleich → nichts tun
		{Name: "wp_postmeta", Checksum: sp("5"), Mode: "filtered:revision"},
		{Name: "wp_new_log", Mode: profile.ModeStructure}, // neu → Struktur anlegen
	}
	var names []string
	for _, tb := range ChangedTables(tables, b) {
		names = append(names, tb.Name)
	}
	if want := []string{"wp_posts", "wp_new_log"}; !reflect.DeepEqual(names, want) {
		t.Fatalf("changed = %v, want %v", names, want)
	}
}

func TestTableKey(t *testing.T) {
	s := agentapi.Scope{ExcludePostTypes: []string{"iwp_log", "revision"}}
	cases := []struct{ mode, table, want string }{
		{profile.ModeFull, "wp_posts", "filtered:iwp_log,revision"},
		{profile.ModeFull, "wp_postmeta", "filtered:iwp_log,revision"},
		{profile.ModeFull, "wp_term_relationships", "filtered:iwp_log,revision"},
		{profile.ModeFull, "wp_options", profile.ModeFull},
		{profile.ModeStructure, "wp_comments", profile.ModeStructure},
	}
	for _, c := range cases {
		if got := TableKey(c.mode, s, "wp_", c.table); got != c.want {
			t.Errorf("TableKey(%s, %s) = %q, want %q", c.mode, c.table, got, c.want)
		}
	}
	if got := TableKey(profile.ModeFull, agentapi.Scope{}, "wp_", "wp_posts"); got != profile.ModeFull {
		t.Errorf("without post-type filter posts stay %q, got %q", profile.ModeFull, got)
	}
}
