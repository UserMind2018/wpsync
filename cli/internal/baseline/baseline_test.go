package baseline

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadMissingReturnsEmpty(t *testing.T) {
	b, err := Load(t.TempDir())
	if err != nil || !b.Empty() {
		t.Fatalf("b = %+v, err = %v", b, err)
	}
}

func TestSaveLoadRoundTrip(t *testing.T) {
	dir := t.TempDir()
	b := New("https://kunde.de")
	b.Files["wp-content/a"] = FileStamp{Size: 1, MTime: 2}
	b.Tables["wp_posts"] = "123"
	if err := Save(dir, b); err != nil {
		t.Fatal(err)
	}
	got, err := Load(dir)
	if err != nil || got.Empty() || got.Files["wp-content/a"].MTime != 2 || got.Tables["wp_posts"] != "123" {
		t.Fatalf("got %+v, %v", got, err)
	}
}

func TestModeFallsBackToFullForOldBaselines(t *testing.T) {
	b := New("x")
	b.Tables["wp_posts"] = "1"
	b.Modes["wp_log"] = "structure"
	if b.Mode("wp_posts") != "full" || b.Mode("wp_log") != "structure" || b.Mode("wp_new") != "" {
		t.Fatalf("modes: posts=%q log=%q new=%q", b.Mode("wp_posts"), b.Mode("wp_log"), b.Mode("wp_new"))
	}
	dir := t.TempDir()
	if err := Save(dir, b); err != nil {
		t.Fatal(err)
	}
	loaded, err := Load(dir)
	if err != nil || loaded.Mode("wp_log") != "structure" {
		t.Fatalf("loaded = %+v, err = %v", loaded, err)
	}
}

// Nach-Review N-d: Load folgt keinem Symlink (Mac: .wpsync/ im DDEV-Mount).
func TestLoadRefusesSymlinks(t *testing.T) {
	siteDir, outside := t.TempDir(), t.TempDir()
	foreign := filepath.Join(outside, "b.json")
	os.WriteFile(foreign, []byte(`{"source":"https://fremd.example","pulled_at":"2026-01-01T00:00:00Z","files":{},"tables":{}}`), 0o644)
	os.MkdirAll(filepath.Join(siteDir, ".wpsync"), 0o755)
	os.Symlink(foreign, filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if b, err := Load(siteDir); err == nil {
		t.Fatalf("Load followed a symlink: %+v", b)
	}
	site2 := t.TempDir()
	os.Symlink(outside, filepath.Join(site2, ".wpsync"))
	os.Rename(foreign, filepath.Join(outside, "baseline.json"))
	if b, err := Load(site2); err == nil {
		t.Fatalf("Load through a symlinked .wpsync: %+v", b)
	}
	if b, err := Load(t.TempDir()); err != nil || !b.Empty() {
		t.Fatalf("missing baseline = %+v, %v", b, err)
	}
}
