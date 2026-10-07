package localgit

import (
	"bytes"
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

func write(t *testing.T, root, rel string) {
	t.Helper()
	p := filepath.Join(root, rel)
	os.MkdirAll(filepath.Dir(p), 0o755)
	os.WriteFile(p, []byte(rel), 0o644)
}

// cleanEnv is the environment for git calls made by the tests themselves (attacker setup, assertions):
// no inherited GIT_*, no user or system config.
func cleanEnv() []string {
	var env []string
	for _, kv := range os.Environ() {
		if !strings.HasPrefix(kv, "GIT_") {
			env = append(env, kv)
		}
	}
	return append(env, "GIT_CONFIG_GLOBAL="+os.DevNull, "GIT_CONFIG_NOSYSTEM=1")
}

// hostGit runs git outside wpsync, e.g. as the attacker preparing a site or to inspect a repo.
func hostGit(t *testing.T, args ...string) string {
	t.Helper()
	cmd := exec.Command("git", args...)
	cmd.Env = cleanEnv()
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("git %v: %v: %s", args, err, out)
	}
	return string(out)
}

func snapCount(t *testing.T, root string) int {
	t.Helper()
	n, err := strconv.Atoi(strings.TrimSpace(hostGit(t, "--git-dir="+GitDir(root, "site"), "rev-list", "--count", "HEAD")))
	if err != nil {
		t.Fatal(err)
	}
	return n
}

// newSite creates <root>/site with one plugin file and returns root and site dir.
func newSite(t *testing.T) (string, string) {
	t.Helper()
	root := t.TempDir()
	dir := filepath.Join(root, "site")
	write(t, dir, "public/wp-content/plugins/a/a.php")
	return root, dir
}

// payload writes an executable script that appends to marker; with filter it also passes stdin through.
func payload(t *testing.T, marker, name string, filter bool) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), name)
	body := fmt.Sprintf("#!/bin/sh\necho \"%s\" >> \"%s\"\n", name, marker)
	if filter {
		body += "cat\n"
	}
	if err := os.WriteFile(p, []byte(body+"exit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	return p
}

func noMarker(t *testing.T, marker string) {
	t.Helper()
	if b, err := os.ReadFile(marker); err == nil {
		t.Fatalf("payload ran on the host: %q", b)
	}
}

func hook(t *testing.T, hooksDir, name, marker string) {
	t.Helper()
	os.MkdirAll(hooksDir, 0o755)
	src, err := os.ReadFile(payload(t, marker, name, false))
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(hooksDir, name), src, 0o755); err != nil {
		t.Fatal(err)
	}
}

// attackerRepo turns <dir>/.git into a repo as code in the container could (git init, config).
func attackerRepo(t *testing.T, dir string, config ...[2]string) {
	t.Helper()
	hostGit(t, "-C", dir, "init", "-q", "-b", "main")
	for _, kv := range config {
		hostGit(t, "-C", dir, "config", kv[0], kv[1])
	}
}

func TestCommitTracksOnlyCodeAndBaseline(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "site")
	for _, f := range []string{
		"public/wp-content/plugins/a/a.php",
		"public/wp-content/themes/t/style.css",
		"public/wp-content/uploads/2026/x.jpg",
		"public/wp-content/cache/borlabs-cookie/1/x.css",
		"public/wp-config.php",
		"public/wp-includes/version.php",
		".wpsync/baseline.json",
		".wpsync/db/tables/wp_posts.sql",
		".ddev/config.yaml",
	} {
		write(t, dir, f)
	}

	if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command("git", "--git-dir="+GitDir(root, "site"), "ls-tree", "-r", "--name-only", "HEAD").Output()
	if err != nil {
		t.Fatal(err)
	}
	got := strings.Fields(string(out))
	want := []string{".gitignore", ".wpsync/baseline.json", "public/wp-content/plugins/a/a.php", "public/wp-content/themes/t/style.css"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("tracked = %v, want %v", got, want)
	}

	if err := Commit(root, "site", "pull 2", io.Discard); err != nil {
		t.Fatalf("second commit without changes must succeed: %v", err)
	}
	log, _ := exec.Command("git", "--git-dir="+GitDir(root, "site"), "log", "--format=%s").Output()
	if strings.Count(string(log), "pull") != 2 {
		t.Fatalf("log = %s", log)
	}
}

// AC-1: the repo lives next to the site folder, 0700, and no <site>/.git is created.
func TestRepoOutsideSiteFolder(t *testing.T) {
	root, dir := newSite(t)
	if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
		t.Fatal(err)
	}
	g := GitDir(root, "site")
	if rel, err := filepath.Rel(dir, g); err != nil || !strings.HasPrefix(rel, "..") {
		t.Fatalf("GitDir %s lies inside the site folder %s", g, dir)
	}
	for _, p := range []string{filepath.Dir(g), g} {
		fi, err := os.Stat(p)
		if err != nil {
			t.Fatal(err)
		}
		if fi.Mode().Perm() != 0o700 {
			t.Fatalf("%s mode = %v, want 0700", p, fi.Mode().Perm())
		}
	}
	if _, err := os.Lstat(filepath.Join(dir, ".git")); !errors.Is(err, fs.ErrNotExist) {
		t.Fatalf("<site>/.git exists after Commit: %v", err)
	}
	if snapCount(t, root) != 1 {
		t.Fatal("no commit in the snapshot repo")
	}
}

