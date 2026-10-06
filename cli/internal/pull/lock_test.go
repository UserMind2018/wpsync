package pull

import (
	"bytes"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/localgit"
)

// Review M1: zwei Pulls derselben Site laufen nie gleichzeitig. Der zweite bricht sofort ab,
// bevor er die Quelle fragt oder lokal etwas anfasst.
func TestRunRefusesWhileAnotherPullHoldsTheLock(t *testing.T) {
	requests := 0
	srv := agentServer(t, func(string) { requests++ })
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	o.SiteDir = t.TempDir()
	o.Docroot = filepath.Join(o.SiteDir, "html")

	held, err := lockSite(o.lockPath())
	if err != nil {
		t.Fatal(err)
	}
	err = Run(o)
	var local *localenv.Error
	if !errors.Is(err, ErrPullRunning) || !errors.As(err, &local) {
		t.Fatalf("err = %v, want ErrPullRunning as local_env", err)
	}
	if requests != 0 {
		t.Fatalf("%d requests while locked", requests)
	}
	if _, err := os.Stat(o.Docroot); !os.IsNotExist(err) {
		t.Fatal("docroot touched while locked")
	}
	held.Close()
	if err := Run(o); err != nil {
		t.Fatalf("after unlock: %v", err)
	}
}

func TestLockPaths(t *testing.T) {
	o := Options{SitesRoot: "/sites", SiteDir: "/srv/kunde"}
	o.Site.Name = "kunde"
	if got := o.lockPath(); got != "/srv/kunde/.wpsync/lock" {
		t.Errorf("server lock = %s", got)
	}
	// Mac: .wpsync/ im Site-Ordner liegt im DDEV-Mount – der Lock liegt daneben beim Snapshot-Repo.
	o.SiteDir = ""
	if got := o.lockPath(); got != "/sites/.wpsync-git/kunde.lock" {
		t.Errorf("mac lock = %s", got)
	}
}

// orphanDriver records whether the site lock was held when the orphans were removed.
type orphanDriver struct {
	*fakeDriver
	lock      string
	lockedNow bool
	called    bool
}

func (d *orphanDriver) RemoveOrphans(site string) error {
	d.called = true
	l, err := lockSite(d.lock)
	if err == nil {
		l.Close()
	}
	d.lockedNow = errors.Is(err, ErrPullRunning)
	return nil
}

func TestRunRemovesOrphansUnderTheLock(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	drv := &orphanDriver{fakeDriver: newFakeDriver(false)}
	o := pullOptions(t, srv.URL, drv)
	o.SiteDir = t.TempDir()
	o.Docroot = filepath.Join(o.SiteDir, "html")
	drv.lock = o.lockPath()
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if !drv.called || !drv.lockedNow {
		t.Fatalf("called = %v, under lock = %v", drv.called, drv.lockedNow)
	}
}

// sitesRootTrace lists what a pull left below the sites root besides its site lock: the lock file
// is taken before anything else and stays (AC-7 otherwise holds: no site folder, no baseline).
func sitesRootTrace(root, site string) []string {
	var trace []string
	entries, _ := os.ReadDir(root)
	for _, e := range entries {
		if e.Name() == ".wpsync-git" {
			inner, _ := os.ReadDir(filepath.Join(root, e.Name()))
			if len(inner) == 1 && inner[0].Name() == site+".lock" {
				continue
			}
		}
		trace = append(trace, e.Name())
	}
	return trace
}

// Review M2: scheitert nur der Schnappschuss, bleibt der Pull erfolgreich – mit Warnung auf der
// Ausgabe und snapshot_failed in Result.Warnings.
func TestSnapshotFailureIsAWarning(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	o.SiteDir = t.TempDir()
	o.Docroot = filepath.Join(o.SiteDir, "html")
	var res Result
	o.Report = &res
	gitDir := localgit.TreeGitDir(o.SiteDir)
	os.MkdirAll(filepath.Dir(gitDir), 0o755)
	os.WriteFile(gitDir, []byte("kein Repo"), 0o644)
	if err := Run(o); err != nil {
		t.Fatalf("Run: %v", err)
	}
	if len(res.Warnings) != 1 || res.Warnings[0] != WarningSnapshotFailed {
		t.Fatalf("warnings = %v", res.Warnings)
	}
	if !strings.Contains(o.Out.(*bytes.Buffer).String(), "Schnappschuss") {
		t.Fatalf("no warning in output:\n%s", o.Out)
	}
}

// Liegengebliebene git-Locks eines gekillten Pulls blockieren den nächsten nicht.
func TestRunClearsStaleGitLocks(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	o.SiteDir = t.TempDir()
	o.Docroot = filepath.Join(o.SiteDir, "html")
	var res Result
	o.Report = &res
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	gitDir := localgit.TreeGitDir(o.SiteDir)
	os.WriteFile(filepath.Join(gitDir, "index.lock"), nil, 0o644)
	os.WriteFile(filepath.Join(gitDir, "HEAD.lock"), nil, 0o644)
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if len(res.Warnings) != 0 {
		t.Fatalf("warnings = %v", res.Warnings)
	}
}

// Review N3: auch ohne die Prüfung der Flags liegt der Docroot nie auf .wpsync oder .git.
func TestRunRefusesReservedDocroot(t *testing.T) {
	for _, name := range []string{".wpsync", ".git", ".GIT"} {
		o := pullOptions(t, "http://127.0.0.1:1", newFakeDriver(false))
		o.SiteDir = t.TempDir()
		o.Docroot = filepath.Join(o.SiteDir, name)
		if err := Run(o); err == nil || !strings.Contains(err.Error(), "reserv") {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}
