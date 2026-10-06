package push

import (
	"bytes"
	"errors"
	"os"
	"path/filepath"
	"testing"

	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/sitelock"
	"github.com/usermind/wpsync/internal/sites"
)

// Nach-Review M-1: push und rollback committen ins selbe Repo wie pull und nehmen denselben
// Site-Lock. Ist er belegt, brechen sie sofort mit local_env ab, ohne die Site zu fragen.
func TestPushAndRollbackTakeTheSiteLock(t *testing.T) {
	root := t.TempDir()
	held, err := sitelock.Acquire(sitelock.Path(root, "kunde", ""))
	if err != nil {
		t.Fatal(err)
	}
	defer held.Close()
	o := Options{SitesRoot: root, Site: sites.Site{Name: "kunde", URL: "http://127.0.0.1:1"}, Out: &bytes.Buffer{}}
	var local *localenv.Error
	if err := Run(o); !errors.Is(err, sitelock.ErrBusy) || !errors.As(err, &local) {
		t.Errorf("push = %v, want ErrBusy as local_env", err)
	}
	if err := Rollback(o, ""); !errors.Is(err, sitelock.ErrBusy) || !errors.As(err, &local) {
		t.Errorf("rollback = %v, want ErrBusy as local_env", err)
	}
}

// Der Standard-Commit räumt liegengebliebene git-Locks weg (er läuft unter dem Site-Lock).
func TestDefaultCommitClearsStaleGitLocks(t *testing.T) {
	root := t.TempDir()
	siteDir := filepath.Join(root, "kunde")
	os.MkdirAll(filepath.Join(siteDir, "public", "wp-content", "plugins", "a"), 0o755)
	os.WriteFile(filepath.Join(siteDir, "public", "wp-content", "plugins", "a", "a.php"), []byte("<?php\n"), 0o644)
	o := Options{SitesRoot: root, Site: sites.Site{Name: "kunde", URL: "https://kunde.example"}, Out: &bytes.Buffer{}}.defaults()
	if err := o.Commit(siteDir, "first"); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(filepath.Join(localgit.GitDir(root, "kunde"), "index.lock"), nil, 0o644)
	if err := o.Commit(siteDir, "second"); err != nil {
		t.Fatalf("commit with stale index.lock: %v", err)
	}
}