// AC-2/AC-3: every mechanism that fires through <site>/.git or the work tree stays silent, both for a
// .git found on the first pull (moved aside) and for one that reappears later (only warned about).
func TestSiteControlledGitMechanismsDoNotRun(t *testing.T) {
	type prep func(t *testing.T, dir, marker string)
	inRepo := func(config ...[2]string) prep {
		return func(t *testing.T, dir, marker string) { attackerRepo(t, dir, config...) }
	}
	cases := []struct {
		name  string
		prep  prep
		later bool // also run after a clean first commit
	}{
		{"hooks in .git/hooks", func(t *testing.T, dir, marker string) {
			attackerRepo(t, dir)
			for _, h := range []string{"pre-commit", "prepare-commit-msg", "commit-msg", "post-commit", "post-index-change", "reference-transaction"} {
				hook(t, filepath.Join(dir, ".git", "hooks"), h, marker)
			}
		}, true},
		{"core.hooksPath into the work tree", func(t *testing.T, dir, marker string) {
			hook(t, filepath.Join(dir, "public/wp-content/uploads/h"), "pre-commit", marker)
			attackerRepo(t, dir, [2]string{"core.hooksPath", "public/wp-content/uploads/h"})
		}, true},
		{"core.fsmonitor", func(t *testing.T, dir, marker string) {
			inRepo([2]string{"core.fsmonitor", payload(t, marker, "fsmonitor", false)})(t, dir, marker)
		}, true},
		{"clean filter via .gitattributes", func(t *testing.T, dir, marker string) {
			write(t, dir, "public/wp-content/.gitattributes")
			os.WriteFile(filepath.Join(dir, "public/wp-content/.gitattributes"), []byte("*.php filter=x\n"), 0o644)
			inRepo([2]string{"filter.x.clean", payload(t, marker, "filter", true)})(t, dir, marker)
		}, true},
		{"clean filter via .git/info/attributes", func(t *testing.T, dir, marker string) {
			inRepo([2]string{"filter.x.clean", payload(t, marker, "filter-info", true)})(t, dir, marker)
			os.MkdirAll(filepath.Join(dir, ".git/info"), 0o755)
			os.WriteFile(filepath.Join(dir, ".git/info/attributes"), []byte("*.php filter=x\n"), 0o644)
		}, true},
		{"commit.gpgsign + gpg.program", func(t *testing.T, dir, marker string) {
			inRepo([2]string{"commit.gpgsign", "true"}, [2]string{"gpg.program", payload(t, marker, "gpg", false)})(t, dir, marker)
		}, true},
		{"include.path into the work tree", func(t *testing.T, dir, marker string) {
			cfg := fmt.Sprintf("[core]\n\tfsmonitor = %s\n", payload(t, marker, "include-fsmonitor", false))
			os.WriteFile(filepath.Join(dir, "public/wp-content/inc.cfg"), []byte(cfg), 0o644)
			inRepo([2]string{"include.path", "../public/wp-content/inc.cfg"})(t, dir, marker)
		}, true},
		{"AC-2 .git as gitfile", func(t *testing.T, dir, marker string) {
			attackerRepo(t, dir)
			hook(t, filepath.Join(dir, ".git", "hooks"), "pre-commit", marker)
			if err := os.Rename(filepath.Join(dir, ".git"), filepath.Join(dir, "public/wp-content/uploads-git")); err != nil {
				t.Fatal(err)
			}
			os.WriteFile(filepath.Join(dir, ".git"), []byte("gitdir: public/wp-content/uploads-git\n"), 0o644)
		}, true},
		{"AC-2 .git as symlink", func(t *testing.T, dir, marker string) {
			outside := filepath.Join(t.TempDir(), "repo")
			os.MkdirAll(outside, 0o755)
			attackerRepo(t, outside)
			hook(t, filepath.Join(outside, ".git", "hooks"), "pre-commit", marker)
			if err := os.Symlink(filepath.Join(outside, ".git"), filepath.Join(dir, ".git")); err != nil {
				t.Fatal(err)
			}
		}, true},
		{"AC-2 pre-created .git on the first pull", func(t *testing.T, dir, marker string) {
			attackerRepo(t, dir)
			hook(t, filepath.Join(dir, ".git", "hooks"), "pre-commit", marker)
		}, false},
	}
	for _, c := range cases {
		t.Run(c.name+"/first pull", func(t *testing.T) {
			root, dir := newSite(t)
			marker := filepath.Join(t.TempDir(), "marker")
			c.prep(t, dir, marker)
			if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
				t.Fatalf("Commit: %v", err)
			}
			noMarker(t, marker)
			if n := snapCount(t, root); n != 1 {
				t.Fatalf("snapshot commits = %d, want 1", n)
			}
		})
		if !c.later {
			continue
		}
		t.Run(c.name+"/reappeared", func(t *testing.T) {
			root, dir := newSite(t)
			marker := filepath.Join(t.TempDir(), "marker")
			if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
				t.Fatal(err)
			}
			c.prep(t, dir, marker)
			write(t, dir, "public/wp-content/plugins/a/b.php")
			if err := Commit(root, "site", "pull 2", io.Discard); err != nil {
				t.Fatalf("Commit: %v", err)
			}
			noMarker(t, marker)
			if n := snapCount(t, root); n != 2 {
				t.Fatalf("snapshot commits = %d, want 2", n)
			}
			if !strings.Contains(hostGit(t, "--git-dir="+GitDir(root, "site"), "ls-tree", "-r", "--name-only", "HEAD"), "plugins/a/b.php") {
				t.Fatal("new file missing in the snapshot")
			}
		})
	}
}

// AC-4: inherited GIT_* variables and the user's global config, ignore and attributes files have no effect.
func TestCommitIgnoresInheritedGitEnvironment(t *testing.T) {
	t.Run("GIT_DIR and GIT_WORK_TREE", func(t *testing.T) {
		root, _ := newSite(t)
		foreign := t.TempDir()
		write(t, foreign, "f.txt")
		attackerRepo(t, foreign)
		hostGit(t, "-C", foreign, "add", "-A")
		hostGit(t, "-C", foreign, "-c", "user.name=x", "-c", "user.email=x@example.example", "commit", "-q", "-m", "foreign")
		head := hostGit(t, "-C", foreign, "rev-parse", "HEAD")
		other := t.TempDir()
		write(t, other, "public/wp-content/plugins/other/other.php")
		t.Setenv("GIT_DIR", filepath.Join(foreign, ".git"))
		t.Setenv("GIT_WORK_TREE", other)
		if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
			t.Fatal(err)
		}
		os.Unsetenv("GIT_DIR")
		os.Unsetenv("GIT_WORK_TREE")
		if got := hostGit(t, "-C", foreign, "rev-parse", "HEAD"); got != head {
			t.Fatal("commit landed in the foreign repo")
		}
		files := hostGit(t, "--git-dir="+GitDir(root, "site"), "ls-tree", "-r", "--name-only", "HEAD")
		if !strings.Contains(files, "plugins/a/a.php") || strings.Contains(files, "other.php") {
			t.Fatalf("snapshot tracks the wrong work tree: %s", files)
		}
	})
	t.Run("GIT_INDEX_FILE", func(t *testing.T) {
		root, _ := newSite(t)
		idx := filepath.Join(t.TempDir(), "index")
		t.Setenv("GIT_INDEX_FILE", idx)
		if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
			t.Fatal(err)
		}
		if _, err := os.Stat(idx); err == nil {
			t.Fatal("git wrote the index named by the inherited GIT_INDEX_FILE")
		}
		if _, err := os.Stat(filepath.Join(GitDir(root, "site"), "index")); err == nil {
			t.Fatal("the snapshot writes no index – git never sees the site folder")
		}
	})
	t.Run("GIT_CONFIG_COUNT", func(t *testing.T) {
		root, _ := newSite(t)
		marker := filepath.Join(t.TempDir(), "marker")
		hooks := t.TempDir()
		hook(t, hooks, "pre-commit", marker)
		t.Setenv("GIT_CONFIG_COUNT", "1")
		t.Setenv("GIT_CONFIG_KEY_0", "core.hooksPath")
		t.Setenv("GIT_CONFIG_VALUE_0", hooks)
		if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
			t.Fatal(err)
		}
		noMarker(t, marker)
	})
	t.Run("GIT_CONFIG_PARAMETERS", func(t *testing.T) {
		root, _ := newSite(t)
		marker := filepath.Join(t.TempDir(), "marker")
		hooks := t.TempDir()
		hook(t, hooks, "pre-commit", marker)
		t.Setenv("GIT_CONFIG_PARAMETERS", "'core.hooksPath'='"+hooks+"'")
		if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
			t.Fatal(err)
		}
		noMarker(t, marker)
	})
	t.Run("global config in HOME and XDG_CONFIG_HOME", func(t *testing.T) {
		root, _ := newSite(t)
		marker := filepath.Join(t.TempDir(), "marker")
		home, xdg, hooks := t.TempDir(), t.TempDir(), t.TempDir()
		hook(t, hooks, "pre-commit", marker)
		cfg := fmt.Sprintf("[core]\n\thooksPath = %s\n[commit]\n\tgpgsign = true\n[gpg]\n\tprogram = %s\n", hooks, payload(t, marker, "gpg", false))
		os.WriteFile(filepath.Join(home, ".gitconfig"), []byte(cfg), 0o644)
		os.MkdirAll(filepath.Join(xdg, "git"), 0o755)
		os.WriteFile(filepath.Join(xdg, "git", "config"), []byte(cfg), 0o644)
		t.Setenv("HOME", home)
		t.Setenv("XDG_CONFIG_HOME", xdg)
		if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
			t.Fatal(err)
		}
		noMarker(t, marker)
	})
	t.Run("global ignore and attributes files", func(t *testing.T) {
		root, _ := newSite(t)
		marker := filepath.Join(t.TempDir(), "marker")
		xdg := t.TempDir()
		os.MkdirAll(filepath.Join(xdg, "git"), 0o755)
		os.WriteFile(filepath.Join(xdg, "git", "ignore"), []byte("*.php\n"), 0o644)
		os.WriteFile(filepath.Join(xdg, "git", "attributes"), []byte("*.php filter=x\n"), 0o644)
		// the filter config comes through the repo's own config only if something leaks; the attributes file
		// alone must not change the snapshot content
		t.Setenv("XDG_CONFIG_HOME", xdg)
		t.Setenv("HOME", t.TempDir())
		if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
			t.Fatal(err)
		}
		os.Unsetenv("XDG_CONFIG_HOME")
		if files := hostGit(t, "--git-dir="+GitDir(root, "site"), "ls-tree", "-r", "--name-only", "HEAD"); !strings.Contains(files, "plugins/a/a.php") {
			t.Fatalf("global ignore file dropped the PHP file: %s", files)
		}
		noMarker(t, marker)
	})
}

