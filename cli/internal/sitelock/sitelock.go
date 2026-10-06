// Package sitelock is the one lock per site that pull, push and rollback share: they all write the
// docroot, the baseline and the snapshot repo, and only the lock holder may clear git locks that a
// killed predecessor left behind (localgit.ClearStaleLocks).
package sitelock

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"syscall"
)

// ErrBusy: another pull, push or rollback of the same site holds the lock (exit code local_env).
var ErrBusy = errors.New("für diese Site läuft bereits ein wpsync-Vorgang (pull, push oder rollback) – erneut versuchen, wenn er beendet ist")

// storeDir is localgit's folder of snapshot repos next to the sites (Mac).
const storeDir = ".wpsync-git"

// Path is the lock of a site. Server mode (siteDir set): <siteDir>/.wpsync/lock, out of the site's
// reach like baseline and snapshot repo. Mac: <sitesRoot>/.wpsync-git/<site>.lock next to the
// snapshot repo, because <site>/.wpsync/ lies in the DDEV mount, where the site could hold or
// replace the lock.
func Path(sitesRoot, site, siteDir string) string {
	if siteDir == "" {
		return filepath.Join(sitesRoot, storeDir, site+".lock")
	}
	return filepath.Join(siteDir, ".wpsync", "lock")
}

// Acquire takes the lock without waiting: flock on the lock file, released by Close or by the end
// of the process (also after SIGKILL). The file itself stays.
func Acquire(path string) (*os.File, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return nil, fmt.Errorf("site lock %s: %w", path, err)
	}
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		f.Close()
		if errors.Is(err, syscall.EWOULDBLOCK) {
			return nil, ErrBusy
		}
		return nil, fmt.Errorf("site lock %s: %w", path, err)
	}
	return f, nil
}
