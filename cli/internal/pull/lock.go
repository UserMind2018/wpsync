package pull

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"syscall"

	"github.com/usermind/wpsync/internal/localgit"
)

// ErrPullRunning: another pull of the same site holds the site lock (exit code local_env).
var ErrPullRunning = errors.New("für diese Site läuft bereits ein Pull – erneut versuchen, wenn er beendet ist")

// lockPath is the site lock. Server mode: <siteDir>/.wpsync/lock, out of the site's reach like
// baseline and snapshot repo. Mac: <sitesRoot>/.wpsync-git/<site>.lock next to the snapshot repo,
// because <site>/.wpsync/ lies in the DDEV mount, where the site could hold or replace the lock.
func (o *Options) lockPath() string {
	if o.SiteDir == "" {
		return filepath.Join(filepath.Dir(localgit.GitDir(o.SitesRoot, o.Site.Name)), o.Site.Name+".lock")
	}
	return filepath.Join(o.SiteDir, ".wpsync", "lock")
}

// lockSite takes the site lock without waiting: flock on the lock file, released by Close or by
// the end of the process (also after SIGKILL). The file itself stays.
func lockSite(path string) (*os.File, error) {
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
			return nil, ErrPullRunning
		}
		return nil, fmt.Errorf("site lock %s: %w", path, err)
	}
	return f, nil
}