// treeHashes maps every path below dir (symlinks not followed) to its content or link target.
func treeHashes(t *testing.T, dir string) map[string]string {
	t.Helper()
	m := map[string]string{}
	err := filepath.WalkDir(dir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(dir, p)
		switch {
		case d.Type()&fs.ModeSymlink != 0:
			l, err := os.Readlink(p)
			m[rel] = "link:" + l
			return err
		case d.IsDir():
			m[rel] = "dir"
		default:
			b, err := os.ReadFile(p)
			m[rel] = fmt.Sprintf("%x", sha256.Sum256(b))
			return err
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return m
}

// AC-6: an existing <site>/.git is moved aside unchanged, without running git in it; nothing is carried over.
func TestExistingSiteGitIsMovedAside(t *testing.T) {
	root, dir := newSite(t)
	marker := filepath.Join(t.TempDir(), "marker")
	attackerRepo(t, dir,
		[2]string{"core.fsmonitor", payload(t, marker, "fsmonitor", false)},
		[2]string{"filter.x.clean", payload(t, marker, "filter", true)})
	os.MkdirAll(filepath.Join(dir, ".git/info"), 0o755)
	os.WriteFile(filepath.Join(dir, ".git/info/attributes"), []byte("*.php filter=x\n"), 0o644)
	// the user's own history in the old repo, written before the hooks exist
	cmd := exec.Command("git", "-C", dir, "-c", "core.fsmonitor=false", "-c", "filter.x.clean=cat", "add", "-A")
	cmd.Env = cleanEnv()
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("%v: %s", err, out)
	}
	hostGit(t, "-C", dir, "-c", "core.fsmonitor=false", "-c", "user.name=x", "-c", "user.email=x@example.example", "commit", "-q", "-m", "own")
	hook(t, filepath.Join(dir, ".git/hooks"), "pre-commit", marker)
	os.Remove(marker) // filter=cat above did not write, but be explicit
	before := treeHashes(t, filepath.Join(dir, ".git"))

	var out bytes.Buffer
	if err := Commit(root, "site", "pull 1", &out); err != nil {
		t.Fatal(err)
	}
	noMarker(t, marker)
	if _, err := os.Lstat(filepath.Join(dir, ".git")); !errors.Is(err, fs.ErrNotExist) {
		t.Fatalf("<site>/.git still there: %v", err)
	}
	moved, _ := filepath.Glob(filepath.Join(root, ".wpsync-git", "site.alt-*.git"))
	if len(moved) != 1 {
		t.Fatalf("moved repos = %v, want exactly one", moved)
	}
	if after := treeHashes(t, moved[0]); !reflect.DeepEqual(before, after) {
		t.Fatal("old repo changed while being moved")
	}
	if !strings.Contains(out.String(), "Bisheriges Site-Git verschoben nach "+moved[0]) {
		t.Fatalf("output does not name the target: %q", out.String())
	}
	g := GitDir(root, "site")
	if n := snapCount(t, root); n != 1 {
		t.Fatalf("new history has %d commits, want 1", n)
	}
	cfg := hostGit(t, "--git-dir="+g, "config", "--list", "--local")
	for _, k := range []string{"fsmonitor", "hookspath", "filter."} {
		if strings.Contains(strings.ToLower(cfg), k) {
			t.Fatalf("old config carried over: %s", cfg)
		}
	}
	if _, err := os.Stat(filepath.Join(g, "hooks", "pre-commit")); err == nil {
		t.Fatal("old hook carried over")
	}
	if _, err := os.Stat(filepath.Join(g, "info", "attributes")); err == nil {
		t.Fatal("old info/attributes carried over")
	}
}

// fakeGitOnPath makes every git call write to marker, so a test can prove that git did not run at all.
func fakeGitOnPath(t *testing.T, marker string) {
	t.Helper()
	bin := t.TempDir()
	os.WriteFile(filepath.Join(bin, "git"), []byte(fmt.Sprintf("#!/bin/sh\necho \"$@\" >> \"%s\"\n", marker)), 0o755)
	t.Setenv("PATH", bin)
}

func TestMoveAsideFailureStopsBeforeGit(t *testing.T) {
	t.Run("rename fails", func(t *testing.T) {
		root, dir := newSite(t)
		attackerRepo(t, dir)
		boom := errors.New("cross-device link")
		rename = func(string, string) error { return boom }
		t.Cleanup(func() { rename = os.Rename })
		marker := filepath.Join(t.TempDir(), "git-ran")
		fakeGitOnPath(t, marker)

		err := Commit(root, "site", "pull 1", io.Discard)
		if !errors.Is(err, boom) || !strings.Contains(err.Error(), "ließ sich nicht aus dem Site-Ordner verschieben") {
			t.Fatalf("err = %v", err)
		}
		noMarker(t, marker)
		if _, err := os.Stat(filepath.Join(GitDir(root, "site"), "HEAD")); err == nil {
			t.Fatal("snapshot repo initialised despite failed move")
		}
		if _, err := os.Stat(filepath.Join(dir, ".git", "HEAD")); err != nil {
			t.Fatal("old repo lost")
		}
	})
	t.Run("target exists", func(t *testing.T) {
		root, dir := newSite(t)
		attackerRepo(t, dir)
		os.MkdirAll(filepath.Join(root, ".wpsync-git"), 0o700)
		now := time.Now()
		for i := 0; i < 5; i++ {
			os.Mkdir(filepath.Join(root, ".wpsync-git", "site.alt-"+now.Add(time.Duration(i)*time.Second).Format("20060102-150405")+".git"), 0o700)
		}
		marker := filepath.Join(t.TempDir(), "git-ran")
		fakeGitOnPath(t, marker)

		err := Commit(root, "site", "pull 1", io.Discard)
		if err == nil || !strings.Contains(err.Error(), "existiert bereits") {
			t.Fatalf("err = %v", err)
		}
		noMarker(t, marker)
		if _, err := os.Stat(filepath.Join(GitDir(root, "site"), "HEAD")); err == nil {
			t.Fatal("snapshot repo initialised despite failed move")
		}
		if _, err := os.Stat(filepath.Join(dir, ".git", "HEAD")); err != nil {
			t.Fatal("old repo lost")
		}
	})
}

func hasRawControl(s string) bool {
	for _, r := range s {
		if (r < 0x20 && r != '\n') || (r >= 0x7f && r <= 0x9f) {
			return true
		}
	}
	return false
}

// AC-7: a .git that reappears after the switch is reported, not used, and the pull goes on.
func TestReappearedSiteGitWarns(t *testing.T) {
	t.Run("text", func(t *testing.T) {
		root, dir := newSite(t)
		if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
			t.Fatal(err)
		}
		os.Mkdir(filepath.Join(dir, ".git"), 0o755)
		var out bytes.Buffer
		if err := Commit(root, "site", "pull 2", &out); err != nil {
			t.Fatal(err)
		}
		for _, want := range []string{filepath.Join(dir, ".git"), "nicht von wpsync angelegt", "nicht benutzt", "auf diesem Mac ausführen"} {
			if !strings.Contains(out.String(), want) {
				t.Fatalf("warning lacks %q: %q", want, out.String())
			}
		}
		if n := snapCount(t, root); n != 2 {
			t.Fatalf("snapshot commits = %d, want 2", n)
		}
	})
	t.Run("no raw control characters", func(t *testing.T) {
		root := filepath.Join(t.TempDir(), "x\x1b[31m\a")
		dir := filepath.Join(root, "site")
		write(t, dir, "public/wp-content/plugins/a/a.php")
		os.Mkdir(filepath.Join(dir, ".git"), 0o755)
		var out bytes.Buffer
		if err := Commit(root, "site", "pull 1", &out); err != nil { // move-aside notice
			t.Fatal(err)
		}
		os.Mkdir(filepath.Join(dir, ".git"), 0o755)
		if err := Commit(root, "site", "pull 2", &out); err != nil { // warning
			t.Fatal(err)
		}
		if !strings.Contains(out.String(), "verschoben nach") || !strings.Contains(out.String(), "nicht benutzt") {
			t.Fatalf("output = %q", out.String())
		}
		if hasRawControl(out.String()) || !strings.Contains(out.String(), `\x1b`) {
			t.Fatalf("output not escaped: %q", out.String())
		}
	})
}

