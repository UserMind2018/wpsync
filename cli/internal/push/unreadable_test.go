package push

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

// unreadable takes the read permission of a path below wp-content for the rest of the test.
func unreadable(t *testing.T, docroot, rel string) {
	t.Helper()
	if os.Geteuid() == 0 {
		t.Skip("root reads everything")
	}
	p := filepath.Join(docroot, "wp-content", filepath.FromSlash(rel))
	info, err := os.Stat(p)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(p, 0); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.Chmod(p, info.Mode().Perm()) })
}

// AC-130, P-O3: eine nicht lesbare Datei in einer geänderten Einheit stoppt den Push vor dem
// Begin mit dem Pfad relativ zum Docroot.
func TestUnreadableFileInAChangedUnitStopsBeforeTheBegin(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	unreadable(t, filepath.Join(siteDir, "public"), "plugins/x/inc/same.php")
	err := Run(o)
	var u *UnreadableError
	if !errors.As(err, &u) || !errors.Is(err, ErrNotReadable) || u.Path != "wp-content/plugins/x/inc/same.php" {
		t.Fatalf("err = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("the site was asked: %v", f.routes)
	}
}

// Ein nicht lesbarer Ordner in der geänderten Einheit ebenso.
func TestUnreadableFolderInAChangedUnitStopsBeforeTheBegin(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	unreadable(t, filepath.Join(siteDir, "public"), "plugins/x/inc")
	err := Run(o)
	var u *UnreadableError
	if !errors.As(err, &u) || u.Path != "wp-content/plugins/x/inc" {
		t.Fatalf("err = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("the site was asked: %v", f.routes)
	}
}

// Nicht lesbare Dateien und Ordner in einer unveränderten Einheit stören nicht: der Scan braucht
// dort nur stat, gelesen wird nur, was gepusht wird.
func TestUnreadableFilesOfAnUnchangedUnitDoNotMatter(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	docroot := filepath.Join(siteDir, "public")
	write(t, docroot, "themes/t/cache/x.log.php", "<?php // created by the site container", 1700000000)
	unreadable(t, docroot, "themes/t/style.css")
	unreadable(t, docroot, "themes/t/cache")
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if len(f.begins) != 2 || len(f.begins[1].Units) != 1 || f.begins[1].Units[0].Path != "plugins/x" {
		t.Errorf("begins = %+v", f.begins)
	}
}
