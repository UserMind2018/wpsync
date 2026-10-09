package main

import (
	"errors"
	"fmt"
	"path/filepath"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/content"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/pull"
	"github.com/usermind/wpsync/internal/sitelock"
	"github.com/usermind/wpsync/internal/sites"
)

// errNoContentPull: content export needs the content state of a pull with --content – the domain
// map to normalize with and a baseline that still belongs to the working copy (Plan B11).
var errNoContentPull = errors.New("kein aktueller Inhaltsstand aus einem Pull mit --content")

// cmdContent: `wpsync content export <site>` writes the normalized rows and fingerprints of the
// working copy as JSON lines to stdout (Spec Content-Push §4.4). stdout belongs to these lines
// alone: every message, also those of the driver, goes to stderr, and with --json the result
// object follows as the last line (Plan B6). The command changes neither the site nor
// .wpsync/content.
func (a *app) cmdContent(args []string) error {
	const call = "wpsync content export <site> [--json] [--secret-stdin] [--driver container …]"
	a.dataStdout = true
	if len(args) == 0 || args[0] != "export" {
		return cliout.Usage(errors.New("Aufruf: " + call))
	}
	fs := a.flags("content")
	secretStdin := secretStdinFlag(fs)
	df := addDriverFlags(fs)
	positional, err := a.parse(fs, args[1:], exactly(1), call)
	if err != nil {
		return err
	}
	if err := df.needsSecretStdin(*secretStdin); err != nil {
		return err
	}
	site, secret, err := loadSite(positional[0], a.secretStore(*secretStdin))
	if err != nil {
		return err
	}
	root, err := sites.SitesRoot()
	if err != nil {
		return err
	}
	drv, siteDir, _, err := a.siteDriver(df, site, root)
	if err != nil {
		return err
	}
	dir := siteDir
	if dir == "" {
		dir = filepath.Join(root, site.Name) // as pull.Options.dirs
	}
	stale := cliout.Hint(cliout.Usage(errNoContentPull), fmt.Sprintf("%v – zuerst: wpsync pull %s --content", errNoContentPull, site.Name))
	// Before the lock: a site that was never pulled gets no lock file from an export.
	if !content.Fresh(dir) {
		return stale
	}
	lock, err := sitelock.Acquire(sitelock.Path(root, site.Name, siteDir))
	if err != nil {
		return localenv.Wrap("lock", err)
	}
	defer lock.Close()
	// Under the lock no pull is replacing tables or the content state.
	if !content.Fresh(dir) {
		return stale
	}
	// The local URL is the one the pull replaced the live origin with – from map.json, not from
	// the driver: fingerprints must be normalized with exactly that value.
	m, _, err := content.ReadMap(dir)
	if err != nil {
		return stale
	}
	if inContainer, _ := df.isContainer(); inContainer {
		if err := a.configureFromSheet(drv, site, secret); err != nil {
			return err
		}
	}
	rows, err := content.Export(drv.Runner(site.Name), m.Local, nil, a.stdout)
	if err != nil {
		return cliout.Hint(err, fmt.Sprintf("%v – läuft die lokale Umgebung? (wpsync list)", err))
	}
	fmt.Fprintf(a.stderr, "%d Zeilen exportiert\n", rows)
	a.data = map[string]any{"rows": rows, "canon_version": content.CanonVersion}
	return nil
}

// configureFromSheet gives the container driver what a pull takes from /delta: PHP version (the
// WP-CLI image without --cli-image) and table prefix (WORDPRESS_TABLE_PREFIX of the run) of the
// source. Nothing in the site folder records them, so the export asks the site's infosheet – one
// read request. The DDEV driver needs neither for `ddev wp`.
func (a *app) configureFromSheet(drv localenv.Driver, site *sites.Site, secret string) error {
	client := agentapi.New(site.URL, site.KeyID, secret, site.RPS)
	client.Ctx = a.ctx
	sheet, _, err := client.Infosheet()
	if err != nil {
		return explain(err, site)
	}
	if sheet == nil {
		return pullError(pull.ErrNoInfosheet, site)
	}
	if bad := sheet.Env.InvalidArgs(); len(bad) > 0 {
		keys := make([]string, len(bad))
		for i, f := range bad {
			keys[i] = f.Key + " = " + agentapi.Printable(f.Value)
		}
		return fmt.Errorf("%w: %s", agentapi.ErrInvalidEnv, strings.Join(keys, ", "))
	}
	return localenv.Wrap("configure", drv.Configure(sheet.Env))
}
