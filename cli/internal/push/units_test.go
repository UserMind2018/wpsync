package push

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"slices"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/baseline"
)

// write creates a file below docroot/wp-content with a fixed mtime and returns its baseline stamp.
func write(t *testing.T, docroot, rel, content string, mtime int64) baseline.FileStamp {
	t.Helper()
	full := filepath.Join(docroot, "wp-content", filepath.FromSlash(rel))
	if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(full, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(full, time.Unix(mtime, 0), time.Unix(mtime, 0)); err != nil {
		t.Fatal(err)
	}
	return baseline.FileStamp{Size: int64(len(content)), MTime: mtime}
}

// pulled writes a file and records it in the baseline, as a pull does.
func pulled(t *testing.T, docroot string, b *baseline.Baseline, rel, content string) {
	t.Helper()
	b.Files["wp-content/"+rel] = write(t, docroot, rel, content, 1700000000)
}

func find(units []Unit, path string) *Unit {
	for i := range units {
		if units[i].Path == path {
			return &units[i]
		}
	}
	return nil
}

func TestValidUnit(t *testing.T) {
	for _, u := range []string{"plugins/mein-plugin", "themes/kunde-child", "mu-plugins", "plugins/woo.commerce_2"} {
		if !ValidUnit(u) {
			t.Errorf("%q should be valid", u)
		}
	}
	for _, u := range []string{"plugins/wpsync-agent", "plugins/WPSYNC-Agent", "plugins", "plugins/a/b", "plugins/..", "plugins/.x", "uploads/2026", "mu-plugins/x", ""} {
		if ValidUnit(u) {
			t.Errorf("%q should be invalid", u)
		}
	}
}

func TestUnitOf(t *testing.T) {
	cases := []struct{ path, unit, rel string }{
		{"wp-content/plugins/x/inc/a.php", "plugins/x", "inc/a.php"},
		{"wp-content/themes/t/style.css", "themes/t", "style.css"},
		{"wp-content/mu-plugins/loader.php", "mu-plugins", "loader.php"},
		{"wp-content/mu-plugins/lib/a.php", "mu-plugins", "lib/a.php"},
	}
	for _, c := range cases {
		unit, rel, ok := UnitOf(c.path)
		if !ok || unit != c.unit || rel != c.rel {
			t.Errorf("UnitOf(%q) = %q, %q, %v", c.path, unit, rel, ok)
		}
	}
	for _, path := range []string{"wp-content/plugins/hello.php", "wp-content/uploads/2026/a.jpg", "wp-content/plugins/wpsync-agent/a.php", "wp-config.php", "wp-content/index.php"} {
		if _, _, ok := UnitOf(path); ok {
			t.Errorf("UnitOf(%q) should not be a unit", path)
		}
	}
}

// U10, P11
func TestIgnored(t *testing.T) {
	ignored := map[string]string{
		"plugins/x":  ".DS_Store",
		"plugins/a":  "inc/.DS_Store",
		"plugins/b":  ".git/HEAD",
		"plugins/c":  "debug.log",
		"plugins/d":  ".env",
		"plugins/e":  ".env.local",
		"plugins/f":  "a.php.wpsync-tmp",
		"plugins/g":  ".htpasswd",
		"themes/t":   "node/.svn/entries",
		"mu-plugins": "00-local-mailguard.php",
	}
	for unit, rel := range ignored {
		if !Ignored(unit, rel, 10) {
			t.Errorf("%s/%s should be ignored", unit, rel)
		}
	}
	if !Ignored("mu-plugins", "wpsync-loader.php", 10) || !Ignored("plugins/x", "big.zip", 300<<20) {
		t.Error("protected or oversized file not ignored")
	}
	for _, rel := range []string{"main.php", "inc/api.php", "assets/schema.sql", "00-local-mailguard.php", "readme.txt"} {
		if Ignored("plugins/x", rel, 10) {
			t.Errorf("plugins/x/%s should be kept", rel)
		}
	}
}

func TestScanFindsChangedNewAndDeletedFiles(t *testing.T) {
	docroot := t.TempDir()
	base := baseline.New("https://kunde.de")
	pulled(t, docroot, base, "plugins/same/main.php", "<?php // same")
	pulled(t, docroot, base, "plugins/edit/main.php", "<?php\n/* Plugin Name: Edit\n * Version: 1.0 */")
	pulled(t, docroot, base, "plugins/edit/inc/gone.php", "<?php // gone")
	pulled(t, docroot, base, "plugins/edit/.DS_Store", "finder")
	pulled(t, docroot, base, "themes/t/style.css", "/* Theme Name: T\nVersion: 2.0 */")
	pulled(t, docroot, base, "plugins/removed/main.php", "<?php")
	pulled(t, docroot, base, "plugins/wpsync-agent/wpsync-agent.php", "<?php")
	pulled(t, docroot, base, "uploads/2026/a.jpg", "jpg")
	base.PulledAt = time.Now()

	write(t, docroot, "plugins/edit/main.php", "<?php\n/* Plugin Name: Edit\n * Version: 1.1 */", 1800000000)
	write(t, docroot, "plugins/edit/inc/new.php", "<?php // new", 1800000000)
	os.Remove(filepath.Join(docroot, "wp-content/plugins/edit/inc/gone.php"))
	os.Remove(filepath.Join(docroot, "wp-content/plugins/edit/.DS_Store"))
	os.RemoveAll(filepath.Join(docroot, "wp-content/plugins/removed"))
	write(t, docroot, "plugins/fresh/fresh.php", "<?php\n/* Plugin Name: Fresh */", 1800000000)
	write(t, docroot, "plugins/fresh/.git/HEAD", "ref", 1800000000)
	write(t, docroot, "plugins/hello.php", "<?php // single file plugin", 1800000000)
	write(t, docroot, "mu-plugins/00-local-mailguard.php", "", 1800000000)
	write(t, docroot, "themes/t/.DS_Store", "finder", 1800000000)
	os.Symlink("/etc/hosts", filepath.Join(docroot, "wp-content/plugins/edit/link.php"))

	units, deleted, err := Scan(docroot, base)
	if err != nil {
		t.Fatal(err)
	}
	var paths []string
	for _, u := range units {
		paths = append(paths, u.Path)
	}
	if !slices.Equal(paths, []string{"plugins/edit", "plugins/fresh"}) {
		t.Fatalf("changed units = %v", paths)
	}
	if !slices.Equal(deleted, []string{"plugins/removed"}) {
		t.Errorf("deleted = %v", deleted)
	}

	edit := find(units, "plugins/edit")
	if !slices.Equal(edit.Changed, []string{"inc/gone.php", "inc/new.php", "main.php"}) {
		t.Errorf("edit.Changed = %v", edit.Changed)
	}
	if edit.New || edit.Version != "1.1" || len(edit.Files) != 2 || len(edit.Base) != 3 {
		t.Errorf("edit = %+v", edit)
	}
	if _, ok := edit.Files["link.php"]; ok {
		t.Error("symlinks are never pushed")
	}

	fresh := find(units, "plugins/fresh")
	if !fresh.New || len(fresh.Files) != 1 || len(fresh.Base) != 0 {
		t.Errorf("fresh = %+v", fresh)
	}
}

func TestHashAndRequest(t *testing.T) {
	docroot := t.TempDir()
	base := baseline.New("https://kunde.de")
	pulled(t, docroot, base, "plugins/x/a.php", "old")
	write(t, docroot, "plugins/x/a.php", "<?php // a", 1800000000)
	base.PulledAt = time.Now()

	units, _, err := Scan(docroot, base)
	if err != nil || len(units) != 1 {
		t.Fatalf("units = %v, %v", units, err)
	}
	if err := units[0].Hash(docroot); err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256([]byte("<?php // a"))
	req := units[0].Request()
	file := req.Files["a.php"]
	if req.Path != "plugins/x" || file.SHA256 != hex.EncodeToString(sum[:]) || file.Size != 10 || file.MTime != 1800000000 {
		t.Errorf("files = %+v", req.Files)
	}
	if req.Base["a.php"].Size != 3 || req.Base["a.php"].MTime != 1700000000 {
		t.Errorf("base = %+v", req.Base)
	}
}

func TestVersion(t *testing.T) {
	docroot := t.TempDir()
	write(t, docroot, "plugins/p/a-readme.php", "<?php\n// Version: 9.9.9", 1)
	write(t, docroot, "plugins/p/main.php", "<?php\n/**\n * Plugin Name: P\n * Version:     1.4.0\n */", 1)
	write(t, docroot, "themes/t/style.css", "/*\nTheme Name: T\nVersion: 2.1\n*/", 1)
	content := filepath.Join(docroot, "wp-content")
	if got := Version(filepath.Join(content, "plugins/p"), "plugins/p"); got != "1.4.0" {
		t.Errorf("plugin version = %q", got)
	}
	if got := Version(filepath.Join(content, "themes/t"), "themes/t"); got != "2.1" {
		t.Errorf("theme version = %q", got)
	}
	if got := Version(filepath.Join(content, "missing"), "plugins/missing"); got != "" {
		t.Errorf("missing version = %q", got)
	}
}
