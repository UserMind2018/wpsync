package localgit

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// committedSite returns a site folder with one snapshot commit and its git dir.
func committedSite(t *testing.T) (site, docroot, gitDir string) {
	t.Helper()
	site = t.TempDir()
	docroot = filepath.Join(site, "public")
	gitDir = TreeGitDir(site)
	write(t, site, "public/wp-content/a.php")
	if err := CommitTree(gitDir, site, "public", "first", io.Discard); err != nil {
		t.Fatal(err)
	}
	return site, docroot, gitDir
}

// evilRepo writes a git dir into the docroot (site-writable) whose config and info/attributes
// run a clean filter that creates the returned marker.
func evilRepo(t *testing.T, docroot string) (dir, marker string) {
	t.Helper()
	dir = filepath.Join(docroot, "evil")
	for _, d := range []string{"info", "objects", "refs"} {
		os.MkdirAll(filepath.Join(dir, d), 0o755)
	}
	marker = filepath.Join(t.TempDir(), "PWNED")
	os.WriteFile(filepath.Join(dir, "config"), []byte(fmt.Sprintf("[core]\n\trepositoryformatversion = 0\n[filter \"x\"]\n\tclean = \"echo RAN >> %s; cat\"\n", marker)), 0o644)
	os.WriteFile(filepath.Join(dir, "info", "attributes"), []byte("* filter=x\n"), 0o644)
	return dir, marker
}

// Nach-Review K-2: eine Datei commondir in history.git lenkt git auf Config und info/attributes
// im Docroot. GIT_COMMON_DIR und die Allowlist der obersten Ebene verhindern die Ausführung.
func TestCommondirRunsNoFilterFromDocroot(t *testing.T) {
	site, docroot, gitDir := committedSite(t)
	_, marker := evilRepo(t, docroot)
	os.WriteFile(filepath.Join(gitDir, "commondir"), []byte("../../public/evil\n"), 0o644)
	write(t, site, "public/wp-content/b.php")
	if err := CommitTree(gitDir, site, "public", "second", io.Discard); err != nil {
		t.Fatalf("commit: %v", err)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("filter from the docroot ran")
	}
	if _, err := os.Lstat(filepath.Join(gitDir, "commondir")); !os.IsNotExist(err) {
		t.Error("commondir must be removed")
	}
}

// Auch ohne die Allowlist hält GIT_COMMON_DIR git im eigenen Repo.
func TestGitEnvPinsCommonDir(t *testing.T) {
	env := strings.Join(gitEnv("/x/history.git"), "\n")
	if !strings.Contains(env, "\nGIT_COMMON_DIR=/x/history.git") {
		t.Fatalf("GIT_COMMON_DIR missing:\n%s", env)
	}
}

// Fremde Einträge auf der obersten Ebene (worktrees, config.worktree, gitdir, unbekannte Dateien),
// fremde Dateien in info/ und objects/info/alternates werden entfernt; der Commit läuft weiter.
func TestForeignRepoEntriesAreRemoved(t *testing.T) {
	site, docroot, gitDir := committedSite(t)
	evil, marker := evilRepo(t, docroot)
	os.MkdirAll(filepath.Join(gitDir, "worktrees", "w"), 0o755)
	os.WriteFile(filepath.Join(gitDir, "worktrees", "w", "commondir"), []byte(evil), 0o644)
	os.WriteFile(filepath.Join(gitDir, "config.worktree"), []byte("[filter \"x\"]\n\tclean = touch "+marker+"\n"), 0o644)
	os.WriteFile(filepath.Join(gitDir, "gitdir"), []byte(evil), 0o644)
	os.WriteFile(filepath.Join(gitDir, "something"), nil, 0o644)
	os.MkdirAll(filepath.Join(gitDir, "info"), 0o700) // wpsync no longer writes info/exclude itself
	os.WriteFile(filepath.Join(gitDir, "info", "exclude"), []byte("# kept\n"), 0o600)
	os.WriteFile(filepath.Join(gitDir, "info", "grafts"), nil, 0o644)
	os.WriteFile(filepath.Join(gitDir, "objects", "info", "alternates"), []byte(filepath.Join(evil, "objects")+"\n"), 0o644)
	os.WriteFile(filepath.Join(gitDir, "objects", "info", "http-alternates"), []byte("http://x\n"), 0o644)
	write(t, site, "public/wp-content/b.php")
	if err := CommitTree(gitDir, site, "public", "second", io.Discard); err != nil {
		t.Fatalf("commit: %v", err)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("filter ran")
	}
	for _, rel := range []string{"worktrees", "config.worktree", "gitdir", "something", "info/grafts", "objects/info/alternates", "objects/info/http-alternates"} {
		if _, err := os.Lstat(filepath.Join(gitDir, filepath.FromSlash(rel))); !os.IsNotExist(err) {
			t.Errorf("%s still there", rel)
		}
	}
	if _, err := os.Stat(filepath.Join(gitDir, "info", "exclude")); err != nil {
		t.Errorf("info/exclude removed: %v", err)
	}
}

// Ein erlaubter Eintrag, der ein Symlink ist (z. B. info oder objects in den Docroot), bricht ab.
func TestSymlinkedRepoEntryAborts(t *testing.T) {
	for _, name := range []string{"info", "objects", "refs", "HEAD"} {
		t.Run(name, func(t *testing.T) {
			site, docroot, gitDir := committedSite(t)
			evil, _ := evilRepo(t, docroot)
			target := filepath.Join(gitDir, name)
			os.RemoveAll(target)
			os.Symlink(filepath.Join(evil, "info"), target)
			write(t, site, "public/wp-content/b.php")
			err := CommitTree(gitDir, site, "public", "second", io.Discard)
			if err == nil || !strings.Contains(err.Error(), "symbolischer Link") {
				t.Fatalf("err = %v", err)
			}
			if _, err := os.Stat(filepath.Join(evil, "info", "exclude")); err == nil {
				t.Fatal("wrote through the symlink")
			}
		})
	}
}
