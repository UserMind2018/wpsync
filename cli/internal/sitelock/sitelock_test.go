package sitelock

import (
	"errors"
	"path/filepath"
	"testing"
)

func TestAcquireIsExclusive(t *testing.T) {
	path := filepath.Join(t.TempDir(), "x", "lock")
	l, err := Acquire(path)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := Acquire(path); !errors.Is(err, ErrBusy) {
		t.Fatalf("second Acquire = %v, want ErrBusy", err)
	}
	l.Close()
	l2, err := Acquire(path)
	if err != nil {
		t.Fatalf("after release: %v", err)
	}
	l2.Close()
}

func TestPath(t *testing.T) {
	if got := Path("/sites", "kunde", "/srv/kunde"); got != "/srv/kunde/.wpsync/lock" {
		t.Errorf("server = %s", got)
	}
	if got := Path("/sites", "kunde", ""); got != "/sites/.wpsync-git/kunde.lock" {
		t.Errorf("mac = %s", got)
	}
}
