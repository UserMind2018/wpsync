package safefs

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func openRoot(t *testing.T, dir string) *os.Root {
	t.Helper()
	r, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { r.Close() })
	return r
}

// Ein Symlink unterwegs – auch einer, der innerhalb des Baums bleibt – lässt das Schreiben scheitern.
func TestWriteFileRefusesSymlinkedParent(t *testing.T) {
	base, outside := t.TempDir(), t.TempDir()
	os.MkdirAll(filepath.Join(base, "a"), 0o755)
	if err := os.Symlink(outside, filepath.Join(base, "a", "evil")); err != nil {
		t.Fatal(err)
	}
	os.MkdirAll(filepath.Join(base, "inner"), 0o755)
	if err := os.Symlink(filepath.Join(base, "inner"), filepath.Join(base, "a", "inside")); err != nil {
		t.Fatal(err)
	}
	r := openRoot(t, base)
	for _, rel := range []string{"a/evil/config", "a/evil/x/y", "a/inside/config"} {
		err := WriteFile(r, rel, strings.NewReader("x"), 1, time.Unix(1, 0), 0o644)
		if !errors.Is(err, ErrSymlink) {
			t.Errorf("WriteFile(%s) = %v, want ErrSymlink", rel, err)
		}
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("wrote outside: %v", entries)
	}
	if entries, _ := os.ReadDir(filepath.Join(base, "inner")); len(entries) != 0 {
		t.Fatalf("wrote through an inner symlink: %v", entries)
	}
}

// Eine Datei, die ein Symlink ist, wird ersetzt – das Ziel bleibt unberührt.
func TestWriteFileReplacesSymlinkNotTarget(t *testing.T) {
	base, outside := t.TempDir(), t.TempDir()
	target := filepath.Join(outside, "victim")
	os.WriteFile(target, []byte("keep"), 0o644)
	os.Symlink(target, filepath.Join(base, "f"))
	os.Symlink(target, filepath.Join(base, "f.wpsync-tmp"))
	r := openRoot(t, base)
	if err := WriteFile(r, "f", strings.NewReader("new"), 3, time.Unix(1700000000, 0), 0o644); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(target); string(b) != "keep" {
		t.Fatalf("target changed: %q", b)
	}
	info, err := os.Lstat(filepath.Join(base, "f"))
	if err != nil || !info.Mode().IsRegular() || info.ModTime().Unix() != 1700000000 {
		t.Fatalf("f = %v, %v", info, err)
	}
}

func TestWriteFileSizeMismatchLeavesNothing(t *testing.T) {
	base := t.TempDir()
	r := openRoot(t, base)
	if err := WriteFile(r, "d/f", strings.NewReader("hi"), 5, time.Time{}, 0o644); err == nil {
		t.Fatal("short body must fail")
	}
	if _, err := os.Lstat(filepath.Join(base, "d", "f.wpsync-tmp")); !os.IsNotExist(err) {
		t.Fatal("tmp file left behind")
	}
}

func TestRemoveSkipsSymlinkedParent(t *testing.T) {
	base, outside := t.TempDir(), t.TempDir()
	victim := filepath.Join(outside, "victim")
	os.WriteFile(victim, []byte("keep"), 0o644)
	os.Symlink(outside, filepath.Join(base, "link"))
	r := openRoot(t, base)
	if err := Remove(r, "link/victim"); !errors.Is(err, ErrSymlink) {
		t.Fatalf("Remove = %v, want ErrSymlink", err)
	}
	if _, err := os.Stat(victim); err != nil {
		t.Fatal("victim removed")
	}
}

func TestLstatDoesNotFollow(t *testing.T) {
	base, outside := t.TempDir(), t.TempDir()
	os.WriteFile(filepath.Join(outside, "f"), []byte("abc"), 0o644)
	os.Symlink(outside, filepath.Join(base, "dir"))
	os.Symlink(filepath.Join(outside, "f"), filepath.Join(base, "file"))
	r := openRoot(t, base)
	if _, err := Lstat(r, "dir/f"); !errors.Is(err, ErrSymlink) {
		t.Errorf("Lstat(dir/f) = %v", err)
	}
	info, err := Lstat(r, "file")
	if err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Errorf("Lstat(file) = %v, %v", info, err)
	}
}

func TestOpenTreeRefusesSymlink(t *testing.T) {
	base, outside := t.TempDir(), t.TempDir()
	os.Symlink(outside, filepath.Join(base, ".wpsync"))
	if _, err := OpenTree(base, ".wpsync/db/tables"); !errors.Is(err, ErrSymlink) {
		t.Fatalf("OpenTree = %v, want ErrSymlink", err)
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("created outside: %v", entries)
	}
	os.Remove(filepath.Join(base, ".wpsync"))
	r, err := OpenTree(base, ".wpsync/db/tables")
	if err != nil {
		t.Fatal(err)
	}
	r.Close()
	if info, err := os.Stat(filepath.Join(base, ".wpsync", "db", "tables")); err != nil || !info.IsDir() {
		t.Fatalf("tables = %v, %v", info, err)
	}
}
