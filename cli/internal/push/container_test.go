package push

import (
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/sitelock"
)

// containerLayout moves the site of localSite into the layout of the Website Studio:
// <tmp>/kunde/docroot with .wpsync next to it. SitesRoot points at an empty folder that has to
// stay empty (Spec Container-Push C1).
func containerLayout(t *testing.T, o Options, siteDir string) (Options, string) {
	t.Helper()
	studio := filepath.Join(t.TempDir(), "kunde")
	if err := os.Rename(siteDir, studio); err != nil {
		t.Fatal(err)
	}
	if err := os.Rename(filepath.Join(studio, "public"), filepath.Join(studio, "docroot")); err != nil {
		t.Fatal(err)
	}
	o.SitesRoot = t.TempDir()
	o.SiteDir, o.Docroot = studio, filepath.Join(studio, "docroot")
	return o, studio
}

// history lists the subjects of the snapshot repo in the site folder, newest first.
func history(t *testing.T, siteDir string) string {
	t.Helper()
	out, err := exec.Command("git", "--git-dir="+localgit.TreeGitDir(siteDir), "log", "--format=%s").Output()
	if err != nil {
		t.Fatalf("git log: %v", err)
	}
	return string(out)
}

// AC-115: baseline, journal, lock and snapshot repo of a live push lie in the site folder; the
// sites root of the Mac stays untouched. A missing history.git is created (AC-116).
func TestRunInContainerLayoutKeepsEverythingInTheSiteFolder(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	o, studio := containerLayout(t, o, siteDir)
	o.Commit = nil // the real snapshot
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := history(t, studio); !strings.Contains(got, "push "+testID) {
		t.Errorf("history = %q", got)
	}
	ls, _ := exec.Command("git", "--git-dir="+localgit.TreeGitDir(studio), "ls-tree", "-r", "--name-only", "HEAD").Output()
	if !strings.Contains(string(ls), "docroot/wp-content/plugins/x/main.php") || !strings.Contains(string(ls), ".wpsync/baseline.json") {
		t.Errorf("snapshot = %s", ls)
	}
	if _, err := os.Stat(filepath.Join(studio, ".wpsync", "lock")); err != nil {
		t.Errorf("lock not in the site folder: %v", err)
	}
	if entries, _ := os.ReadDir(o.SitesRoot); len(entries) != 0 {
		t.Errorf("sites root not empty: %v", entries)
	}
	base, _ := baseline.Load(studio)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1800000000 {
		t.Errorf("baseline = %v", base.Files)
	}
	if j, err := LoadJournal(studio, testID); err != nil || !j.Applied {
		t.Errorf("journal = %+v, %v", j, err)
	}
}

// AC-118: push and the container pull share <slug>/.wpsync/lock.
func TestRunInContainerLayoutTakesTheLockOfTheContainerPull(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	o, studio := containerLayout(t, o, siteDir)
	held, err := sitelock.Acquire(sitelock.Path("", "kunde", studio))
	if err != nil {
		t.Fatal(err)
	}
	defer held.Close()
	var local *localenv.Error
	if err := Run(o); !errors.Is(err, sitelock.ErrBusy) || !errors.As(err, &local) {
		t.Errorf("push = %v, want ErrBusy as local_env", err)
	}
	if err := Rollback(o, testID); !errors.Is(err, sitelock.ErrBusy) {
		t.Errorf("rollback = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("a locked site must not cost a request: %v", f.routes)
	}
}

// AC-117: a push to staging leaves baseline and history.git alone and keeps its stamps in the
// site folder; the second push of the same unit needs no --force.
func TestRunToStagingInContainerLayout(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	o, studio := containerLayout(t, o, siteDir)
	o.Commit = nil
	before, _ := os.ReadFile(filepath.Join(studio, ".wpsync", "baseline.json"))
	mustPush(t, o, out)
	write(t, filepath.Join(studio, "docroot"), "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */\n// edited twice", 1800000100)
	f.ids = []string{secondID}
	mustPush(t, o, out)
	if f.begins[len(f.begins)-1].Force {
		t.Error("second push to staging sent --force")
	}
	if after, _ := os.ReadFile(filepath.Join(studio, ".wpsync", "baseline.json")); string(after) != string(before) {
		t.Error("baseline changed by a push to staging")
	}
	if _, err := os.Stat(localgit.TreeGitDir(studio)); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("history.git after pushes to staging only: %v", err)
	}
	if _, err := os.Stat(stampsPath(studio)); err != nil {
		t.Errorf("staging stamps not in the site folder: %v", err)
	}
}

// AC-124: rollback <id> in the container layout puts the baseline back and records it in
// history.git.
func TestRollbackInContainerLayout(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	o, studio := containerLayout(t, o, siteDir)
	o.Commit = nil
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if err := Rollback(o, testID); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	base, _ := baseline.Load(studio)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1700000000 {
		t.Errorf("baseline not reverted: %v", base.Files["wp-content/plugins/x/main.php"])
	}
	if got := history(t, studio); !strings.HasPrefix(got, "rollback "+testID) {
		t.Errorf("history = %q", got)
	}
}

func TestDocrootMustLieDirectlyBelowTheSiteFolder(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	o, studio := containerLayout(t, o, siteDir)
	o.Docroot = filepath.Join(studio, "docroot", "deeper")
	if err := Run(o); err == nil || !strings.Contains(err.Error(), "directly below") {
		t.Fatalf("err = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("routes = %v", f.routes)
	}
}
