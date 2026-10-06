package pull

import (
	"github.com/usermind/wpsync/internal/sitelock"
)

// ErrPullRunning: another pull, push or rollback of the same site holds the site lock (exit code
// local_env). Same value as sitelock.ErrBusy.
var ErrPullRunning = sitelock.ErrBusy

// lockPath is the site lock shared with push and rollback (sitelock.Path).
func (o *Options) lockPath() string {
	return sitelock.Path(o.SitesRoot, o.Site.Name, o.SiteDir)
}

// lockSite takes the site lock without waiting (sitelock.Acquire).
var lockSite = sitelock.Acquire
