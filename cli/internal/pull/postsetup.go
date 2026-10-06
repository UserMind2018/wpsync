package pull

import (
	"errors"
	"fmt"
	"io"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/safefs"
)

// ErrMailguardMissing stops a pull: a local site must never send real mail (AC-22).
var ErrMailguardMissing = errors.New("local-mailguard is not active in the container")

// LocalDisabledPlugins are deactivated locally (Spike B14, AC-23).
var LocalDisabledPlugins = []string{
	// Mail – in addition to local-mailguard
	"wp-mail-smtp", "wp-mail-smtp-pro", "post-smtp", "fluent-smtp", "easy-wp-smtp",
	// Access protection & security – lock you out locally
	"password-protected", "better-wp-security", "ithemes-security-pro", "wps-hide-login",
	"wordfence", "all-in-one-wp-security-and-firewall", "sucuri-scanner",
	// Caching
	"wp-rocket", "w3-total-cache", "litespeed-cache", "wp-super-cache", "wp-fastest-cache",
}

// DropIns from caching plugins that break a local site.
var DropIns = []string{"advanced-cache.php", "object-cache.php"}

// Local admin of an anonymized site: pulled accounts cannot log in, their hashes stay on the server (Spec 11.4).
const (
	LocalAdminUser     = "wpsync"
	LocalAdminPassword = "wpsync"
)

// localAdminCode creates the local admin or resets its password and role. One wp eval, so it is
// idempotent and neither wp_insert_user nor wp_set_password sends mail.
const localAdminCode = `$u = get_user_by("login", "` + LocalAdminUser + `"); ` +
	`if ($u) { wp_set_password("` + LocalAdminPassword + `", $u->ID); $u->set_role("administrator"); } ` +
	`else { $r = wp_insert_user(["user_login" => "` + LocalAdminUser + `", "user_pass" => "` + LocalAdminPassword + `", ` +
	`"user_email" => "wpsync@example.invalid", "role" => "administrator"]); ` +
	`if (is_wp_error($r)) { fwrite(STDERR, $r->get_error_message()); exit(1); } }`

// PostSetupOptions adapts the post-setup to the pull profile.
type PostSetupOptions struct {
	// ExcludedPlugins were not pulled; active ones are removed from active_plugins (AC-15).
	ExcludedPlugins []string
	// LocalAdmin creates the local admin – set after an anonymized pull (AC-34).
	LocalAdmin bool
}

// PostSetup rewrites URLs, sets local constants and deactivates problematic or missing plugins.
func PostSetup(r localenv.Runner, env agentapi.Env, localURL string, o PostSetupOptions, out io.Writer) error {
	// Checked here as well as after /delta: home, siteurl and the prefix become `wp` arguments.
	if err := env.CheckArgs(); err != nil {
		return err
	}
	sources := []string{env.Home}
	if env.SiteURL != "" && env.SiteURL != env.Home {
		sources = append(sources, env.SiteURL)
	}
	for _, src := range sources {
		// Plain and JSON-escaped (Elementor & co. store "https:\/\/…").
		pairs := [][2]string{{src, localURL}, {strings.ReplaceAll(src, "/", `\/`), strings.ReplaceAll(localURL, "/", `\/`)}}
		for _, p := range pairs {
			if err := r.Run("wp", "search-replace", p[0], p[1], "--all-tables-with-prefix", "--skip-columns=guid",
				"--report-changed-only", "--skip-plugins", "--skip-themes"); err != nil {
				return err
			}
		}
	}

	if err := setLocalConstants(r, out); err != nil {
		return err
	}

	var deactivate []string
	for _, slug := range LocalDisabledPlugins {
		for _, active := range env.ActivePlugins {
			if strings.HasPrefix(active, slug+"/") {
				deactivate = append(deactivate, slug)
			}
		}
	}
	if len(deactivate) > 0 {
		fmt.Fprintf(out, "  lokal deaktiviert: %s\n", strings.Join(deactivate, ", "))
		args := append([]string{"wp", "plugin", "deactivate"}, deactivate...)
		if err := r.Run(append(args, "--skip-plugins", "--skip-themes")...); err != nil {
			return err
		}
	}
	if err := dropMissingPlugins(r, env, o.ExcludedPlugins, out); err != nil {
		return err
	}
	if o.LocalAdmin {
		if err := r.Run("wp", "eval", localAdminCode, "--skip-plugins", "--skip-themes"); err != nil {
			return fmt.Errorf("lokalen Admin anlegen: %w", err)
		}
		fmt.Fprintf(out, "  lokaler Admin %q angelegt\n", LocalAdminUser)
	}

	// Second pass with plugins loaded: only then can WP-CLI unserialize objects of plugin classes
	// (Spike B13, AC-21). Loading plugins locally may fail – a warning, not an abort.
	for _, src := range sources {
		if err := r.Run("wp", "search-replace", src, localURL, env.TablePrefix+"options", "--precise", "--report-changed-only"); err != nil {
			fmt.Fprintf(out, "  ! Search-Replace mit geladenen Plugins fehlgeschlagen – plugin-serialisierte Optionen können noch %s enthalten (%v)\n", src, err)
			break
		}
	}
	return nil
}

