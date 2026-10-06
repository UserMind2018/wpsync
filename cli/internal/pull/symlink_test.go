package pull

import (
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localgit"
)

// SEC-113 (Review K1): Site-Code legt im Docroot einen Symlink auf <slug>/.wpsync/history.git und
// eine .gitattributes an; die Quelle liefert wp-content/plugins/evil/config. Ein Pull darf
// history.git/config nicht überschreiben, und der nächste Schnappschuss führt keinen Filter aus.
func TestSymlinkIntoHistoryGitRunsNoFilter(t *testing.T) {
	slug := t.TempDir()
	docroot := filepath.Join(slug, "html")
	os.MkdirAll(filepath.Join(docroot, "wp-content", "plugins"), 0o755)
	os.WriteFile(filepath.Join(docroot, "wp-content", "plugins", "a.php"), []byte("x"), 0o644)
	gitDir := localgit.TreeGitDir(slug)
	if err := localgit.CommitTree(gitDir, slug, "html", "first", io.Discard); err != nil {
		t.Fatal(err)
	}
	before, _ := os.ReadFile(filepath.Join(gitDir, "config"))
	if err := os.Symlink(gitDir, filepath.Join(docroot, "wp-content", "plugins", "evil")); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(filepath.Join(docroot, "wp-content", ".gitattributes"), []byte("* filter=pwn\n"), 0o644)
	marker := filepath.Join(t.TempDir(), "pwned")
	cfg := "[core]\n\trepositoryformatversion = 0\n\tbare = false\n[filter \"pwn\"]\n\tclean = touch " + marker + " && cat\n"
	if err := writeFile(docroot, "wp-content/plugins/evil/config", strings.NewReader(cfg), int64(len(cfg)), 1700000000); err == nil {
		t.Fatal("writeFile through a symlink must fail")
	}
	if after, _ := os.ReadFile(filepath.Join(gitDir, "config")); string(after) != string(before) {
		t.Fatalf("history.git/config overwritten: %q", after)
	}
	os.WriteFile(filepath.Join(docroot, "wp-content", "plugins", "b.php"), []byte("y"), 0o644)
	if err := localgit.CommitTree(gitDir, slug, "html", "second", io.Discard); err != nil {
		t.Fatalf("commit: %v", err)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("clean filter from history.git/config ran in the wpsync process")
	}
}

// Ein Symlink im Pfad, der im Docroot bleibt, wird genauso abgelehnt; geschrieben wird nichts.
func TestWriteFileRefusesSymlinkInsideDocroot(t *testing.T) {
	docroot := t.TempDir()
	os.MkdirAll(filepath.Join(docroot, "wp-content", "themes", "real"), 0o755)
	os.Symlink(filepath.Join(docroot, "wp-content", "themes", "real"), filepath.Join(docroot, "wp-content", "plugins"))
	if err := writeFile(docroot, "wp-content/plugins/x.php", strings.NewReader("x"), 1, 1); err == nil {
		t.Fatal("writeFile through a symlink must fail")
	}
	if _, err := os.Lstat(filepath.Join(docroot, "wp-content", "themes", "real", "x.php")); !os.IsNotExist(err) {
		t.Fatal("wrote through the symlink")
	}
}

// Ist der Docroot selbst ein Symlink (Mac: public/ im DDEV-Mount), schreibt der Pull nicht dorthin.
func TestWriteFileRefusesSymlinkedDocroot(t *testing.T) {
	siteDir, outside := t.TempDir(), t.TempDir()
	docroot := filepath.Join(siteDir, "public")
	os.Symlink(outside, docroot)
	if err := writeFile(docroot, "wp-content/x.php", strings.NewReader("x"), 1, 1); err == nil {
		t.Fatal("writeFile into a symlinked docroot must fail")
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("wrote outside: %v", entries)
	}
}

func TestRemoveFilesSkipsSymlinkedDir(t *testing.T) {
	docroot, outside := t.TempDir(), t.TempDir()
	victim := filepath.Join(outside, "victim.php")
	os.WriteFile(victim, []byte("keep"), 0o644)
	os.MkdirAll(filepath.Join(docroot, "wp-content"), 0o755)
	os.Symlink(outside, filepath.Join(docroot, "wp-content", "plugins"))
	RemoveFiles(docroot, []string{"wp-content/plugins/victim.php"})
	if _, err := os.Stat(victim); err != nil {
		t.Fatal("RemoveFiles followed a symlink")
	}
}

