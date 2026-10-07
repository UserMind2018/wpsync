package push

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

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

// M1: a symlink anywhere between the site folder and the unit directories would push another
// site's files.
func TestRunRefusesSymlinksOnTheWayToTheUnits(t *testing.T) {
	cases := map[string]string{
		"public":                       "public",
		"public/wp-content":            "public/wp-content",
		"public/wp-content/plugins":    "public/wp-content/plugins",
		"public/wp-content/themes":     "public/wp-content/themes",
		"public/wp-content/mu-plugins": "public/wp-content/mu-plugins",
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

// U19: a symlink as a unit is skipped with a hint when nothing is named; it is never read.
func TestRunSkipsUnitsThatAreSymlinks(t *testing.T) {
	cases := map[string]struct {
		link, target string
		err          error // what Run returns: the link was the only candidate, or the rest is pushed
	}{
		"changed unit": {"plugins/x", "plugins/x", ErrNothing},
		"new unit":     {"plugins/neu", "plugins/x", nil},
		"theme":        {"themes/t", "themes/t", nil},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			f := newFakeSite(t)
			o, siteDir, out := localSite(t, f)
			other := otherSite(t, siteDir)
			replaceWithLink(t, filepath.Join(siteDir, "public/wp-content", c.link), filepath.Join(other, "public/wp-content", c.target))

			err := Run(o)
			if c.err == nil && err != nil || c.err != nil && !errors.Is(err, c.err) {
				t.Fatalf("err = %v\n%s", err, out)
			}
			if !strings.Contains(out.String(), "übersprungen: "+c.link+" – symbolischer Link, wird nie gepusht") {
				t.Errorf("no hint about the skipped link:\n%s", out)
			}
			if strings.Contains(out.String(), c.link+" fehlt lokal") {
				t.Errorf("a linked unit is not missing:\n%s", out)
			}
			for _, b := range f.begins {
				for _, u := range b.Units {
					if u.Path == c.link {
						t.Errorf("the linked unit went to the agent: %+v", u)
					}
				}
			}
			for _, data := range f.uploaded {
				if strings.Contains(data, "SECRET") || strings.Contains(data, "other site") {
					t.Fatal("content of the other site was uploaded")
				}
			}
		})
	}
}

// U19: a named unit that is a symlink stops the push before any request.
func TestRunRefusesANamedUnitThatIsASymlink(t *testing.T) {
	for _, named := range [][]string{{"plugins/neu"}, {"plugins/x", "wp-content/plugins/neu/"}} {
		t.Run(strings.Join(named, ","), func(t *testing.T) {
			f := newFakeSite(t)
			o, siteDir, out := localSite(t, f)
			other := otherSite(t, siteDir)
			replaceWithLink(t, filepath.Join(siteDir, "public/wp-content/plugins/neu"), filepath.Join(other, "public/wp-content/plugins/x"))
			o.Units = named

			err := Run(o)
			if !errors.Is(err, ErrSymlink) || !strings.Contains(err.Error(), "public/wp-content/plugins/neu") {
				t.Fatalf("err = %v\n%s", err, out)
			}
			if len(f.routes) != 0 {
				t.Errorf("no request may leave: %v", f.routes)
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

// Security-Audit F-1: wird die Datei zwischen Lstat und open gegen eine FIFO getauscht, blockiert
// open(2) ohne O_NONBLOCK für immer. openFile meldet ErrChanged und kehrt zurück.
func TestOpenFileSwappedForFifoDoesNotHang(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "a.php")
	if err := os.WriteFile(path, []byte("<?php"), 0o644); err != nil {
		t.Fatal(err)
	}
	info, _ := os.Stat(path)
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	testHookBeforeOpen = func() {
		os.Remove(path)
		if err := syscall.Mkfifo(path, 0o644); err != nil {
			t.Error(err)
		}
	}
	t.Cleanup(func() { testHookBeforeOpen = nil })
	done := make(chan error, 1)
	go func() {
		f, err := openFile(root, "plugins/x", "a.php", LocalFile{Size: info.Size(), MTime: info.ModTime().Unix()})
		if f != nil {
			f.Close()
		}
		done <- err
	}()
	select {
	case err := <-done:
		if !errors.Is(err, ErrChanged) {
			t.Fatalf("err = %v, want ErrChanged", err)
		}
	case <-time.After(10 * time.Second):
		if f, err := os.OpenFile(path, os.O_WRONLY|syscall.O_NONBLOCK, 0); err == nil {
			f.Close()
		}
		t.Fatal("openFile hangs on a FIFO")
	}
}