// embeddedRepo creates a committed repo below plugins/ whose hooks and fsmonitor write to marker.
func embeddedRepo(t *testing.T, dir, marker string) string {
	t.Helper()
	n := filepath.Join(dir, "public/wp-content/plugins/evil")
	write(t, n, "y.php")
	attackerRepo(t, n)
	hostGit(t, "-C", n, "add", "-A")
	hostGit(t, "-C", n, "-c", "user.name=x", "-c", "user.email=x@example.example", "commit", "-q", "-m", "n")
	for _, h := range []string{"pre-commit", "post-index-change"} {
		hook(t, filepath.Join(n, ".git/hooks"), h, marker)
	}
	hostGit(t, "-C", n, "config", "core.fsmonitor", payload(t, marker, "fsmonitor", false))
	return n
}

// AC-8: an embedded repo with a commit below plugins/ does not fail the commit.
func TestEmbeddedPluginRepoDoesNotBreakCommit(t *testing.T) {
	root, dir := newSite(t)
	embeddedRepo(t, dir, filepath.Join(t.TempDir(), "marker"))
	for i := 1; i <= 3; i++ {
		if err := Commit(root, "site", fmt.Sprintf("pull %d", i), io.Discard); err != nil {
			t.Fatalf("pull %d: %v", i, err)
		}
	}
	if c := snapCount(t, root); c != 3 {
		t.Fatalf("snapshot commits = %d, want 3", c)
	}
}

// Given/When/Then: the config and hooks of an embedded repo below plugins/ do not run either – also not on
// later pulls, when the gitlink is already in the index and git looks into the embedded repo.
func TestEmbeddedPluginRepoDoesNotRunItsConfig(t *testing.T) {
	root, dir := newSite(t)
	marker := filepath.Join(t.TempDir(), "marker")
	n := embeddedRepo(t, dir, marker)
	for i := 1; i <= 3; i++ {
		os.WriteFile(filepath.Join(n, "y.php"), []byte(fmt.Sprint(i)), 0o644)
		if err := Commit(root, "site", fmt.Sprintf("pull %d", i), io.Discard); err != nil {
			t.Fatalf("pull %d: %v", i, err)
		}
		noMarker(t, marker)
	}
}

// AC-9: errors keep the step and the cause, git output is escaped.
func TestCommitErrors(t *testing.T) {
	t.Run("git missing", func(t *testing.T) {
		root, _ := newSite(t)
		t.Setenv("PATH", "")
		err := Commit(root, "site", "pull 1", io.Discard)
		if !errors.Is(err, exec.ErrNotFound) || !strings.Contains(err.Error(), "init") {
			t.Fatalf("err = %v", err)
		}
	})
	t.Run("unreadable file is left out and reported escaped", func(t *testing.T) {
		if os.Geteuid() == 0 {
			t.Skip("root reads everything")
		}
		root, dir := newSite(t)
		n := filepath.Join(dir, "public/wp-content/plugins/x\x1b[31m")
		write(t, n, "z.php")
		os.Chmod(filepath.Join(n, "z.php"), 0)
		t.Cleanup(func() { os.Chmod(filepath.Join(n, "z.php"), 0o644) })
		var out bytes.Buffer
		if err := Commit(root, "site", "pull 1", &out); !errors.Is(err, ErrIncomplete) {
			t.Fatalf("an unreadable file is left out, the snapshot is saved: err = %v, want ErrIncomplete", err)
		}
		if !strings.Contains(out.String(), "z.php ist nicht lesbar") || hasRawControl(out.String()) {
			t.Fatalf("output = %q", out.String())
		}
		if strings.Contains(tracked(t, root), "z.php") || !strings.Contains(tracked(t, root), "plugins/a/a.php") {
			t.Fatalf("tracked:\n%s", tracked(t, root))
		}
	})
}

func tracked(t *testing.T, root string) string {
	t.Helper()
	return hostGit(t, "--git-dir="+GitDir(root, "site"), "ls-tree", "-r", "HEAD")
}

// Iteration 1 (1): every kind of nested .git is found, symlinks are not followed, the folder is excluded
// from the snapshot, reported, and info/exclude is rewritten without leftovers.
func TestNestedReposAreExcludedAndReported(t *testing.T) {
	root, dir := newSite(t)
	wpc := filepath.Join(dir, "public/wp-content")
	other := t.TempDir()
	hostGit(t, "-C", other, "init", "-q")
	write(t, wpc, "plugins/dir/d.php")
	attackerRepo(t, filepath.Join(wpc, "plugins/dir"))
	write(t, wpc, "plugins/dir/inner/i.php")
	attackerRepo(t, filepath.Join(wpc, "plugins/dir/inner"))
	write(t, wpc, "plugins/file/f.php")
	os.WriteFile(filepath.Join(wpc, "plugins/file/.git"), []byte("gitdir: "+filepath.Join(other, ".git")+"\n"), 0o644)
	write(t, wpc, "themes/link/l.php")
	os.Symlink(filepath.Join(other, ".git"), filepath.Join(wpc, "themes/link/.git"))
	// a symlinked folder whose target holds a repo: not followed, git stores the link itself
	hidden := t.TempDir()
	write(t, hidden, "h.php")
	attackerRepo(t, hidden)
	os.Symlink(hidden, filepath.Join(wpc, "plugins/linked"))

	want := []string{"public/wp-content/plugins/dir", "public/wp-content/plugins/file", "public/wp-content/themes/link"}

	var out bytes.Buffer
	if err := Commit(root, "site", "pull 1", &out); err != nil {
		t.Fatal(err)
	}
	ls := tracked(t, root)
	for _, rel := range want {
		if strings.Contains(ls, rel) {
			t.Fatalf("%s is in the snapshot:\n%s", rel, ls)
		}
		if !strings.Contains(out.String(), filepath.Join(dir, rel)+" hat ein eigenes .git") {
			t.Fatalf("no warning for %s:\n%s", rel, out.String())
		}
	}
	if !strings.Contains(ls, "public/wp-content/plugins/a/a.php") || !strings.Contains(ls, "120000") {
		t.Fatalf("regular file or symlink missing:\n%s", ls)
	}
	// the repos are gone on the next pull: their folders come back
	for _, p := range []string{"plugins/dir/.git", "plugins/dir/inner/.git", "plugins/file/.git", "themes/link/.git"} {
		os.RemoveAll(filepath.Join(wpc, p))
	}
	out.Reset()
	if err := Commit(root, "site", "pull 2", &out); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(out.String(), "eigenes .git") {
		t.Fatalf("stale warning:\n%s", out.String())
	}
	if ls := tracked(t, root); !strings.Contains(ls, "plugins/dir/inner/i.php") || !strings.Contains(ls, "plugins/file/f.php") {
		t.Fatalf("folders not back in the snapshot:\n%s", ls)
	}
}

