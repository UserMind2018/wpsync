package push

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/baseline"
)

func mustLoad(t *testing.T, siteDir string) *baseline.Baseline {
	t.Helper()
	base, err := baseline.Load(siteDir)
	if err != nil {
		t.Fatal(err)
	}
	return base
}

// otherSite creates a second pulled site next to the first one; its plugin x has a secret file.
func otherSite(t *testing.T, siteDir string) string {
	t.Helper()
	other := filepath.Join(filepath.Dir(siteDir), "andere")
	write(t, filepath.Join(other, "public"), "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */\n// SECRET", 1800000000)
	write(t, filepath.Join(other, "public"), "plugins/x/inc/same.php", "<?php // same", 1700000000)
	write(t, filepath.Join(other, "public"), "plugins/x/secret.php", "<?php // other site", 1800000000)
	write(t, filepath.Join(other, "public"), "themes/t/style.css", "/* Theme Name: T */", 1700000000)
	write(t, filepath.Join(other, "public"), "mu-plugins/secret.php", "<?php // other site", 1800000000)
	return other
}

// replaceWithLink swaps a directory or file of the site for a relative symlink to target.
func replaceWithLink(t *testing.T, path, target string) {
	t.Helper()
	if err := os.RemoveAll(path); err != nil {
		t.Fatal(err)
	}
	rel, err := filepath.Rel(filepath.Dir(path), target)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(rel, path); err != nil {
		t.Fatal(err)
	}
}

// M1: a symlink anywhere between the site folder and a unit would push another site's files.
func TestRunRefusesSymlinksOnTheWayToTheUnits(t *testing.T) {
	cases := map[string]string{
		"public":                        "public",
		"public/wp-content":             "public/wp-content",
		"public/wp-content/plugins":     "public/wp-content/plugins",
		"public/wp-content/themes":      "public/wp-content/themes",
		"public/wp-content/mu-plugins":  "public/wp-content/mu-plugins",
		"public/wp-content/plugins/x":   "public/wp-content/plugins/x",
		"public/wp-content/plugins/neu": "public/wp-content/plugins/x",
	}
	for link, target := range cases {
		t.Run(link, func(t *testing.T) {
			f := newFakeSite(t)
			o, siteDir, out := localSite(t, f)
			other := otherSite(t, siteDir)
			if strings.HasSuffix(link, "mu-plugins") {
				write(t, filepath.Join(siteDir, "public"), "mu-plugins/eigen.php", "<?php", 1700000000)
			}
			replaceWithLink(t, filepath.Join(siteDir, filepath.FromSlash(link)), filepath.Join(other, filepath.FromSlash(target)))

			err := Run(o)
			if !errors.Is(err, ErrSymlink) {
				t.Fatalf("err = %v\n%s", err, out)
			}
			if !strings.Contains(err.Error(), link) {
				t.Errorf("error does not name the link: %v", err)
			}
			if len(f.routes) != 0 || len(f.uploaded) != 0 {
				t.Errorf("no request may leave before the scan is clean: %v", f.routes)
			}
		})
	}
}

// M1: after the scan a file is swapped for a symlink to another site's file of the same size and mtime.
func TestRunStopsWhenAFileBecomesASymlinkAfterTheScan(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	other := otherSite(t, siteDir)
	main := filepath.Join(siteDir, "public/wp-content/plugins/x/main.php")
	o.Yes = false
	o.Confirm = func(string) bool {
		// "// edited" and "// SECRET" have the same length; mtime as in the scan
		replaceWithLink(t, main, filepath.Join(other, "public/wp-content/plugins/x/main.php"))
		return true
	}

	err := Run(o)
	if !errors.Is(err, ErrChanged) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	for _, data := range f.uploaded {
		if strings.Contains(data, "SECRET") {
			t.Fatal("content of the other site was uploaded")
		}
	}
	if f.committed {
		t.Error("nothing may be committed")
	}
}

// M1: the whole unit is swapped for a symlink to an identical copy after the scan.
func TestRunStopsWhenTheUnitBecomesASymlinkAfterTheScan(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	other := otherSite(t, siteDir)
	// the copy matches the scan in size and mtime, only the content differs
	unit := filepath.Join(siteDir, "public/wp-content/plugins/x")
	o.Yes = false
	o.Confirm = func(string) bool {
		replaceWithLink(t, unit, filepath.Join(other, "public/wp-content/plugins/x"))
		return true
	}

	err := Run(o)
	if !errors.Is(err, ErrSymlink) && !errors.Is(err, ErrChanged) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	for _, data := range f.uploaded {
		if strings.Contains(data, "SECRET") {
			t.Fatal("content of the other site was uploaded")
		}
	}
	if f.committed {
		t.Error("nothing may be committed")
	}
}

// M1: Hash reads only the file the scan saw.
func TestHashRefusesAFileSwappedForASymlink(t *testing.T) {
	f := newFakeSite(t)
	_, siteDir, _ := localSite(t, f)
	other := otherSite(t, siteDir)
	docroot := filepath.Join(siteDir, "public")
	base := mustLoad(t, siteDir)
	units, _, err := Scan(docroot, base)
	if err != nil || len(units) != 1 {
		t.Fatalf("units = %v, %v", units, err)
	}
	replaceWithLink(t, filepath.Join(docroot, "wp-content/plugins/x/main.php"), filepath.Join(other, "public/wp-content/plugins/x/main.php"))
	if err := units[0].Hash(docroot); !errors.Is(err, ErrChanged) {
		t.Fatalf("err = %v", err)
	}
}

// M1: a directory inside the unit swapped for a symlink after the scan must not lead out of the unit.
func TestHashRefusesADirectorySwappedForASymlink(t *testing.T) {
	f := newFakeSite(t)
	_, siteDir, _ := localSite(t, f)
	other := otherSite(t, siteDir)
	docroot := filepath.Join(siteDir, "public")
	write(t, docroot, "plugins/x/inc/new.php", "<?php // other site", 1800000000)
	base := mustLoad(t, siteDir)
	units, _, err := Scan(docroot, base)
	if err != nil || len(units) != 1 {
		t.Fatalf("units = %v, %v", units, err)
	}
	// other/plugins/x/secret.php has the size and mtime of inc/new.php
	os.Rename(filepath.Join(other, "public/wp-content/plugins/x/secret.php"), filepath.Join(other, "public/wp-content/plugins/x/new.php"))
	write(t, filepath.Join(other, "public"), "plugins/x/same.php", "<?php // same", 1700000000)
	replaceWithLink(t, filepath.Join(docroot, "wp-content/plugins/x/inc"), filepath.Join(other, "public/wp-content/plugins/x"))
	if err := units[0].Hash(docroot); err == nil {
		t.Fatal("hashed a file outside the unit")
	}
}