func TestRemoveDropInsSkipsSymlinkedWPContent(t *testing.T) {
	docroot, outside := t.TempDir(), t.TempDir()
	victim := filepath.Join(outside, "object-cache.php")
	os.WriteFile(victim, []byte("keep"), 0o644)
	os.Symlink(outside, filepath.Join(docroot, "wp-content"))
	RemoveDropIns(docroot)
	if _, err := os.Stat(victim); err != nil {
		t.Fatal("RemoveDropIns followed a symlink")
	}
}

// Ein Symlink auf eine passende Datei zählt nicht als vorhanden – sonst bliebe er stehen.
func TestPresentLocallyIgnoresSymlinks(t *testing.T) {
	docroot, outside := t.TempDir(), t.TempDir()
	if err := writeFile(outside, "wp-content/x", strings.NewReader("abc"), 3, 1234); err != nil {
		t.Fatal(err)
	}
	os.MkdirAll(filepath.Join(docroot, "wp-content"), 0o755)
	os.Symlink(filepath.Join(outside, "wp-content", "x"), filepath.Join(docroot, "wp-content", "x"))
	os.Symlink(filepath.Join(outside, "wp-content"), filepath.Join(docroot, "wp-content", "d"))
	present := PresentLocally(docroot)
	for _, p := range []string{"wp-content/x", "wp-content/d/x"} {
		if present(agentapi.File{Path: p, Size: 3, MTime: 1234}) {
			t.Errorf("%s via symlink counts as present", p)
		}
	}
}

// Mac: .wpsync/ liegt im DDEV-Mount. Symlinks in der DB-Zwischenablage führen weder zum Schreiben
// noch zum Lesen außerhalb (ein Import würde fremde Dateien in die lokale DB tragen).
func TestTablesCacheRefusesSymlinks(t *testing.T) {
	siteDir, outside := t.TempDir(), t.TempDir()
	secret := filepath.Join(outside, "secret")
	os.WriteFile(secret, []byte("SECRET"), 0o600)
	dir := filepath.Join(siteDir, ".wpsync", "db", "tables")
	os.MkdirAll(dir, 0o755)
	os.Symlink(secret, filepath.Join(dir, "wp_posts.sql"))
	os.Symlink(secret, filepath.Join(dir, "wp_users.sql.part"))
	tables := []agentapi.Table{{Name: "wp_posts"}}
	if r, closeAll, err := ImportReader(dir, tables); err == nil {
		b, _ := io.ReadAll(r)
		closeAll()
		if strings.Contains(string(b), "SECRET") {
			t.Fatal("ImportReader read through a symlink")
		}
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	if err := storeTable(root, agentapi.Table{Name: "wp_users"}, strings.NewReader("X;")); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(secret); string(b) != "SECRET" {
		t.Fatalf("storeTable wrote through a symlink: %q", b)
	}

	link := t.TempDir()
	os.RemoveAll(filepath.Join(link, ".wpsync"))
	os.Symlink(outside, filepath.Join(link, ".wpsync"))
	if _, err := openTables(link); err == nil {
		t.Fatal("openTables must refuse a symlinked .wpsync")
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 1 {
		t.Fatalf("created outside: %v", entries)
	}
}

func TestBaselineSaveRefusesSymlinks(t *testing.T) {
	siteDir, outside := t.TempDir(), t.TempDir()
	victim := filepath.Join(outside, "victim")
	os.WriteFile(victim, []byte("keep"), 0o644)
	os.MkdirAll(filepath.Join(siteDir, ".wpsync"), 0o755)
	os.Symlink(victim, filepath.Join(siteDir, ".wpsync", "baseline.json.tmp"))
	os.Symlink(victim, filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if err := baseline.Save(siteDir, baseline.New("https://x")); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(victim); string(b) != "keep" {
		t.Fatalf("baseline.Save wrote through a symlink: %q", b)
	}
}