// Iteration 1 (1): a .gitignore written by the site cannot re-include a nested repo.
func TestSiteGitignoreCannotReincludeNestedRepo(t *testing.T) {
	root, dir := newSite(t)
	marker := filepath.Join(t.TempDir(), "marker")
	embeddedRepo(t, dir, marker)
	os.WriteFile(filepath.Join(dir, "public/wp-content/plugins/.gitignore"), []byte("!evil/\n!/evil/\n"), 0o644)
	for i := 1; i <= 2; i++ {
		if err := Commit(root, "site", fmt.Sprintf("pull %d", i), io.Discard); err != nil {
			t.Fatal(err)
		}
		if ls := tracked(t, root); strings.Contains(ls, "plugins/evil") {
			t.Fatalf("pull %d: nested repo in the snapshot:\n%s", i, ls)
		}
		noMarker(t, marker)
	}
}

// Iteration 1 (2): a gitlink already in the index (e.g. from an earlier pull) is removed before add,
// without running anything of the embedded repo.
func TestGitlinkInIndexIsRemovedWithoutRunningIt(t *testing.T) {
	root, dir := newSite(t)
	if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
		t.Fatal(err)
	}
	marker := filepath.Join(t.TempDir(), "marker")
	n := embeddedRepo(t, dir, marker)
	// earlier state: gitlink staged and committed in the snapshot repo
	g := []string{"--git-dir=" + GitDir(root, "site"), "--work-tree=" + dir}
	hostGit(t, append(g, "add", "-f", "public/wp-content/plugins/evil")...)
	hostGit(t, append(g, "-c", "user.name=x", "-c", "user.email=x@example.example", "commit", "-q", "-m", "old")...)
	if !strings.Contains(tracked(t, root), "160000") {
		t.Fatal("setup: gitlink not in index")
	}
	os.Remove(marker)
	os.WriteFile(filepath.Join(n, "y.php"), []byte("changed"), 0o644)
	if err := Commit(root, "site", "pull 2", io.Discard); err != nil {
		t.Fatal(err)
	}
	noMarker(t, marker)
	if ls := tracked(t, root); strings.Contains(ls, "160000") || strings.Contains(ls, "plugins/evil") {
		t.Fatalf("gitlink still in the snapshot:\n%s", ls)
	}
}

// Iteration 1 (3): fsmonitor and hooks stay off even if the snapshot repo itself were tampered with.
func TestTamperedSnapshotRepoDoesNotRunHooksOrFsmonitor(t *testing.T) {
	root, _ := newSite(t)
	if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
		t.Fatal(err)
	}
	marker := filepath.Join(t.TempDir(), "marker")
	g := GitDir(root, "site")
	hostGit(t, "--git-dir="+g, "config", "core.fsmonitor", payload(t, marker, "fsmonitor", false))
	hostGit(t, "--git-dir="+g, "config", "core.hooksPath", filepath.Join(g, "hooks"))
	for _, h := range []string{"pre-commit", "post-commit", "post-index-change"} {
		hook(t, filepath.Join(g, "hooks"), h, marker)
	}
	if err := Commit(root, "site", "pull 2", io.Discard); err != nil {
		t.Fatal(err)
	}
	noMarker(t, marker)
}

// Iteration 1 (4) / SEC-133: a nested repo without a commit – directory or gitfile – no longer fails the snapshot.
func TestNestedRepoWithoutCommitDoesNotFailCommit(t *testing.T) {
	root, dir := newSite(t)
	wpc := filepath.Join(dir, "public/wp-content")
	write(t, wpc, "plugins/empty/e.php")
	attackerRepo(t, filepath.Join(wpc, "plugins/empty"))
	empty := t.TempDir()
	hostGit(t, "-C", empty, "init", "-q")
	write(t, wpc, "plugins/gitfile/g.php")
	os.WriteFile(filepath.Join(wpc, "plugins/gitfile/.git"), []byte("gitdir: "+filepath.Join(empty, ".git")+"\n"), 0o644)
	for i := 1; i <= 2; i++ {
		if err := Commit(root, "site", fmt.Sprintf("pull %d", i), io.Discard); err != nil {
			t.Fatalf("pull %d: %v", i, err)
		}
	}
	if c := snapCount(t, root); c != 2 {
		t.Fatalf("snapshot commits = %d, want 2", c)
	}
}

// Folder names with glob characters, quotes and line breaks reach the snapshot literally; a nested
// repo excludes only its own folder, not a look-alike.
func TestOddNamesAreSnapshottedLiterally(t *testing.T) {
	root, dir := newSite(t)
	odd := filepath.Join(dir, `public/wp-content/plugins/a*[b]? \x`)
	write(t, odd, "q.php")
	attackerRepo(t, odd)
	write(t, dir, "public/wp-content/plugins/aZZb/q.php")
	write(t, dir, `public/wp-content/plugins/a*[b]? \xy/q.php`)
	write(t, dir, "public/wp-content/plugins/\"quoted\"/q.php")
	write(t, dir, "public/wp-content/plugins/n\nl/q.php")
	if err := Commit(root, "site", "pull 1", io.Discard); err != nil {
		t.Fatal(err)
	}
	ls := hostGit(t, "--git-dir="+GitDir(root, "site"), "ls-tree", "-r", "-z", "--name-only", "HEAD") // -z: names unquoted
	for _, want := range []string{"plugins/aZZb/q.php", `a*[b]? \xy/q.php`, "plugins/\"quoted\"/q.php", "plugins/n\nl/q.php"} {
		if !strings.Contains(ls, want) {
			t.Errorf("%q missing:\n%q", want, ls)
		}
	}
	if strings.Contains(ls, `a*[b]? \x/`) {
		t.Fatalf("nested repo in the snapshot:\n%q", ls)
	}
}

// caseInsensitive reports whether dir's volume finds "X" for a file named "x" (APFS default).
func caseInsensitive(t *testing.T, dir string) bool {
	t.Helper()
	os.WriteFile(filepath.Join(dir, "case-probe"), nil, 0o644)
	_, err := os.Lstat(filepath.Join(dir, "CASE-PROBE"))
	os.Remove(filepath.Join(dir, "case-probe"))
	return err == nil
}

// filterRepo creates a committed repo below plugins/ with a clean filter and fsmonitor writing to marker,
// its git dir renamed to gitName. Returns the repo folder.
func filterRepo(t *testing.T, dir, marker, gitName string) string {
	t.Helper()
	n := filepath.Join(dir, "public/wp-content/plugins/evil")
	write(t, n, "y.php")
	attackerRepo(t, n)
	hostGit(t, "-C", n, "add", "-A")
	hostGit(t, "-C", n, "-c", "user.name=x", "-c", "user.email=x@example.example", "commit", "-q", "-m", "n")
	hostGit(t, "-C", n, "config", "filter.x.clean", payload(t, marker, "filter", true))
	hostGit(t, "-C", n, "config", "core.fsmonitor", payload(t, marker, "fsmonitor", false))
	os.WriteFile(filepath.Join(n, ".gitattributes"), []byte("* filter=x\n"), 0o644)
	if gitName != ".git" {
		// two steps: a case-only rename is a no-op on some volumes
		if err := os.Rename(filepath.Join(n, ".git"), filepath.Join(n, gitName+"tmp")); err != nil {
			t.Fatal(err)
		}
		if err := os.Rename(filepath.Join(n, gitName+"tmp"), filepath.Join(n, gitName)); err != nil {
			t.Fatal(err)
		}
	}
	return n
}

// touchSameSize rewrites y.php with equal size and an old mtime: git has to compare content inside the
// embedded repo, which runs its clean filter.
func touchSameSize(t *testing.T, n string, i int) {
	t.Helper()
	p := filepath.Join(n, "y.php")
	os.WriteFile(p, []byte(fmt.Sprintf("%05d", i)), 0o644)
	old := time.Now().Add(-365 * 24 * time.Hour)
	os.Chtimes(p, old, old)
}

