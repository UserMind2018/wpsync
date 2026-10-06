package pull

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

func TestWriteFileSetsMTimeAndRejectsShortBody(t *testing.T) {
	docroot := t.TempDir()
	if err := writeFile(docroot, "wp-content/a/b.txt", strings.NewReader("hello"), 5, 1700000000); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(filepath.Join(docroot, "wp-content/a/b.txt"))
	if err != nil || info.ModTime().Unix() != 1700000000 {
		t.Fatalf("info = %v, err = %v", info, err)
	}
	if err := writeFile(docroot, "wp-content/short.txt", strings.NewReader("hi"), 5, 1); err == nil {
		t.Fatal("short body must fail")
	}
	if _, err := os.Stat(filepath.Join(docroot, "wp-content/short.txt")); !os.IsNotExist(err) {
		t.Fatal("no partial file may remain")
	}
}

func TestSafeJoinRejectsEscapes(t *testing.T) {
	for _, bad := range []string{"../etc/passwd", "wp-content/../../x", "wp-config.php", "/abs"} {
		if _, err := SafeJoin("/root", bad); err == nil {
			t.Errorf("SafeJoin(%q) must fail", bad)
		}
	}
	if p, err := SafeJoin("/root", "wp-content/x.php"); err != nil || p != "/root/wp-content/x.php" {
		t.Fatalf("p = %q, err = %v", p, err)
	}
}

func TestPresentLocally(t *testing.T) {
	docroot := t.TempDir()
	writeFile(docroot, "wp-content/x", strings.NewReader("abc"), 3, 1234)
	present := PresentLocally(docroot)
	if !present(agentapi.File{Path: "wp-content/x", Size: 3, MTime: 1234}) {
		t.Fatal("identical file must count as present")
	}
	if present(agentapi.File{Path: "wp-content/x", Size: 3, MTime: 9999}) {
		t.Fatal("different mtime must not count as present")
	}
}

func TestBundlesRespectLimit(t *testing.T) {
	files := []agentapi.File{{Path: "a", Size: 6}, {Path: "b", Size: 3}, {Path: "c", Size: 20}, {Path: "d", Size: 1}}
	got := bundles(files, 10)
	// a+b passen zusammen, c ist allein zu gross und bekommt ein eigenes Bündel, d beginnt ein neues
	if len(got) != 3 || len(got[0]) != 2 || len(got[1]) != 1 || len(got[2]) != 1 {
		t.Fatalf("bundles = %v", got)
	}
}

// W11: kein Segment .git/.svn/.hg (Ordner oder Gitfile) darf aus einem Pull in den Docroot.
func TestSafeJoinRejectsVCSPaths(t *testing.T) {
	for _, bad := range []string{"wp-content/plugins/a/.git/config", "wp-content/themes/t/.GIT", "wp-content/x/.svn/entries", "wp-content/x/.hg/hgrc", "wp-content/plugins/a/.git"} {
		if _, err := SafeJoin("/root", bad); err == nil {
			t.Errorf("SafeJoin(%q) must fail", bad)
		}
	}
	for _, good := range []string{"wp-content/plugins/a/.github/x.yml", "wp-content/plugins/a/.gitignore", "wp-content/plugins/a/.gitattributes", "wp-content/plugins/git/a.php"} {
		if _, err := SafeJoin("/root", good); err != nil {
			t.Errorf("SafeJoin(%q) = %v", good, err)
		}
	}
}

// Lokale VCS-Pfade überlebt jedes Löschen, auch wenn eine alte Baseline sie kennt.
func TestRemoveFilesKeepsLocalVCSPaths(t *testing.T) {
	docroot := t.TempDir()
	gitfile := filepath.Join(docroot, "wp-content", "plugins", "a", ".git")
	if err := os.MkdirAll(filepath.Dir(gitfile), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(gitfile, []byte("gitdir: ../x"), 0o644); err != nil {
		t.Fatal(err)
	}
	RemoveFiles(docroot, []string{"wp-content/plugins/a/.git"})
	if _, err := os.Stat(gitfile); err != nil {
		t.Fatalf("local gitfile removed: %v", err)
	}
}
