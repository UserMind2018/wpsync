package agentapi

import (
	"errors"
	"fmt"
	"net/url"
	"regexp"
	"strings"
)

// WP-CLI reads every argument that starts with "--" as one of its own options, wherever it
// stands, and "--" does not end option parsing (`wp config set table_prefix -- --exec=…` still
// runs the code). Env values from /delta that the CLI hands to `ddev wp` as arguments therefore
// have to match a form that cannot start with "-". The site chooses them; the request signature
// covers our request, not its answer.
const (
	// maxTablePrefixLen matches the identifier limit used for table names.
	maxTablePrefixLen = 64
	// maxSiteURLLen bounds home and siteurl; real URLs stay far below it.
	maxSiteURLLen = 2048
)

// tablePrefixRe allows what wpdb::set_prefix allows. The empty prefix passes there but not
// here: the local site would not find its tables.
var tablePrefixRe = regexp.MustCompile(`^[A-Za-z0-9_]+$`)

// ErrInvalidEnv: /delta carried a value the CLI must not pass to WP-CLI.
var ErrInvalidEnv = errors.New("site env value not usable as WP-CLI argument")

// ValidTablePrefix reports whether p may become a `wp` argument (wp-config.php, search-replace table).
func ValidTablePrefix(p string) bool {
	return len(p) <= maxTablePrefixLen && tablePrefixRe.MatchString(p)
}

// ValidSiteURL reports whether s may be a search-replace pattern: an absolute http(s) URL with a
// host, without whitespace or control characters. Path, port and non-ASCII hosts stay allowed
// (subdirectory installs, IDN).
func ValidSiteURL(s string) bool {
	if len(s) > maxSiteURLLen || strings.IndexFunc(s, func(r rune) bool { return r <= ' ' || r == 0x7f }) >= 0 {
		return false
	}
	u, err := url.Parse(s)
	// Hostname, not Host: "https://:80" has a Host but no host name.
	return err == nil && (u.Scheme == "http" || u.Scheme == "https") && u.Hostname() != ""
}

// EnvField is one Env value by its /delta key.
type EnvField struct{ Key, Value string }

// InvalidArgs lists the Env values that reach WP-CLI as arguments and break their rule, in a
// fixed order. An empty siteurl is allowed: PostSetup then only uses home.
func (e Env) InvalidArgs() []EnvField {
	var bad []EnvField
	if !ValidTablePrefix(e.TablePrefix) {
		bad = append(bad, EnvField{"table_prefix", e.TablePrefix})
	}
	if !ValidSiteURL(e.Home) {
		bad = append(bad, EnvField{"home", e.Home})
	}
	if e.SiteURL != "" && !ValidSiteURL(e.SiteURL) {
		bad = append(bad, EnvField{"siteurl", e.SiteURL})
	}
	return bad
}

// CheckArgs guards callers that pass Env values to WP-CLI.
func (e Env) CheckArgs() error {
	bad := e.InvalidArgs()
	if len(bad) == 0 {
		return nil
	}
	shown := make([]string, len(bad))
	for i, f := range bad {
		shown[i] = f.Key + " " + Printable(f.Value)
	}
	return fmt.Errorf("%w: %s", ErrInvalidEnv, strings.Join(shown, ", "))
}