// Iteration 2 (F1): on a case-insensitive volume git finds .GIT/.Git as a repo; it is excluded, reported,
// and its clean filter does not run on later pulls.
func TestUppercaseNestedRepoDoesNotRunItsFilter(t *testing.T) {
	for _, name := range []string{".GIT", ".Git"} {
		t.Run(name, func(t *testing.T) {
			root, dir := newSite(t)
			if !caseInsensitive(t, dir) {
				t.Skip("case-sensitive file system")
			}
			marker := filepath.Join(t.TempDir(), "marker")
			n := filterRepo(t, dir, marker, name)
			for i := 1; i <= 3; i++ {
				touchSameSize(t, n, i)
				var out bytes.Buffer
				if err := Commit(root, "site", fmt.Sprintf("pull %d", i), &out); err != nil {
					t.Fatalf("pull %d: %v", i, err)
				}
				noMarker(t, marker)
				if ls := tracked(t, root); strings.Contains(ls, "160000") || strings.Contains(ls, "plugins/evil") {
					t.Fatalf("pull %d: embedded repo in the snapshot:\n%s", i, ls)
				}
				if !strings.Contains(out.String(), "plugins/evil hat ein eigenes .git") {
					t.Fatalf("pull %d: no warning:\n%s", i, out.String())
				}
			}
		})
	}
}

// Iteration 2 (4) / SEC-133: a .GIT repo without a commit does not fail the snapshot either.
func TestUppercaseNestedRepoWithoutCommitDoesNotFailCommit(t *testing.T) {
	root, dir := newSite(t)
	if !caseInsensitive(t, dir) {
		t.Skip("case-sensitive file system")
	}
	n := filepath.Join(dir, "public/wp-content/plugins/empty")
	write(t, n, "e.php")
	attackerRepo(t, n)
	os.Rename(filepath.Join(n, ".git"), filepath.Join(n, ".GITtmp"))
	os.Rename(filepath.Join(n, ".GITtmp"), filepath.Join(n, ".GIT"))
	for i := 1; i <= 2; i++ {
		if err := Commit(root, "site", fmt.Sprintf("pull %d", i), io.Discard); err != nil {
			t.Fatalf("pull %d: %v", i, err)
		}
	}
}

// Iteration 2 (b): a folder the scan cannot read is reported, not silently skipped.
func TestUnreadableFolderIsReported(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root reads everything")
	}
	root, dir := newSite(t)
	locked := filepath.Join(dir, "public/wp-content/plugins/locked")
	write(t, locked, "l.php")
	os.Chmod(locked, 0)
	t.Cleanup(func() { os.Chmod(locked, 0o755) })
	var out bytes.Buffer
	Commit(root, "site", "pull 1", &out) // git may fail on the folder too; only the report matters here
	if !strings.Contains(out.String(), locked+" ist nicht lesbar") {
		t.Fatalf("no report:\n%s", out.String())
	}
}

func TestPrintable(t *testing.T) {
	if got := printable("a\x1b[2Jb\x07\u009b\x7f\n\tü"); got != `a\x1b[2Jb\x07\x9b\x7f`+"\n\tü" {
		t.Fatalf("printable = %q", got)
	}
}

// Server-Modus: der Docroot heisst nicht public/, das Snapshot-Repo liegt in <site>/.wpsync/history.git
// (nur der Docroot ist in die Container gemountet) und bleibt selbst ausserhalb des Schnappschusses.
func TestCommitTreeWithOtherDocroot(t *testing.T) {
	dir := t.TempDir()
	for _, f := range []string{
		"docroot/wp-content/plugins/a/a.php",
		"docroot/wp-content/uploads/2026/x.jpg",
		"docroot/wp-config.php",
		".wpsync/baseline.json",
		".wpsync/db/tables/wp_options.sql",
	} {
		write(t, dir, f)
	}
	gitDir := TreeGitDir(dir)
	if err := CommitTree(gitDir, dir, "docroot", "pull 1", io.Discard); err != nil {
		t.Fatal(err)
	}
	if err := CommitTree(gitDir, dir, "docroot", "pull 2", io.Discard); err != nil {
		t.Fatalf("second commit: %v", err)
	}
	got := strings.Fields(hostGit(t, "--git-dir="+gitDir, "ls-tree", "-r", "--name-only", "HEAD"))
	want := []string{".gitignore", ".wpsync/baseline.json", "docroot/wp-content/plugins/a/a.php"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("tracked = %v, want %v", got, want)
	}
	if _, err := os.Lstat(filepath.Join(dir, ".git")); !errors.Is(err, fs.ErrNotExist) {
		t.Fatalf("<site>/.git exists after CommitTree: %v", err)
	}
	for _, bad := range []string{"../x", "a/b", ".", ""} {
		if err := CommitTree(gitDir, dir, bad, "pull 3", io.Discard); err == nil {
			t.Fatalf("docroot %q must be rejected", bad)
		}
	}
}

// Ein vorhandenes <site>/.git wandert im Server-Modus neben das Snapshot-Repo, ohne git darin.
func TestCommitTreeMovesSiteGitNextToTheRepo(t *testing.T) {
	dir := t.TempDir()
	write(t, dir, "docroot/wp-content/plugins/a/a.php")
	attackerRepo(t, dir)
	var out bytes.Buffer
	if err := CommitTree(TreeGitDir(dir), dir, "docroot", "pull 1", &out); err != nil {
		t.Fatal(err)
	}
	moved, _ := filepath.Glob(filepath.Join(dir, ".wpsync", "history.alt-*.git"))
	if len(moved) != 1 || !strings.Contains(out.String(), "Bisheriges Site-Git verschoben nach") {
		t.Fatalf("moved = %v, out = %s", moved, out.String())
	}
}

// Container-Modus: wpsync endet mit dem Container. Ein Auto-gc während des Commits stürbe mit und
// liesse HEAD.lock zurück (Abnahme Server-Modus, Folge-Pull; Review M2): kein Auto-gc und keine
// Auto-Maintenance in den git-Aufrufen, abgekoppelt schon gar nicht.
func TestNoAutoMaintenanceInGitCalls(t *testing.T) {
	gitDir := filepath.Join(t.TempDir(), "history.git")
	if out, err := exec.Command("git", "init", "-q", "--bare", gitDir).CombinedOutput(); err != nil {
		t.Fatalf("git init: %v %s", err, out)
	}
	for key, want := range map[string]string{"gc.auto": "0", "maintenance.auto": "false", "gc.autoDetach": "false", "maintenance.autoDetach": "false"} {
		out, err := run(gitDir, "", "config", "--get", key)
		if err != nil || strings.TrimSpace(string(out)) != want {
			t.Errorf("%s = %q, %v; want %s", key, out, err, want)
		}
	}
}

// Review M2: ein hart beendeter Commit oder gc hinterlässt Locks im Snapshot-Repo; ohne Aufräumen
// scheiterte jeder weitere Pull. ClearStaleLocks entfernt nur die Top-Level-Locks des git-dir.
func TestClearStaleLocks(t *testing.T) {
	siteDir := t.TempDir()
	write(t, siteDir, "html/wp-content/a.php")
	gitDir := TreeGitDir(siteDir)
	if err := CommitTree(gitDir, siteDir, "html", "first", io.Discard); err != nil {
		t.Fatal(err)
	}
	stale := []string{"index.lock", "HEAD.lock", "refs/heads/main.lock", "gc.pid"}
	for _, rel := range stale {
		os.WriteFile(filepath.Join(gitDir, filepath.FromSlash(rel)), nil, 0o644)
	}
	keep := filepath.Join(gitDir, "refs", "heads", "x", "y.lock")
	os.MkdirAll(filepath.Dir(keep), 0o755)
	os.WriteFile(keep, nil, 0o644)
	write(t, siteDir, "html/wp-content/b.php")
	if err := CommitTree(gitDir, siteDir, "html", "blocked", io.Discard); err == nil {
		t.Fatal("commit with index.lock must fail (test setup)")
	}
	if err := ClearStaleLocks(gitDir); err != nil {
		t.Fatal(err)
	}
	for _, rel := range stale {
		if _, err := os.Lstat(filepath.Join(gitDir, filepath.FromSlash(rel))); !os.IsNotExist(err) {
			t.Errorf("%s still there", rel)
		}
	}
	if _, err := os.Stat(keep); err != nil {
		t.Error("nested lock removed")
	}
	os.Remove(keep)
	if err := CommitTree(gitDir, siteDir, "html", "second", io.Discard); err != nil {
		t.Fatalf("commit after cleanup: %v", err)
	}
	if err := ClearStaleLocks(filepath.Join(t.TempDir(), "missing.git")); err != nil {
		t.Fatalf("missing repo: %v", err)
	}
}