var slugRe = regexp.MustCompile(`^[A-Za-z0-9._-]+$`)

// dropMissingPlugins removes excluded but active plugins from active_plugins. Their files were
// not pulled, so `wp plugin deactivate` would not find them.
func dropMissingPlugins(r localenv.Runner, env agentapi.Env, excluded []string, out io.Writer) error {
	var names, quoted []string
	for _, slug := range excluded {
		if !slugRe.MatchString(slug) {
			continue
		}
		for _, active := range env.ActivePlugins {
			if pluginSlug(active) == slug {
				names = append(names, slug)
				quoted = append(quoted, "'"+slug+"'")
				break
			}
		}
	}
	if len(names) == 0 {
		return nil
	}
	fmt.Fprintf(out, "  lokal deaktiviert (nicht gezogen): %s\n", strings.Join(names, ", "))
	code := "$drop = [" + strings.Join(quoted, ",") + "]; " +
		`update_option("active_plugins", array_values(array_filter((array) get_option("active_plugins", []), function ($p) use ($drop) { ` +
		`$s = strpos($p, "/") === false ? basename($p, ".php") : dirname($p); return !in_array($s, $drop, true); })));`
	return r.Run("wp", "eval", code, "--skip-plugins", "--skip-themes")
}

// pluginSlug turns elementor/elementor.php into elementor and hello.php into hello.
func pluginSlug(file string) string {
	if i := strings.Index(file, "/"); i >= 0 {
		return file[:i]
	}
	return strings.TrimSuffix(file, ".php")
}

// RemoveDropIns deletes caching drop-ins below docroot/wp-content without following a symlinked
// docroot or wp-content (SEC-113).
func RemoveDropIns(docroot string) {
	root, err := openDocroot(docroot, false)
	if err != nil {
		return
	}
	defer root.Close()
	for _, name := range DropIns {
		safefs.Remove(root, filepath.Join("wp-content", name))
	}
}

// MailguardCheck verifies that local-mailguard itself is loaded (not just any wp_mail filter,
// which an SMTP plugin would also register) and its wp_mail filter is active.
func MailguardCheck(r localenv.Runner) error {
	out, err := r.Output("wp", "eval", `echo function_exists("local_mailguard_collect") && has_filter("wp_mail") ? "ok" : "missing";`)
	if err != nil {
		return fmt.Errorf("%w (%v)", ErrMailguardMissing, err)
	}
	if !strings.HasSuffix(strings.TrimSpace(out), "ok") {
		return ErrMailguardMissing
	}
	return nil
}

// localConstants are the wp-config.php constants every local copy needs, with their JSON value.
var localConstants = []struct{ name, json string }{
	{"WP_ENVIRONMENT_TYPE", `"local"`},
	{"DISABLE_WP_CRON", "true"},
}

// setLocalConstants sets the local constants that are missing or differ. A wp-config.php the caller
// provides read-only (Website Studio) and that already defines them is left alone; one that
// differs and cannot be written still fails the post-setup.
func setLocalConstants(r localenv.Runner, out io.Writer) error {
	set := 0
	for _, c := range localConstants {
		got, err := r.Output("wp", "config", "get", c.name, "--type=constant", "--format=json")
		if err == nil && constantMatches(got, c.json) {
			continue
		}
		args := []string{"wp", "config", "set", c.name, strings.Trim(c.json, `"`), "--type=constant"}
		if c.json == "true" {
			args = []string{"wp", "config", "set", c.name, "true", "--raw", "--type=constant"}
		}
		if err := r.Run(args...); err != nil {
			return fmt.Errorf("wp-config.php: %s auf %s setzen: %w", c.name, c.json, err)
		}
		set++
	}
	if set == 0 {
		fmt.Fprintln(out, "  wp-config.php: lokale Konstanten schon gesetzt")
	}
	return nil
}

// constantMatches compares the JSON output of `wp config get --format=json` with the wanted value;
// a constant defined as 1 counts as true.
func constantMatches(got, want string) bool {
	got = strings.TrimSpace(got)
	if got == want {
		return true
	}
	return want == "true" && (got == "1" || got == `"1"`)
}