// Der Schnappschuss schreibt nichts in den Site-Ordner: keine .gitignore, kein Index, kein .git.
// Eine .gitignore der Site (auch als Symlink) bleibt, wie sie ist, und steuert nichts.
func TestCommitTreeWritesNothingIntoTheSiteFolder(t *testing.T) {
	siteDir, outside := t.TempDir(), t.TempDir()
	victim := filepath.Join(outside, "victim")
	os.WriteFile(victim, []byte("*.php\n"), 0o644)
	write(t, siteDir, "html/wp-content/a.php")
	if err := os.Symlink(victim, filepath.Join(siteDir, ".gitignore")); err != nil {
		t.Fatal(err)
	}
	before := treeHashes(t, siteDir)
	if err := CommitTree(TreeGitDir(siteDir), siteDir, "html", "first", io.Discard); err != nil {
		t.Fatal(err)
	}
	after := treeHashes(t, siteDir)
	for rel := range after {
		if strings.HasPrefix(rel, ".wpsync") {
			delete(after, rel) // the snapshot repo itself
		}
	}
	if !reflect.DeepEqual(before, after) {
		t.Fatalf("site folder changed:\n%v\n->\n%v", before, after)
	}
	if b, _ := os.ReadFile(victim); string(b) != "*.php\n" {
		t.Fatalf("victim = %q", b)
	}
	if got := hostGit(t, "--git-dir="+TreeGitDir(siteDir), "ls-tree", "-r", "--name-only", "HEAD"); !strings.Contains(got, "html/wp-content/a.php") {
		t.Fatalf("the site's .gitignore steered the snapshot: %s", got)
	}
}

// SEC-113, Tiefenverteidigung: auch ohne Symlink-Weg führt ein manipuliertes history.git/config
// (Filter, include, core.*) zusammen mit einer .gitattributes im Baum nichts aus.
func TestManipulatedConfigAndAttributesRunNothing(t *testing.T) {
	siteDir := t.TempDir()
	write(t, siteDir, "html/wp-content/a.php")
	gitDir := TreeGitDir(siteDir)
	if err := CommitTree(gitDir, siteDir, "html", "first", io.Discard); err != nil {
		t.Fatal(err)
	}
	markers := t.TempDir()
	inc := filepath.Join(t.TempDir(), "inc")
	os.WriteFile(inc, []byte("[filter \"inc\"]\n\tclean = touch "+filepath.Join(markers, "include")+" && cat\n"), 0o644)
	cfgPath := filepath.Join(gitDir, "config")
	cfg, _ := os.ReadFile(cfgPath)
	evil := string(cfg) +
		"[filter \"pwn\"]\n\tclean = touch " + filepath.Join(markers, "filter") + " && cat\n" +
		"[include]\n\tpath = " + inc + "\n" +
		"[core]\n\tfsmonitor = touch " + filepath.Join(markers, "fsmonitor") + "\n" +
		"[diff \"x\"]\n\ttextconv = touch " + filepath.Join(markers, "textconv") + "\n"
	if err := os.WriteFile(cfgPath, []byte(evil), 0o644); err != nil {
		t.Fatal(err)
	}
	os.MkdirAll(filepath.Join(gitDir, "info"), 0o755)
	os.WriteFile(filepath.Join(gitDir, "info", "attributes"), []byte("* filter=pwn\n"), 0o644)
	os.WriteFile(filepath.Join(siteDir, "html", "wp-content", ".gitattributes"), []byte("* filter=pwn\n*.php filter=inc diff=x\n"), 0o644)
	write(t, siteDir, "html/wp-content/b.php")
	if err := CommitTree(gitDir, siteDir, "html", "second", io.Discard); err != nil {
		t.Fatalf("commit: %v", err)
	}
	if entries, _ := os.ReadDir(markers); len(entries) != 0 {
		t.Fatalf("git ran commands from a manipulated repo: %v", entries)
	}
	after, _ := os.ReadFile(cfgPath)
	for _, bad := range []string{"filter", "include", "fsmonitor", "textconv"} {
		if strings.Contains(string(after), bad) {
			t.Errorf("config still contains %s:\n%s", bad, after)
		}
	}
	if _, err := os.Lstat(filepath.Join(gitDir, "info", "attributes")); !os.IsNotExist(err) {
		t.Error("info/attributes must be removed")
	}
	if got := hostGit(t, "--git-dir="+gitDir, "show", "--name-only", "--format=", "HEAD"); !strings.Contains(got, "html/wp-content/b.php") {
		t.Fatalf("second commit misses b.php: %q", got)
	}
}

// Die eigenen Einträge von git init bleiben erhalten; eine saubere config wird nicht angefasst.
func TestCleanConfigStaysUntouched(t *testing.T) {
	siteDir := t.TempDir()
	write(t, siteDir, "html/wp-content/a.php")
	gitDir := TreeGitDir(siteDir)
	if err := CommitTree(gitDir, siteDir, "html", "first", io.Discard); err != nil {
		t.Fatal(err)
	}
	before, _ := os.ReadFile(filepath.Join(gitDir, "config"))
	if err := CommitTree(gitDir, siteDir, "html", "second", io.Discard); err != nil {
		t.Fatal(err)
	}
	if after, _ := os.ReadFile(filepath.Join(gitDir, "config")); string(after) != string(before) {
		t.Fatalf("config changed:\n%s\n->\n%s", before, after)
	}
}

// Review 3, M-1 / AC-131: tauscht die Site während des Schnappschusses einen Ordner gegen einen
// Symlink auf <slug>/.wpsync/… oder irgendwohin ausserhalb, kommt keine Datei von dort in die Historie.
func TestSwappedFolderDoesNotLeakIntoTheSnapshot(t *testing.T) {
	for _, target := range []string{"inside-site", "absolute"} {
		t.Run(target, func(t *testing.T) {
			siteDir := t.TempDir()
			write(t, siteDir, "docroot/wp-content/plugins/a/a.php")
			write(t, siteDir, "docroot/wp-content/plugins/b/b.php")
			secretDir := filepath.Join(siteDir, ".wpsync", "db")
			if target == "absolute" {
				secretDir = t.TempDir()
			}
			os.MkdirAll(secretDir, 0o700)
			os.WriteFile(filepath.Join(secretDir, "a.php"), []byte("TOP-SECRET-DUMP"), 0o600)
			plugin := filepath.Join(siteDir, "docroot/wp-content/plugins/a")
			testHookBeforeRead = func(rel string) {
				if rel != "plugins/a/a.php" {
					return
				}
				os.Rename(plugin, plugin+"-orig")
				link := secretDir
				if target == "inside-site" {
					link = "../../../.wpsync/db"
				}
				if err := os.Symlink(link, plugin); err != nil {
					t.Error(err)
				}
			}
			t.Cleanup(func() { testHookBeforeRead = nil })
			var out bytes.Buffer
			gitDir := TreeGitDir(siteDir)
			if err := CommitTree(gitDir, siteDir, "docroot", "pull 1", &out); !errors.Is(err, ErrIncomplete) {
				t.Fatalf("err = %v, want ErrIncomplete", err)
			}
			if got := hostGit(t, "--git-dir="+gitDir, "log", "--all", "-p", "--format=%H"); strings.Contains(got, "TOP-SECRET-DUMP") {
				t.Fatalf("file from outside the docroot in history.git:\n%s", got)
			}
			if !strings.Contains(out.String(), "plugins/a/a.php hat sich während des Schnappschusses geändert") {
				t.Fatalf("no report:\n%s", out.String())
			}
			if got := hostGit(t, "--git-dir="+gitDir, "ls-tree", "-r", "--name-only", "HEAD"); !strings.Contains(got, "docroot/wp-content/plugins/b/b.php") {
				t.Fatalf("other files missing:\n%s", got)
			}
		})
	}
}

// Grill 2026-10-07: wpsync öffnet jeden Ordner einmal und liest seine Dateien über diesen Handle.
// Wird der Ordner danach getauscht – vor seiner zweiten Datei –, liest wpsync weiter aus dem
// geöffneten Ordner und nie durch den Symlink; dasselbe für einen Tausch des Elternordners.
func TestFolderSwappedAfterItsFirstFileDoesNotLeak(t *testing.T) {
	for _, swap := range []string{"plugins/a", "plugins"} {
		t.Run(swap, func(t *testing.T) {
			siteDir := t.TempDir()
			write(t, siteDir, "docroot/wp-content/plugins/a/a.php")
			write(t, siteDir, "docroot/wp-content/plugins/a/z.php")
			secretDir := filepath.Join(siteDir, ".wpsync", "db")
			os.MkdirAll(filepath.Join(secretDir, "a"), 0o700)
			os.WriteFile(filepath.Join(secretDir, "z.php"), []byte("TOP-SECRET-DUMP"), 0o600)
			os.WriteFile(filepath.Join(secretDir, "a", "z.php"), []byte("TOP-SECRET-DUMP"), 0o600)
			folder := filepath.Join(siteDir, "docroot/wp-content", filepath.FromSlash(swap))
			testHookBeforeRead = func(rel string) {
				if rel != "plugins/a/z.php" {
					return
				}
				os.Rename(folder, folder+"-orig")
				if err := os.Symlink(secretDir, folder); err != nil {
					t.Error(err)
				}
			}
			t.Cleanup(func() { testHookBeforeRead = nil })
			gitDir := TreeGitDir(siteDir)
			if err := CommitTree(gitDir, siteDir, "docroot", "pull 1", io.Discard); err != nil && !errors.Is(err, ErrIncomplete) {
				t.Fatal(err)
			}
			if got := hostGit(t, "--git-dir="+gitDir, "log", "--all", "-p", "--format=%H"); strings.Contains(got, "TOP-SECRET-DUMP") {
				t.Fatalf("file from outside the docroot in history.git:\n%s", got)
			}
		})
	}
}

// Spec Container-Push A7: ein relativer PATH-Eintrag vor dem echten git wird übergangen – kein
// git aus dem aktuellen Ordner, kein exec.ErrDot.
func TestGitComesOnlyFromAbsolutePathEntries(t *testing.T) {
	root, _ := newSite(t)
	marker := filepath.Join(t.TempDir(), "marker")
	cwd := t.TempDir()
	os.WriteFile(filepath.Join(cwd, "git"), []byte(fmt.Sprintf("#!/bin/sh\necho \"$@\" >> \"%s\"\nexit 1\n", marker)), 0o755)
	t.Chdir(cwd)
	path := os.Getenv("PATH")
	t.Setenv("PATH", "."+string(os.PathListSeparator)+path)
	err := Commit(root, "site", "pull 1", io.Discard)
	os.Setenv("PATH", path) // the test's own git calls below
	if err != nil {
		t.Fatalf("Commit: %v", err)
	}
	noMarker(t, marker)
	if n := snapCount(t, root); n != 1 {
		t.Fatalf("snapshot commits = %d", n)
	}
}

// Ein Snapshot ohne wp-content (frischer Docroot) und ohne Baseline enthält nur die .gitignore.
func TestSnapshotOfAnEmptySiteFolder(t *testing.T) {
	siteDir := t.TempDir()
	os.MkdirAll(filepath.Join(siteDir, "docroot"), 0o755)
	gitDir := TreeGitDir(siteDir)
	if err := CommitTree(gitDir, siteDir, "docroot", "first", io.Discard); err != nil {
		t.Fatal(err)
	}
	if got := strings.Fields(hostGit(t, "--git-dir="+gitDir, "ls-tree", "-r", "--name-only", "HEAD")); !reflect.DeepEqual(got, []string{".gitignore"}) {
		t.Fatalf("tracked = %v", got)
	}
}

// Security-Audit F-1: tauscht die Site eine gelistete Datei nach dem Walk gegen eine FIFO, blockiert
// open(2) ohne O_NONBLOCK für immer. Der Schnappschuss meldet die Datei als fehlend und kehrt zurück.
func TestFifoSwappedInDoesNotHangTheSnapshot(t *testing.T) {
	siteDir := t.TempDir()
	write(t, siteDir, "docroot/wp-content/plugins/a/a.php")
	write(t, siteDir, "docroot/wp-content/plugins/a/z.php")
	victim := filepath.Join(siteDir, "docroot/wp-content/plugins/a/a.php")
	testHookBeforeRead = func(rel string) {
		if rel != "plugins/a/a.php" {
			return
		}
		os.Remove(victim)
		if err := syscall.Mkfifo(victim, 0o644); err != nil {
			t.Error(err)
		}
	}
	t.Cleanup(func() { testHookBeforeRead = nil })
	var out bytes.Buffer
	gitDir := TreeGitDir(siteDir)
	done := make(chan error, 1)
	go func() { done <- CommitTree(gitDir, siteDir, "docroot", "pull 1", &out) }()
	select {
	case err := <-done:
		if !errors.Is(err, ErrIncomplete) {
			t.Fatalf("err = %v, want ErrIncomplete", err)
		}
	case <-time.After(10 * time.Second):
		// free the blocked open so the goroutine ends
		if f, err := os.OpenFile(victim, os.O_WRONLY|syscall.O_NONBLOCK, 0); err == nil {
			f.Close()
		}
		t.Fatal("CommitTree hangs on a FIFO")
	}
	if !strings.Contains(out.String(), "plugins/a/a.php hat sich während des Schnappschusses geändert") {
		t.Fatalf("no report:\n%s", out.String())
	}
	if got := hostGit(t, "--git-dir="+gitDir, "ls-tree", "-r", "--name-only", "HEAD"); !strings.Contains(got, "plugins/a/z.php") || strings.Contains(got, "plugins/a/a.php") {
		t.Fatalf("tree:\n%s", got)
	}
}

// Security-Audit F-3: bricht der Strom ab, weil eine Datei beim Kopieren kürzer wird, hinterlässt
// git fast-import einen fast_import_crash_* im Repo. CommitTree räumt ihn weg.
func TestFailedImportLeavesNoCrashReport(t *testing.T) {
	siteDir := t.TempDir()
	write(t, siteDir, "docroot/wp-content/plugins/a/a.php")
	victim := filepath.Join(siteDir, "docroot/wp-content/plugins/a/a.php")
	os.WriteFile(victim, []byte(strings.Repeat("x", 4096)), 0o644)
	testHookAfterStat = func(rel string) {
		if rel == "plugins/a/a.php" {
			os.Truncate(victim, 10)
		}
	}
	t.Cleanup(func() { testHookAfterStat = nil })
	gitDir := TreeGitDir(siteDir)
	err := CommitTree(gitDir, siteDir, "docroot", "pull 1", io.Discard)
	if err == nil || errors.Is(err, ErrIncomplete) {
		t.Fatalf("err = %v, want a failure", err)
	}
	if left, _ := filepath.Glob(filepath.Join(gitDir, "fast_import_crash_*")); len(left) != 0 {
		t.Fatalf("crash reports left: %v", left)
	}
}
