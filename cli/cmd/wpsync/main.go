// Command wpsync pulls WordPress sites into local DDEV projects – or, in the server mode, into
// WordPress containers that the caller created (Spec Server-Modus).
package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"syscall"
	"text/tabwriter"
	"time"

	"golang.org/x/term"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/container"
	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/keychain"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/mailguard"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/pull"
	"github.com/usermind/wpsync/internal/push"
	"github.com/usermind/wpsync/internal/scan"
	"github.com/usermind/wpsync/internal/secretstore"
	"github.com/usermind/wpsync/internal/setup"
	"github.com/usermind/wpsync/internal/sites"
	"github.com/usermind/wpsync/internal/staging"
)

const usage = `wpsync – WordPress Live ↔ Lokal

  wpsync setup                         einmalig pro Mac (fragt einmal nach dem Passwort)
  wpsync doctor [--server]             Umgebung prüfen (--server: nur was im OS-Container zählt)
  wpsync pair <url> <code> [--name n]  Site koppeln (Code aus Werkzeuge → wpsync; nur https, lokal: --insecure)
  wpsync unpair <site>                 Kopplung lokal entfernen
  wpsync list                          lokale Umgebungen mit Status, lokaler und Live-URL
  wpsync stop <site>… | --all          lokale Umgebung(en) stoppen (Daten bleiben erhalten)
  wpsync scan <site> [--refresh]       zeigen, was auf der Site liegt, und auswählen, was gezogen wird
  wpsync pull <site> [--full] [--yes]  Site nach ~/wpsync-sites/<site> ziehen (--dry-run: nur anzeigen,
                                       --no-anonymize: personenbezogene Daten im Klartext,
                                       --content: Manifest und Baseline für den Inhalts-Push)
  wpsync status <site>                 was sich seit dem letzten Pull geändert hat, ohne Transfer
  wpsync content export <site>         normalisierte Zeilen und Fingerabdrücke der Arbeitskopie als
                                       JSON-Lines auf stdout (braucht einen Pull mit --content)
  wpsync trust <site>                  eigene Änderungen in .ddev ansehen und freigeben
                                       (ohne Terminal: --fingerprint <fp> aus der Anzeige)
  wpsync push <site> code [einheit…]   lokal geänderte Plugins/Themes/mu-plugins auf die Site bringen
                                       (braucht ein offenes Push-Fenster; --dry-run, --force, --yes;
                                       --to staging: auf die Staging-Kopie, Baseline bleibt;
                                       --uploads <liste>: neue Dateien unter wp-content/uploads;
                                       lokal neue Einheiten nur, wenn sie genannt werden)
  wpsync pushes <site>                 Protokoll der Pushes (--confirm <id>: hängenden Push bestätigen)
  wpsync rollback <site> [push-id]     letzten Live-Push bzw. einen bestimmten zurücknehmen (--to staging)
  wpsync staging create <site>         Staging-Kopie auf dem Server anlegen (anonymisiert; --no-anonymize)
  wpsync staging open <site>           Einmal-Link: Zugang und Anmeldung als Staging-Admin (--print)
  wpsync staging refresh <site>        Datenbank neu von Live (--code: auch den Code)
  wpsync staging status <site>         Zustand, Alter, letzte Nutzung, Pushes nach Staging
  wpsync staging delete <site>         Kopie löschen (Tabellen und Ordner)
                                       (alle ausser status brauchen ein offenes Push-Fenster)
  wpsync version

  Server-Modus: --json (pair, scan, pull, status, unpair, doctor, version, staging, push, pushes,
  rollback, content) schreibt JSON auf stdout, Meldungen auf stderr, und fragt nie nach;
  --secret-stdin liest das Secret von stdin (scan, pull, status, staging, push, pushes, rollback,
  content). content export schreibt seine Zeilen immer auf stdout, Meldungen immer auf stderr.
  pull/status/list/stop/content export --driver container --container c --docroot d --db-host h --db-name n
  --db-user u --local-url url [--cli-image i]: vorhandener WordPress-Container statt DDEV
  (DB-Passwort als zweite Zeile von stdin).
  push/pushes/rollback --driver container --docroot d --secret-stdin: Site-Ordner neben dem Docroot
  statt ~/wpsync-sites, Secret nur von stdin, keine Rückfrage; rollback dort nur mit Push-ID.
`

// jsonCommands accept --json (Spec Server-Modus §4, Spec 2b T4). trust, list, stop and setup stay
// text-only.
var jsonCommands = map[string]bool{
	"pair": true, "scan": true, "pull": true, "status": true, "unpair": true, "doctor": true, "version": true,
	"staging": true, "push": true, "pushes": true, "rollback": true, "content": true,
}

// stagingCommands are the subcommands of wpsync staging (Spec 2b 6.1).
var stagingCommands = map[string]bool{"create": true, "refresh": true, "open": true, "status": true, "delete": true}

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM)
	a := &app{ctx: ctx, stdin: os.Stdin, stdout: os.Stdout, stderr: os.Stderr, kc: keychain.MacOS{}}
	code := a.main(os.Args[1:])
	stop()
	os.Exit(code)
}

// app is one wpsync invocation.
type app struct {
	ctx            context.Context
	stdin          io.Reader
	stdout, stderr io.Writer
	kc             keychain.Store // macOS keychain; tests use keychain.NewMemory()
	json           bool
	jw             *cliout.Writer
	data           any // data of the JSON result
	stdinSecrets   *secretstore.Stdin
	browse         func(string) error // opens a URL; nil: open (macOS) or xdg-open
	// dataStdout: stdout carries the data of the command (content export), also without --json.
	dataStdout bool
}

// out receives human messages: stdout – stderr with --json or when stdout carries data.
func (a *app) out() io.Writer {
	if a.json || a.dataStdout {
		return a.stderr
	}
	return a.stdout
}

// interactive: questions only on a terminal, never with --json and never next to data on stdout
// (confirm asks there).
func (a *app) interactive() bool { return !a.json && !a.dataStdout && isTerminal() }

func (a *app) main(args []string) int {
	if len(args) < 1 {
		fmt.Fprint(a.stderr, usage)
		return cliout.ExitUsage
	}
	cmd, rest := args[0], args[1:]
	name := cmd
	if (cmd == "staging" && len(rest) > 0 && stagingCommands[rest[0]]) || (cmd == "content" && len(rest) > 0 && rest[0] == "export") {
		name += " " + rest[0] // "staging create", "content export" – the caller tells the subcommands apart
	}
	a.json = jsonCommands[cmd] && hasJSONFlag(rest)
	if a.json {
		a.jw = cliout.NewWriter(a.stdout)
	}
	err := a.dispatch(cmd, rest)
	if a.json {
		return a.jw.Result(name, a.data, err)
	}
	if err != nil {
		fmt.Fprintf(a.stderr, "\n✗ %v\n", err)
		if pull.IsSuspectedBan(err) {
			fmt.Fprintf(a.stderr, "  Der Server antwortet nicht mehr – vermutlich eine IP-Sperre (fail2ban/WAF).\n"+
				"  Eigene öffentliche IP: %s – im Server-Schutz entsperren und whitelisten, dann erneut versuchen.\n", publicIP())
		}
	}
	return cliout.Classify(err).Exit
}

func (a *app) dispatch(cmd string, args []string) error {
	switch cmd {
	case "setup":
		return a.cmdSetup()
	case "doctor":
		return a.cmdDoctor(args)
	case "pair":
		return a.cmdPair(args)
	case "unpair":
		return a.cmdUnpair(args)
	case "list":
		return a.cmdList(args)
	case "stop":
		return a.cmdStop(args)
	case "scan":
		return a.cmdScan(args)
	case "pull":
		return a.cmdPull(args)
	case "status":
		return a.cmdStatus(args)
	case "content":
		return a.cmdContent(args)
	case "trust":
		return a.cmdTrust(args)
	case "push":
		return a.cmdPush(args)
	case "pushes":
		return a.cmdPushes(args)
	case "rollback":
		return a.cmdRollback(args)
	case "staging":
		return a.cmdStaging(args)
	case "version":
		return a.cmdVersion(args)
	}
	fmt.Fprint(a.stderr, usage)
	return cliout.Usage(fmt.Errorf("unbekannter Befehl %q", cmd))
}

// hasJSONFlag looks for --json before flag parsing, so even a usage error is answered in JSON.
func hasJSONFlag(args []string) bool {
	for _, arg := range args {
		switch arg {
		case "--json", "-json", "--json=true", "-json=true":
			return true
		}
	}
	return false
}

// flags creates a command's flag set; every JSON command knows --json.
func (a *app) flags(name string) *flag.FlagSet {
	fs := flag.NewFlagSet(name, flag.ContinueOnError)
	if jsonCommands[name] {
		fs.Bool("json", false, "maschinenlesbare Ausgabe auf stdout, keine Rückfragen")
	}
	return fs
}

// parse wraps flag and argument errors as usage errors.
func (a *app) parse(fs *flag.FlagSet, args []string, want func(n int) bool, call string) ([]string, error) {
	positional, err := parseInterspersed(fs, args)
	if err != nil {
		return nil, cliout.Usage(err)
	}
	if want != nil && !want(len(positional)) {
		return nil, cliout.Usage(errors.New("Aufruf: " + call))
	}
	return positional, nil
}

func exactly(n int) func(int) bool { return func(got int) bool { return got == n } }

// mailguardSource resolves the mailguard file to mount ($WPSYNC_MAILGUARD or bundled).
func mailguardSource() (path, origin string, err error) {
	dir, err := sites.ConfigDir()
	if err != nil {
		return "", "", err
	}
	return mailguard.Resolve(dir)
}

func (a *app) cmdSetup() error {
	if err := setup.Run(); err != nil {
		return fmt.Errorf("Setup fehlgeschlagen: %w", err)
	}
	return a.cmdDoctor(nil)
}

func (a *app) cmdDoctor(args []string) error {
	fs := a.flags("doctor")
	server := fs.Bool("server", false, "nur die Prüfungen des Server-Modus (ohne DNS, Docker-Version, DDEV)")
	if _, err := a.parse(fs, args, exactly(0), "wpsync doctor [--server] [--json]"); err != nil {
		return err
	}
	source, origin, err := mailguardSource()
	if err != nil {
		origin = err.Error()
	}
	env := setup.SystemEnv(source)
	env.MailguardOrigin = origin
	if !*server {
		return a.report(setup.Doctor(env))
	}
	env.Version = agentapi.Version
	if env.ConfigDir, err = sites.ConfigDir(); err != nil {
		return err
	}
	return a.report(setup.ServerDoctor(env))
}

// report prints doctor checks and fails with local_env if one is red.
func (a *app) report(checks []setup.Check) error {
	failed := 0
	for _, c := range checks {
		mark := "✓"
		if !c.OK {
			mark = "✗"
			failed++
		}
		fmt.Fprintf(a.out(), "%s %s  %s\n", mark, c.Name, c.Detail)
		if !c.OK {
			fmt.Fprintf(a.out(), "    → %s\n", c.Fix)
		}
	}
	a.data = map[string]any{"checks": checks}
	if failed > 0 {
		return localenv.Wrap("doctor", fmt.Errorf("%d Prüfung(en) fehlgeschlagen", failed))
	}
	return nil
}

func (a *app) cmdVersion(args []string) error {
	fs := a.flags("version")
	if _, err := a.parse(fs, args, exactly(0), "wpsync version [--json]"); err != nil {
		return err
	}
	a.data = map[string]string{"version": agentapi.Version, "min_agent_version": agentapi.MinAgentVersion}
	fmt.Fprintln(a.out(), "wpsync "+agentapi.Version)
	return nil
}

// pairResult is the data of pair --json.
type pairResult struct {
	Site                 string `json:"site"`
	URL                  string `json:"url"`
	KeyID                string `json:"key_id"`
	AgentVersion         string `json:"agent_version"`
	RequiredAgentVersion string `json:"required_agent_version"`
	AgentOK              bool   `json:"agent_ok"`
	// Secret only with --secret-out: the caller stores it, wpsync keeps no copy (Spec §5).
	Secret string `json:"secret,omitempty"`
}

func (a *app) cmdPair(args []string) error {
	fs := a.flags("pair")
	name := fs.String("name", "", "lokaler Name der Site (Standard: aus der URL)")
	device := fs.String("device", "", "Gerätename im WP-Admin (Standard: Rechnername)")
	insecure := fs.Bool("insecure", false, "http:// zulassen – nur für lokale Testumgebungen")
	secretOut := fs.Bool("secret-out", false, "Secret einmal im JSON-Ergebnis ausgeben statt in der Keychain speichern (nur mit --json)")
	positional, err := a.parse(fs, args, exactly(2), "wpsync pair <url> <code> [--name n] [--insecure] [--json [--secret-out]]")
	if err != nil {
		return err
	}
	if *secretOut && !a.json {
		return cliout.Usage(errors.New("--secret-out nur zusammen mit --json – sonst stünde das Secret im Terminal"))
	}
	hc := &http.Client{Timeout: 30 * time.Second}
	base, err := agentapi.Discover(hc, positional[0])
	if err != nil {
		return cliout.Hint(err, fmt.Sprintf("wpsync-Agent unter %s nicht gefunden – ist das Plugin aktiv? (%v)", positional[0], err))
	}
	if !*insecure && !agentapi.IsHTTPS(base) {
		return cliout.Usage(fmt.Errorf("%s ist nicht verschlüsselt – Secret und Daten liefen im Klartext über die Leitung. Nur für lokale Testumgebungen: --insecure", base))
	}
	if *name == "" {
		if *name, err = sites.NameFromURL(base); err != nil {
			return cliout.Usage(err)
		}
	}
	if !sites.ValidName(*name) {
		return cliout.Usage(fmt.Errorf("ungültiger Name %q (erlaubt: a-z, 0-9, Bindestrich)", *name))
	}
	if *device == "" {
		host, _ := os.Hostname()
		*device = host
	}
	res, err := agentapi.Pair(hc, base, positional[1], *device)
	if err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Message != "" {
			return cliout.Hint(err, "Kopplung abgelehnt: "+apiErr.Message)
		}
		return fmt.Errorf("Kopplung fehlgeschlagen: %w", err)
	}
	if !*secretOut {
		if err := a.keychainStore().Set(*name, res.Secret); err != nil {
			return fmt.Errorf("Secret konnte nicht in der Keychain gespeichert werden: %w", err)
		}
	}
	if err := sites.Save(&sites.Site{Name: *name, URL: base, KeyID: res.KeyID, RPS: 1, Device: *device}); err != nil {
		return err
	}
	data := pairResult{Site: *name, URL: base, KeyID: res.KeyID, AgentVersion: res.AgentVersion,
		RequiredAgentVersion: agentapi.MinAgentVersion, AgentOK: agentapi.VersionAtLeast(res.AgentVersion, agentapi.MinAgentVersion)}
	if *secretOut {
		data.Secret = res.Secret
	}
	a.data = data
	fmt.Fprintf(a.out(), "✓ %s gekoppelt als %q (Agent %s)\n  Nächster Schritt: wpsync scan %s\n", base, *name, res.AgentVersion, *name)
	return nil
}

func (a *app) cmdUnpair(args []string) error {
	fs := a.flags("unpair")
	positional, err := a.parse(fs, args, exactly(1), "wpsync unpair <site> [--json]")
	if err != nil {
		return err
	}
	site, err := sites.Load(positional[0])
	if err != nil {
		return err
	}
	a.keychainStore().Delete(site.Name)
	if err := sites.Delete(site.Name); err != nil {
		return err
	}
	a.data = map[string]string{"site": site.Name, "url": site.URL}
	fmt.Fprintf(a.out(), "✓ Kopplung %q lokal entfernt.\n  Bitte zusätzlich im WP-Admin unter Werkzeuge → wpsync widerrufen: %s\n", site.Name, site.URL)
	return nil
}

// ddevDriver is the Mac runtime: DDEV projects below the sites root, every project call checked
// against the trusted .ddev state.
func ddevDriver(out, errOut io.Writer) (*ddev.Driver, error) {
	root, err := sites.SitesRoot()
	if err != nil {
		return nil, err
	}
	state, err := ddevStore(root)
	if err != nil {
		return nil, err
	}
	return &ddev.Driver{SitesRoot: root, State: state, Docker: dockerCLI, Out: out, Err: errOut}, nil
}

// localEnvs lists the driver's environments together with the paired sites.
func localEnvs(d localenv.Driver) ([]localenv.Env, error) {
	paired, err := sites.List()
	if err != nil {
		return nil, err
	}
	found, err := d.List()
	if err != nil {
		return nil, localenv.Wrap("list", err)
	}
	return localenv.Merge(found, paired), nil
}

// driverFlags are the switches of the container mode (Spec Server-Modus §3).
type driverFlags struct {
	driver, container, docroot, dbHost, dbName, dbUser, localURL, cliImage *string
}

func addDriverFlags(fs *flag.FlagSet) *driverFlags {
	return &driverFlags{
		driver:    fs.String("driver", "ddev", "Laufzeit: ddev (Mac) oder container (Server)"),
		container: fs.String("container", "", "Container-Modus: WordPress-Container der Site"),
		docroot:   fs.String("docroot", "", "Container-Modus: Docroot (Pfad auf dem Host = im OS-Container)"),
		dbHost:    fs.String("db-host", "", "Container-Modus: Datenbank-Host"),
		dbName:    fs.String("db-name", "", "Container-Modus: Datenbank"),
		dbUser:    fs.String("db-user", "", "Container-Modus: Datenbank-Benutzer (Passwort: zweite Zeile von stdin)"),
		localURL:  fs.String("local-url", "", "Container-Modus: Ziel-URL für Search-Replace"),
		cliImage:  fs.String("cli-image", "", "Container-Modus: WP-CLI-Image (Standard: wordpress:cli-php<PHP der Quelle>)"),
	}
}

// isContainer reports whether --driver container is set.
func (f *driverFlags) isContainer() (bool, error) {
	switch *f.driver {
	case "ddev":
		return false, nil
	case "container":
		return true, nil
	}
	return false, cliout.Usage(fmt.Errorf("unbekannter --driver %q (ddev, container)", *f.driver))
}

// needsSecretStdin: the container mode stores nothing, secret and DB password come from stdin.
func (f *driverFlags) needsSecretStdin(secretStdin bool) error {
	inContainer, err := f.isContainer()
	if err != nil {
		return err
	}
	if inContainer && !secretStdin {
		return cliout.Usage(errors.New("--driver container braucht --secret-stdin (Secret und DB-Passwort über stdin)"))
	}
	return nil
}

// siteDriver builds the driver of one site for pull and returns it with site folder and docroot
// ("" = the DDEV layout below the sites root). DDEV: the trusted .ddev state, docker for the
// hardening checks and the takeover question on a terminal. Container mode: the DB password is
// the next stdin line after the secret (the caller checked --secret-stdin and loaded the secret),
// baseline and snapshot repo live next to the docroot, and the caller mounts the mailguard
// read-only – wpsync only checks that it is active.
func (a *app) siteDriver(f *driverFlags, site *sites.Site, root string) (localenv.Driver, string, string, error) {
	inContainer, err := f.isContainer()
	if err != nil {
		return nil, "", "", err
	}
	if !inContainer {
		guard, _, err := mailguardSource()
		if err != nil {
			return nil, "", "", localenv.Wrap("mailguard", fmt.Errorf("local-mailguard nicht verfügbar – ohne Mail-Schutz kein Pull: %w", err))
		}
		state, err := ddevStore(root)
		if err != nil {
			return nil, "", "", err
		}
		d := &ddev.Driver{SitesRoot: root, MailguardSource: guard, State: state, Docker: dockerCLI, Out: a.out(), Err: a.out()}
		if a.interactive() {
			d.Confirm = a.confirm
		}
		return d, "", "", nil
	}
	password, err := a.stdinSecrets.DBPassword()
	if err != nil {
		return nil, "", "", cliout.Usage(err)
	}
	docroot := *f.docroot
	if docroot != "" {
		docroot = filepath.Clean(docroot)
	}
	cfg := container.Config{
		Site: site.Name, Container: *f.container, Docroot: docroot,
		DBHost: *f.dbHost, DBName: *f.dbName, DBUser: *f.dbUser, DBPassword: password,
		LocalURL: *f.localURL, CLIImage: *f.cliImage,
	}
	if err := cfg.Validate(); err != nil {
		return nil, "", "", cliout.Usage(err)
	}
	d := &container.Driver{Config: cfg, Ctx: a.ctx, Out: a.out(), Err: a.out()}
	return d, filepath.Dir(cfg.Docroot), cfg.Docroot, nil
}

// listDriver is the driver for list and stop: DDEV projects or labelled containers.
func (a *app) listDriver(f *driverFlags, out, errOut io.Writer) (localenv.Driver, error) {
	inContainer, err := f.isContainer()
	if err != nil {
		return nil, err
	}
	if inContainer {
		return &container.Driver{Ctx: a.ctx, Out: out, Err: errOut}, nil
	}
	return ddevDriver(out, errOut)
}

func (a *app) cmdList(args []string) error {
	fs := a.flags("list")
	df := addDriverFlags(fs)
	if _, err := a.parse(fs, args, exactly(0), "wpsync list [--driver ddev|container]"); err != nil {
		return err
	}
	d, err := a.listDriver(df, nil, nil)
	if err != nil {
		return err
	}
	envs, err := localEnvs(d)
	if err != nil {
		return err
	}
	if len(envs) == 0 {
		fmt.Fprintln(a.stdout, "Noch keine Site gekoppelt – wpsync pair <url> <code>")
		return nil
	}
	w := tabwriter.NewWriter(a.stdout, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "SITE\tSTATUS\tLOKAL\tLIVE")
	for _, e := range envs {
		local, live := "–", e.LiveURL
		if e.Status == localenv.StatusRunning {
			local = e.LocalURL
		}
		if live == "" {
			live = "(nicht gekoppelt)"
		}
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\n", e.Name, localenv.Label(e.Status), local, live)
	}
	return w.Flush()
}

func (a *app) cmdStop(args []string) error {
	fs := a.flags("stop")
	all := fs.Bool("all", false, "alle laufenden wpsync-Umgebungen stoppen")
	df := addDriverFlags(fs)
	names, err := a.parse(fs, args, nil, "")
	if err != nil {
		return err
	}
	if *all == (len(names) > 0) {
		return cliout.Usage(errors.New("Aufruf: wpsync stop <site>… oder wpsync stop --all"))
	}
	d, err := a.listDriver(df, a.stdout, a.stderr)
	if err != nil {
		return err
	}
	envs, err := localEnvs(d)
	if err != nil {
		return err
	}
	n, err := localenv.Stop(d, envs, names, *all)
	if err != nil {
		if n > 0 {
			fmt.Fprintf(a.stdout, "%d Umgebung(en) gestoppt.\n", n)
		}
		return err
	}
	switch n {
	case 0:
		fmt.Fprintln(a.stdout, "Nichts zu stoppen – keine der Umgebungen läuft.")
	case 1:
		fmt.Fprintln(a.stdout, "✓ 1 Umgebung gestoppt")
	default:
		fmt.Fprintf(a.stdout, "✓ %d Umgebungen gestoppt\n", n)
	}
	return nil
}

// scanResult is the data of scan --json (Spec Server-Modus §7).
type scanResult struct {
	Site                 string              `json:"site"`
	AgentVersion         string              `json:"agent_version"`
	RequiredAgentVersion string              `json:"required_agent_version"`
	AgentOK              bool                `json:"agent_ok"`
	Infosheet            *agentapi.Infosheet `json:"infosheet"`
	Profile              *profile.Profile    `json:"profile"`
	Requests             int                 `json:"requests"`
}

func (a *app) cmdScan(args []string) error {
	fs := a.flags("scan")
	refresh := fs.Bool("refresh", false, "Infosheet auf der Site neu erstellen")
	preset := fs.String("preset", "", "ohne Rückfrage: ohne-transaktionen | nur-content | vollstaendig")
	since := fs.String("uploads-since", "", "Uploads ab diesem Jahr ziehen, ältere per Proxy; alle = jedes Jahr ziehen (nur mit --preset)")
	var plugins, postTypes listFlag
	fs.Var(&plugins, "exclude-plugin", "Plugin nicht ziehen, mehrfach möglich (nur mit --preset)")
	fs.Var(&postTypes, "exclude-post-type", "Post-Typ nicht ziehen, mehrfach möglich (nur mit --preset)")
	secretStdin := secretStdinFlag(fs)
	positional, err := a.parse(fs, args, exactly(1), "wpsync scan <site> [--refresh] [--preset p] [--json] [--secret-stdin]")
	if err != nil {
		return err
	}
	if *preset == "" && (len(plugins) > 0 || len(postTypes) > 0 || *since != "") {
		return cliout.Usage(errors.New("--exclude-plugin, --exclude-post-type und --uploads-since nur zusammen mit --preset"))
	}
	allUploads := *since == "alle"
	if allUploads {
		*since = ""
	} else if *since != "" && !profile.IsYear(*since) {
		return cliout.Usage(errors.New("--uploads-since erwartet ein Jahr, z. B. 2025, oder alle"))
	}
	site, secret, err := loadSite(positional[0], a.secretStore(*secretStdin))
	if err != nil {
		return err
	}
	client := agentapi.New(site.URL, site.KeyID, secret, site.RPS)
	client.Ctx = a.ctx
	res := scanResult{Site: site.Name, RequiredAgentVersion: agentapi.MinAgentVersion}
	opts := scan.Options{
		Client:  client,
		Current: site.Profile,
		Out:     a.out(),
		Now:     time.Now(),
		Refresh: *refresh,
		Preset:  *preset,
		Adjust:  scan.Adjust{ExcludePlugins: plugins, ExcludePostTypes: postTypes, UploadsSince: *since, AllUploads: allUploads},
		OnSheet: func(s *agentapi.Infosheet) { res.Infosheet = s },
	}
	if *preset == "" && a.interactive() {
		opts.Select = scan.Interactive
	}
	p, err := scan.Run(opts)
	if err != nil {
		if errors.Is(err, scan.ErrNeedsPreset) {
			return cliout.Usage(err)
		}
		return explain(err, site)
	}
	site.Profile = p
	if err := sites.Save(site); err != nil {
		return err
	}
	if res.Infosheet != nil {
		res.AgentVersion = res.Infosheet.Env.AgentVersion
	}
	res.AgentOK = agentapi.VersionAtLeast(res.AgentVersion, agentapi.MinAgentVersion)
	res.Profile, res.Requests = p, client.Stats.Requests
	a.data = res
	fmt.Fprintf(a.out(), "✓ Profil gespeichert. Nächster Schritt: wpsync pull %s\n", site.Name)
	return nil
}

func (a *app) cmdPull(args []string) error {
	fs := a.flags("pull")
	full := fs.Bool("full", false, "alles neu laden (Baseline und vorhandene Dateien ignorieren)")
	yes := fs.Bool("yes", false, "neue Tabellen/Plugins ohne Rückfrage nach dem Preset behandeln")
	dryRun := fs.Bool("dry-run", false, "nur anzeigen, was sich geändert hat (wie wpsync status)")
	withContent := fs.Bool("content", false, "auch das Inhalts-Manifest holen und die Baseline für einen Inhalts-Push bauen (lädt die Inhaltstabellen neu, sobald sich eine geändert hat)")
	noAnon := fs.Bool("no-anonymize", false, "personenbezogene Daten im Klartext ziehen (fragt nach; ohne Terminal zusätzlich --yes)")
	rps := fs.Float64("rps", 0, "max. Requests pro Sekunde (Standard aus der Site-Konfiguration)")
	secretStdin := secretStdinFlag(fs)
	df := addDriverFlags(fs)
	positional, err := a.parse(fs, args, exactly(1), "wpsync pull <site> [--full] [--yes] [--dry-run] [--no-anonymize] [--content] [--json] [--secret-stdin] [--driver container …]")
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
	if *rps > 0 {
		site.RPS = *rps
	}
	root, err := sites.SitesRoot()
	if err != nil {
		return err
	}
	drv, siteDir, docroot, err := a.siteDriver(df, site, root)
	if err != nil {
		return err
	}
	var report pull.Result
	opts := pull.Options{
		Site:            *site,
		Secret:          secret,
		SitesRoot:       root,
		Driver:          drv,
		SiteDir:         siteDir,
		Docroot:         docroot,
		Full:            *full,
		Yes:             *yes,
		NoAnonymize:     *noAnon,
		Content:         *withContent,
		SaveSite:        saveProfile,
		Out:             a.out(),
		RowsPerChunk:    2000,
		FileBundleBytes: 16 << 20,
		DBBundleBytes:   8 << 20,
		Ctx:             a.ctx,
		Report:          &report,
	}
	if a.interactive() {
		opts.Confirm = a.confirm
	}
	if a.json {
		opts.Progress = a.jw.Phase
	}
	if *dryRun {
		return a.status(opts, site, true)
	}
	if err := pull.Run(opts); err != nil {
		return pullError(err, site)
	}
	a.data = report
	return nil
}

func (a *app) cmdStatus(args []string) error {
	fs := a.flags("status")
	secretStdin := secretStdinFlag(fs)
	df := addDriverFlags(fs)
	positional, err := a.parse(fs, args, exactly(1), "wpsync status <site> [--json] [--secret-stdin] [--driver container --docroot d]")
	if err != nil {
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
	opts := pull.Options{Site: *site, Secret: secret, SitesRoot: root, Out: a.out(), Ctx: a.ctx}
	inContainer, err := df.isContainer()
	if err != nil {
		return err
	}
	if inContainer {
		if *df.docroot == "" {
			return cliout.Usage(errors.New("--driver container braucht --docroot (absoluter Pfad)"))
		}
		if err := container.CheckDocroot(*df.docroot); err != nil {
			return cliout.Usage(err)
		}
		opts.Docroot = filepath.Clean(*df.docroot)
		opts.SiteDir = filepath.Dir(opts.Docroot)
	}
	return a.status(opts, site, false)
}

// status prints the changes since the last pull, or returns them as JSON data. For pull --dry-run
// (dryRun) the JSON says "dry_run" and that nothing was pulled, like push --dry-run.
func (a *app) status(opts pull.Options, site *sites.Site, dryRun bool) error {
	if !a.json {
		return pullError(pull.Status(opts), site)
	}
	res, err := pull.StatusReport(opts)
	if err != nil {
		return pullError(err, site)
	}
	if dryRun {
		res.MarkDryRun()
	}
	a.data = res
	return nil
}

// pushMode holds the switches of push, pushes and rollback for the container mode (Spec
// Container-Push C1): driver, docroot and the secret from stdin – the push needs neither the
// WordPress container nor the database, so --container, --db-* and --local-url do not exist here.
type pushMode struct {
	driver, docroot *string
	secretStdin     *bool
}

func addPushMode(fs *flag.FlagSet) *pushMode {
	return &pushMode{
		driver:      fs.String("driver", "ddev", "Laufzeit: ddev (Mac) oder container (Server: mit --docroot und --secret-stdin)"),
		docroot:     fs.String("docroot", "", "Container-Modus: Docroot (Pfad auf dem Host = im OS-Container)"),
		secretStdin: fs.Bool("secret-stdin", false, "Container-Modus: Kopplungs-Secret als erste Zeile von stdin lesen"),
	}
}

func (m *pushMode) container() bool { return *m.driver == "container" }

// dirs checks the combination and returns site folder and docroot ("" on the Mac). The container
// mode takes the secret from stdin and nothing else; the Mac layout without the keychain is no
// supported case.
func (m *pushMode) dirs() (siteDir, docroot string, err error) {
	switch *m.driver {
	case "ddev":
		if *m.secretStdin {
			return "", "", cliout.Usage(errors.New("--secret-stdin bei push, pushes und rollback nur mit --driver container --docroot <pfad>"))
		}
		if *m.docroot != "" {
			return "", "", cliout.Usage(errors.New("--docroot nur mit --driver container"))
		}
		return "", "", nil
	case "container":
	default:
		return "", "", cliout.Usage(fmt.Errorf("unbekannter --driver %q (ddev, container)", *m.driver))
	}
	if !*m.secretStdin {
		return "", "", cliout.Usage(errors.New("--driver container braucht --secret-stdin (das Secret kommt über stdin)"))
	}
	if *m.docroot == "" {
		return "", "", cliout.Usage(errors.New("--driver container braucht --docroot (absoluter Pfad)"))
	}
	if err := container.CheckDocroot(*m.docroot); err != nil {
		return "", "", cliout.Usage(err)
	}
	docroot = filepath.Clean(*m.docroot)
	return filepath.Dir(docroot), docroot, nil
}

// pushOptions loads the site and builds the options shared by push, pushes and rollback: on the
// Mac with the keychain and ~/wpsync-sites, in the container mode with the secret from stdin and
// the site folder next to the docroot. With --json or --secret-stdin nothing asks – stdin carries
// the secret, a question would read from there (Spec Container-Push C6, S-9).
func (a *app) pushOptions(name string, mode *pushMode) (push.Options, *sites.Site, error) {
	siteDir, docroot, err := mode.dirs()
	if err != nil {
		return push.Options{}, nil, err
	}
	site, secret, err := loadSite(name, a.secretStore(*mode.secretStdin))
	if err != nil {
		return push.Options{}, nil, err
	}
	root, err := sites.SitesRoot()
	if err != nil {
		return push.Options{}, nil, err
	}
	opts := push.Options{Site: *site, Secret: secret, SitesRoot: root, SiteDir: siteDir, Docroot: docroot, Out: a.out()}
	if a.interactive() && !*mode.secretStdin {
		opts.Confirm = a.confirm
	}
	return opts, site, nil
}

func (a *app) cmdPush(args []string) error {
	const call = "wpsync push <site> code [plugins/<slug> | themes/<slug> | mu-plugins]… [--uploads <liste>] [--content <package.jsonl>] [--no-code] [--to staging] [--dry-run] [--force] [--yes] [--json] [--driver container --docroot d --secret-stdin]"
	fs := a.flags("push")
	force := fs.Bool("force", false, "überschreiben, obwohl sich die Site seit dem letzten Pull geändert hat (alter Stand bleibt als Snapshot)")
	yes := fs.Bool("yes", false, "ohne Rückfrage pushen")
	allowVersion := fs.Bool("allow-version-change", false, "mit --yes: geänderte Plugin-/Theme-Version akzeptieren")
	dryRun := fs.Bool("dry-run", false, "nur anzeigen, was gepusht würde")
	to := fs.String("to", push.TargetLive, "Ziel: live oder staging")
	uploads := fs.String("uploads", "", "Datei mit neuen Uploads: ein Pfad je Zeile relativ zu wp-content/uploads/ (# Kommentar)")
	contentFile := fs.String("content", "", "Inhalts-Paket (package.jsonl), gebaut gegen den letzten Pull mit --content")
	noCode := fs.Bool("no-code", false, "keinen Code pushen: nur --uploads und --content")
	rps := fs.Float64("rps", 0, "max. Requests pro Sekunde (Standard aus der Site-Konfiguration)")
	mode := addPushMode(fs)
	positional, err := a.parse(fs, args, func(n int) bool { return n >= 2 }, call)
	if err != nil {
		return err
	}
	if positional[1] != "code" {
		return cliout.Usage(errors.New("Aufruf: " + call))
	}
	if *to == "" {
		// An empty target means live to the push package; a caller that passes an empty variable
		// must not end up there.
		return cliout.Usage(push.ErrTarget)
	}
	opts, site, err := a.pushOptions(positional[0], mode)
	if err != nil {
		return err
	}
	if *rps > 0 {
		opts.Site.RPS = *rps
	}
	// --no-code: a set of uploads and content alone. Naming units with it, or nothing to push at
	// all, is a mistake of the caller – never a push of everything that changed.
	if *noCode && len(positional) > 2 {
		return cliout.Usage(errors.New("--no-code und genannte Einheiten schliessen sich aus"))
	}
	if *noCode && *uploads == "" && *contentFile == "" {
		return cliout.Usage(errors.New("--no-code braucht --uploads oder --content"))
	}
	opts.Ctx = a.ctx // SIGTERM stops the push before the swap (C12)
	opts.Units = positional[2:]
	opts.Content, opts.NoCode = *contentFile, *noCode
	if *uploads != "" {
		list, err := push.ReadUploadList(*uploads)
		if err != nil {
			return cliout.Usage(err)
		}
		opts.Uploads = list
	}
	opts.Force, opts.Yes, opts.AllowVersionChange, opts.DryRun = *force, *yes, *allowVersion, *dryRun
	opts.Target = *to // as typed: the push package refuses what it does not know
	var res push.Result
	opts.Report = &res
	if a.json {
		opts.Event = a.jw.Event
	}
	err = push.Run(opts)
	if res.Units != nil {
		a.data = res // also with an error: push_id and status say what happened on the site
	}
	return pushError(err, site)
}

func (a *app) cmdPushes(args []string) error {
	fs := a.flags("pushes")
	confirmID := fs.String("confirm", "", "einen getauschten, aber nicht bestätigten Push als in Ordnung markieren")
	mode := addPushMode(fs)
	positional, err := a.parse(fs, args, exactly(1), "wpsync pushes <site> [--confirm <push-id>] [--json] [--driver container --docroot d --secret-stdin]")
	if err != nil {
		return err
	}
	opts, site, err := a.pushOptions(positional[0], mode)
	if err != nil {
		return err
	}
	if *confirmID != "" {
		var report push.Result
		opts.Report = &report
		if err := push.ConfirmPending(opts, *confirmID); err != nil {
			return pushError(err, site)
		}
		data := map[string]any{"push_id": *confirmID, "status": report.Status}
		if len(report.Warnings) > 0 {
			data["warnings"] = report.Warnings // content_kept: closed as rolled back, the content stays
		}
		a.data = data
		return nil
	}
	if a.json {
		records, err := push.List(opts)
		if err != nil {
			return pushError(err, site)
		}
		if records == nil {
			records = []agentapi.PushRecord{}
		}
		if !mode.container() {
			a.data = map[string]any{"pushes": records}
			return nil
		}
		// The container mode names the journals of this site folder and their rescue URL (C2).
		entries, err := push.WithJournals(opts, records)
		if err != nil {
			return err
		}
		a.data = map[string]any{"pushes": entries}
		return nil
	}
	return pushError(push.Pushes(opts), site)
}

func (a *app) cmdRollback(args []string) error {
	fs := a.flags("rollback")
	to := fs.String("to", "", "ohne Push-ID: den neuesten Push dieses Ziels (live, staging); Standard: live")
	mode := addPushMode(fs)
	positional, err := a.parse(fs, args, func(n int) bool { return n == 1 || n == 2 }, "wpsync rollback <site> [push-id] [--to staging] [--json] [--driver container --docroot d --secret-stdin]")
	if err != nil {
		return err
	}
	// Without an ID the CLI picks the newest push over every pairing of the site; a caller in the
	// container mode knows its push_id and must not leave that to a guess (C14).
	if mode.container() && len(positional) == 1 {
		return cliout.Usage(errors.New("im Container-Modus nur mit Push-ID: wpsync rollback <site> <push-id> --driver container --docroot d --secret-stdin"))
	}
	opts, site, err := a.pushOptions(positional[0], mode)
	if err != nil {
		return err
	}
	// Without --to the target stays unnamed: a push ID then decides itself where it is taken
	// back, and without an ID it is the newest live push (V10).
	opts.Target = *to
	var res push.Result
	opts.Report = &res
	id := ""
	if len(positional) == 2 {
		id = positional[1]
	}
	err = push.Rollback(opts, id)
	if res.PushID != "" {
		a.data = res
	}
	return pushError(err, site)
}

// contentNext is the next step after a refusal of the content of a push, by its reason.
func contentNext(reason string, site *sites.Site) string {
	switch reason {
	case "conflict", "id_taken", "baseline_outdated", "row_unfaithful":
		return fmt.Sprintf(" – die Site hat sich seit dem Pull geändert oder das Paket ist älter als der Inhaltsstand: wpsync pull %s --content, Änderungen neu anlegen, Paket neu bauen; nichts wurde übertragen", site.Name)
	case "author_unknown":
		return fmt.Sprintf(" – das Push-Fenster im WP-Admin öffnen (%s/wp-admin/tools.php?page=wpsync), nicht per WP-CLI: der Benutzer, der es öffnet, wird Autor neuer Beiträge", site.URL)
	case "changed_since_push":
		return " – nichts wurde zurückgenommen, auch Code und Uploads nicht. Die genannten Zeilen auf der Site von Hand prüfen; stehen sie wieder auf dem gepushten Stand, geht die Rücknahme"
	case "before_image_invalid":
		return " – nichts wurde zurückgenommen, auch Code und Uploads nicht. Das Vorher-Abbild liegt geschützt im Arbeitsordner des Pushs: wurden WPSYNC_KEY oder die Salts in wp-config.php seit dem Push geändert, lässt es sich nicht mehr öffnen"
	case "package_too_large":
		return " – in mehreren Pushes übertragen"
	case "upload_missing":
		return " – die Dateien mit --uploads im selben Push mitschicken"
	}
	return " – nichts wurde übertragen"
}

// adminURL is the wpsync page in the WP admin, where an administrator opens the push window.
func adminURL(site *sites.Site) string {
	return strings.TrimRight(site.URL, "/") + "/wp-admin/tools.php?page=wpsync"
}

// windowInfo adds to an exit 40 the admin page and the device whose window it is, as far as pair
// stored it (Spec Container-Push C8).
func windowInfo(err error, site *sites.Site) error {
	if err == nil || cliout.Classify(err).Exit != cliout.ExitPushWindowClosed {
		return err
	}
	return &cliout.WindowError{Err: err, Device: site.Device, AdminURL: adminURL(site)}
}

// pushError turns push errors into the next step; the original error stays for the exit code.
func pushError(err error, site *sites.Site) error { return windowInfo(pushHint(err, site), site) }

// pushHint adds the next step to a push error.
func pushHint(err error, site *sites.Site) error {
	var pending *push.PendingError
	var rolled *push.RolledBackError
	var skipped *push.SkippedNewError
	var apiErr *agentapi.APIError
	var blocked *push.RescueBlockedError
	var refused *push.ContentError
	switch {
	case err == nil:
		return nil
	case errors.Is(err, push.ErrAgentNoContent):
		return cliout.Hint(&agentapi.OutdatedError{Required: agentapi.MinAgentContentPush, Err: err},
			fmt.Sprintf("der wpsync-Agent auf %s kann noch keine Inhalte pushen – Agent %s installieren", site.URL, agentapi.MinAgentContentPush))
	case errors.As(err, &refused):
		if refused.Reason == "content_failed" && len(refused.Keys) > 0 {
			// Only an unrestored row gives content_failed a key: the transaction lost its connection and
			// one write could not be taken back.
			return cliout.Hint(err, fmt.Sprintf("%v – die genannte Zeile auf der Site von Hand prüfen; das Vorher-Abbild des Pushs liegt dafür weiter im Arbeitsordner auf dem Server", err))
		}
		return cliout.Hint(err, fmt.Sprintf("%v%s", err, contentNext(refused.Reason, site)))
	case errors.Is(err, push.ErrNoBaseline):
		return cliout.Hint(cliout.Usage(err), fmt.Sprintf("für %s gibt es noch keinen Pull – zuerst wpsync pull %s", site.Name, site.Name))
	case errors.Is(err, push.ErrAgentNoUploads):
		return cliout.Hint(&agentapi.OutdatedError{Required: push.MinAgentUploads, Err: err},
			fmt.Sprintf("der wpsync-Agent auf %s kennt noch keine Uploads – Agent %s installieren", site.URL, push.MinAgentUploads))
	case errors.Is(err, push.ErrUploadsThere):
		return err
	case errors.Is(err, push.ErrUploadExists):
		return cliout.Hint(err, fmt.Sprintf("%v – ein Push ersetzt nie eine Datei unter uploads, auch nicht mit --force; nichts übertragen. Die Datei lokal umbenennen oder aus der Liste nehmen", err))
	case errors.Is(err, push.ErrUploadTypeBlocked):
		why := err.Error()
		if errors.As(err, &apiErr) {
			why = agentText(apiErr.Message)
		}
		return cliout.Hint(err, why+" – PHP, .htaccess, .user.ini, versteckte Dateien, aktive Typen (SVG, HTML, XML, JavaScript), Typen, die WordPress auf der Site nicht erlaubt, und Namen, die WordPress umbenennen würde (Leerzeichen, Sonderzeichen, mittlere Endungen), gehen nie als Upload auf die Site")
	case errors.Is(err, push.ErrUploadMissing):
		return cliout.Hint(cliout.Usage(err), fmt.Sprintf("%v – die Liste von --uploads nennt Dateien relativ zu wp-content/uploads/ der lokalen Site", err))
	case errors.As(err, &skipped):
		return cliout.Hint(err, fmt.Sprintf("nichts gepusht – lokal neu sind nur %s; neue Einheiten gehen nur mit ausdrücklicher Nennung auf die Site: wpsync push %s code <einheit>",
			strings.Join(skipped.Units, ", "), site.Name))
	case errors.Is(err, push.ErrNothing):
		return cliout.Hint(err, fmt.Sprintf("nichts zu pushen – lokal ist nichts geändert seit dem letzten Pull von %s", site.Name))
	case errors.Is(err, push.ErrRollbackWindow):
		return cliout.Hint(err, fmt.Sprintf("%v – ein Administrator muss das Push-Fenster unter %s/wp-admin/tools.php?page=wpsync öffnen oder den Push dort selbst zurückrollen", err, site.URL))
	case errors.Is(err, push.ErrWindowClosed):
		return cliout.Hint(err, fmt.Sprintf("das Push-Fenster ist geschlossen – öffnen unter %s/wp-admin/tools.php?page=wpsync", site.URL))
	case errors.Is(err, push.ErrConflict):
		return cliout.Hint(err, fmt.Sprintf("die Site hat sich seit dem letzten Pull geändert – zuerst wpsync pull %s, lokal zusammenführen und erneut pushen (bewusst überschreiben: --force)", site.Name))
	case errors.Is(err, push.ErrNotWritable):
		return cliout.Hint(err, "der Webserver darf das Verzeichnis nicht ersetzen – Besitzer und Rechte von wp-content/plugins bzw. themes auf dem Server prüfen")
	case errors.Is(err, push.ErrAgentTooOld):
		return cliout.Hint(&agentapi.OutdatedError{Required: push.MinAgent, Err: err},
			fmt.Sprintf("der wpsync-Agent auf %s kann noch nicht pushen – Agent %s installieren", site.URL, push.MinAgent))
	case errors.Is(err, push.ErrVersionChange):
		return cliout.Hint(cliout.Usage(err), "die Versionsnummer ändert sich (mögliche Datenbank-Migration) – im Terminal bestätigen oder --yes --allow-version-change angeben")
	case errors.As(err, &blocked):
		return cliout.Hint(err, fmt.Sprintf("%v – ohne Rückweg wird nicht gepusht. Wahrscheinliche Ursache: %s. Dort PHP unter wp-content/plugins erlauben – oder dem Webserver Schreibrechte im Webroot geben, dann legt der Agent einen Stub dort an", err, push.HardeningHint(blocked.Plugins)))
	case errors.Is(err, push.ErrRescueUnreachable):
		return cliout.Hint(err, fmt.Sprintf("%v – ohne Rückweg wird nicht gepusht. Sperrt ein Sicherheits-Plugin oder der Server direkte PHP-Aufrufe unter wp-content/plugins/, und darf der Webserver nicht in den Webroot schreiben (Agent ab 0.5.1 legt dort sonst einen Stub an)?", err))
	case errors.As(err, &pending):
		return cliout.Hint(err, fmt.Sprintf("%v.\n  Site prüfen, dann entweder  wpsync pushes %s --confirm %s\n  oder                        wpsync rollback %s %s", err, site.Name, push.ShowID(pending.PushID), site.Name, push.ShowID(pending.PushID)))
	case errors.As(err, &rolled):
		if slices.Contains(rolled.Warnings, push.WarningContentNotRolledBack) {
			return cliout.Hint(err, fmt.Sprintf("%v.\n  Code und Uploads sind zurück, die Inhalte des Pushs stehen noch auf der Site (der Agent hat nicht geantwortet).\n"+
				"  Sobald WordPress wieder antwortet: wpsync rollback %s %s", err, site.Name, push.ShowID(rolled.PushID)))
		}
		if len(rolled.StillWorse) > 0 {
			return cliout.Hint(err, fmt.Sprintf("%v.\n  Nach dem Rollback noch auffällig: %s", err, strings.Join(rolled.StillWorse, "; ")))
		}
		return cliout.Hint(err, fmt.Sprintf("%v.\n  Die Site ist wieder auf dem alten Stand; lokal ist nichts verändert", err))
	case errors.Is(err, push.ErrNeedsYes):
		return cliout.Hint(err, "ohne Terminal (oder mit --json) mit --yes bestätigen")
	case errors.Is(err, push.ErrAgentNoStaging):
		return cliout.Hint(&agentapi.OutdatedError{Required: staging.MinAgent, Err: err},
			fmt.Sprintf("der wpsync-Agent auf %s kennt noch kein Staging – Agent %s installieren", site.URL, staging.MinAgent))
	case errors.Is(err, staging.ErrMissing):
		return cliout.Hint(err, fmt.Sprintf("es gibt keine Staging-Kopie – anlegen mit wpsync staging create %s", site.Name))
	case errors.Is(err, staging.ErrLocked):
		return cliout.Hint(err, fmt.Sprintf("die Staging-Kopie ist gesperrt – entsperren mit wpsync staging open %s", site.Name))
	case errors.Is(err, staging.ErrBusy):
		return cliout.Hint(err, fmt.Sprintf("auf der Staging-Kopie läuft gerade ein Job – Stand: wpsync staging status %s", site.Name))
	case errors.As(err, &apiErr) && apiErr.Code == "rest_no_route":
		return cliout.Hint(&agentapi.OutdatedError{Required: push.MinAgent, Err: err},
			fmt.Sprintf("der wpsync-Agent auf %s kann noch nicht pushen – Agent %s installieren", site.URL, push.MinAgent))
	case errors.As(err, &apiErr) && (strings.HasPrefix(apiErr.Code, "wpsync_push_") || strings.HasPrefix(apiErr.Code, "wpsync_staging_") || strings.HasPrefix(apiErr.Code, "wpsync_upload_") || strings.HasPrefix(apiErr.Code, "wpsync_content_")):
		return cliout.Hint(err, agentText(apiErr.Message))
	}
	return explain(err, site)
}

const stagingCall = "wpsync staging create|refresh|open|status|delete <site> [--yes] [--no-anonymize] [--code] [--print] [--json] [--secret-stdin]"

// cmdStaging runs the staging commands (Spec 2b 6.1). They need no local files, so the server
// mode can call them with --secret-stdin (V11).
func (a *app) cmdStaging(args []string) error {
	if len(args) == 0 || strings.HasPrefix(args[0], "-") {
		return cliout.Usage(errors.New("Aufruf: " + stagingCall))
	}
	sub := args[0]
	if !stagingCommands[sub] {
		return cliout.Usage(fmt.Errorf("unbekannter Staging-Befehl %s – %s", agentapi.Printable(sub), stagingCall))
	}
	fs := a.flags("staging")
	yes := fs.Bool("yes", false, "ohne Rückfrage")
	noAnon := fs.Bool("no-anonymize", false, "create/refresh: personenbezogene Daten im Klartext (fragt nach; ohne Terminal zusätzlich --yes)")
	code := fs.Bool("code", false, "refresh: auch den Code neu von Live (gepushter Code geht verloren)")
	printOnly := fs.Bool("print", false, "open: Link nur ausgeben, keinen Browser öffnen")
	rps := fs.Float64("rps", 0, "max. Requests pro Sekunde (Standard aus der Site-Konfiguration)")
	secretStdin := secretStdinFlag(fs)
	positional, err := a.parse(fs, args[1:], exactly(1), stagingCall)
	if err != nil {
		return err
	}
	switch {
	case *code && sub != "refresh":
		return cliout.Usage(errors.New("--code nur mit wpsync staging refresh"))
	case *noAnon && sub != "create" && sub != "refresh":
		return cliout.Usage(errors.New("--no-anonymize nur mit wpsync staging create oder refresh"))
	case *printOnly && sub != "open":
		return cliout.Usage(errors.New("--print nur mit wpsync staging open"))
	}
	site, secret, err := loadSite(positional[0], a.secretStore(*secretStdin))
	if err != nil {
		return err
	}
	if *rps > 0 {
		site.RPS = *rps
	}
	client := agentapi.New(site.URL, site.KeyID, secret, site.RPS)
	client.Ctx = a.ctx
	opts := staging.Options{Site: *site, Client: client, Out: a.out(), Yes: *yes, NoAnonymize: *noAnon, Code: *code}
	if a.interactive() {
		opts.Confirm = a.confirm
	}
	if a.json {
		opts.Progress = a.jw.Phase
	}
	switch sub {
	case "create", "refresh":
		run, done := staging.Create, "angelegt"
		if sub == "refresh" {
			run, done = staging.Refresh, "aufgefrischt"
		}
		res, err := run(opts)
		if errors.Is(err, staging.ErrBusy) {
			res, err = a.resumeStaging(sub, opts, err)
		}
		if err == nil && res == nil {
			err = errors.New("der Staging-Job endete ohne Ergebnis – Stand: wpsync staging status " + site.Name)
		}
		if err != nil {
			return stagingError(err, site)
		}
		a.data = res
		// An address outside the paired site arrives empty (staging.Create) and is not shown.
		at := ""
		if res.URL != "" {
			at = ": " + shown(res.URL)
		}
		fmt.Fprintf(a.out(), "\n✓ Staging %s%s\n  Öffnen:      wpsync staging open %s\n  Code testen: wpsync push %s code <einheit> --to staging\n",
			done, at, site.Name, site.Name)
		if !res.Anonymized {
			fmt.Fprintln(a.out(), "  ! die Kopie enthält personenbezogene Daten im KLARTEXT")
		}
		if res.SkippedValues > 0 {
			fmt.Fprintf(a.out(), "  ! %d serialisierte Werte liessen sich nicht lesen und zeigen noch auf Live\n", res.SkippedValues)
		}
		return nil
	case "open":
		l, err := staging.Open(opts)
		if err != nil {
			return stagingError(err, site)
		}
		// The link carries the token: it goes to the browser, to stdout on request, or into the
		// JSON result – into no other message.
		a.data = struct {
			*agentapi.StagingLogin
			Warnings []string `json:"warnings"`
		}{l, []string{staging.BrowserWarning}}
		switch {
		case a.json:
		case *printOnly:
			fmt.Fprintf(a.stderr, "! %s\n", staging.BrowserWarning) // stdout carries the link alone
			fmt.Fprintln(a.stdout, l.URL)
		default:
			fmt.Fprintf(a.stdout, "! %s\n  (nur den Link: wpsync staging open %s --print)\n", staging.BrowserWarning, site.Name)
			if err := a.openURL(l.URL); err != nil {
				fmt.Fprintf(a.stdout, "Browser nicht geöffnet (%v) – Link (5 Minuten, einmal gültig):\n%s\n", err, l.URL)
				return nil
			}
			fmt.Fprintln(a.stdout, "✓ Staging im Browser geöffnet – angemeldet als wpsync (Link 5 Minuten, einmal gültig)")
		}
		return nil
	case "status":
		st, err := staging.Status(opts)
		if st != nil {
			a.data = st // also for a missing or locked copy
		}
		return stagingError(err, site)
	}
	err = staging.Delete(opts)
	if errors.Is(err, staging.ErrBusy) {
		_, err = a.resumeStaging(sub, opts, err)
	}
	if err != nil {
		return stagingError(err, site)
	}
	a.data = map[string]string{"site": site.Name, "status": "deleted"}
	fmt.Fprintln(a.out(), "✓ Staging-Kopie gelöscht")
	return nil
}

// resumeStaging picks up the job a run of the same command left on the server when it was
// interrupted: the agent answers the next begin with busy until the job has ended. The user has
// confirmed this command by then, so stepping on is what was asked for. Anything else stays
// busy: a job of another kind, one that treats personal data differently than this call asks
// for, the cleanup of a failed job, a push – and a create that still waits for its probe,
// because a step without a verdict takes the copy down.
func (a *app) resumeStaging(sub string, opts staging.Options, busy error) (*agentapi.StagingResult, error) {
	want := map[string]string{"create": agentapi.StagingCreating, "refresh": agentapi.StagingRefreshing, "delete": agentapi.StagingDeleting}[sub]
	st, err := opts.Client.StagingStatus()
	switch {
	case err != nil, !st.Exists, st.Job == nil, st.Job.Finished(), st.Status != want:
		return nil, busy
	case sub == "create" && st.Job.Phase == "probe":
		return nil, busy
	case sub != "delete" && st.Anonymized == opts.NoAnonymize:
		return nil, busy
	}
	fmt.Fprintf(a.out(), "Ein unterbrochener Lauf von wpsync staging %s hat seinen Job auf dem Server gelassen – er wird fortgesetzt.\n", sub)
	return staging.Resume(opts)
}

// stagingError adds the next step to staging errors; the original error stays for the exit code.
func stagingError(err error, site *sites.Site) error { return windowInfo(stagingHint(err, site), site) }

// stagingHint adds the next step to a staging error.
func stagingHint(err error, site *sites.Site) error {
	var apiErr *agentapi.APIError
	switch {
	case err == nil:
		return nil
	case errors.Is(err, staging.ErrNoProfile):
		return cliout.Hint(cliout.Usage(err), fmt.Sprintf("noch kein Pull-Profil – Staging kopiert nach Profil: zuerst wpsync scan %s", site.Name))
	case errors.Is(err, staging.ErrNoInfosheet):
		return cliout.Hint(err, fmt.Sprintf("die Site hat noch kein Infosheet – wpsync scan %s --refresh", site.Name))
	case errors.Is(err, staging.ErrNeedsYes):
		return cliout.Hint(err, "ohne Terminal (oder mit --json) mit --yes bestätigen")
	case errors.Is(err, staging.ErrMissing):
		return cliout.Hint(err, fmt.Sprintf("es gibt keine Staging-Kopie – anlegen mit wpsync staging create %s", site.Name))
	case errors.Is(err, staging.ErrExists):
		return cliout.Hint(err, fmt.Sprintf("es gibt schon eine Staging-Kopie – auffrischen mit wpsync staging refresh %s, öffnen mit wpsync staging open %s oder löschen mit wpsync staging delete %s", site.Name, site.Name, site.Name))
	case errors.Is(err, staging.ErrLocked):
		return cliout.Hint(err, fmt.Sprintf("die Staging-Kopie ist nach 14 Tagen ohne Nutzung gesperrt – entsperren mit wpsync staging open %s", site.Name))
	case errors.Is(err, staging.ErrBusy):
		return cliout.Hint(err, fmt.Sprintf("auf der Staging-Kopie wird gerade gearbeitet (Job oder Push) – später erneut versuchen; Stand: wpsync staging status %s", site.Name))
	case errors.Is(err, staging.ErrWindowClosed):
		return cliout.Hint(err, fmt.Sprintf("das Push-Fenster ist geschlossen – Staging anlegen, auffrischen, öffnen und löschen geht nur bei offenem Fenster; ein Administrator öffnet es unter %s/wp-admin/tools.php?page=wpsync (wpsync staging status geht immer)", site.URL))
	case errors.As(err, &apiErr) && apiErr.Code == "wpsync_staging_pending":
		return cliout.Hint(err, fmt.Sprintf("%s Protokoll: wpsync pushes %s", agentText(apiErr.Message), site.Name))
	case errors.As(err, &apiErr) && strings.HasPrefix(apiErr.Code, "wpsync_staging_"):
		return cliout.Hint(err, agentText(apiErr.Message))
	}
	return explain(err, site)
}

// openURL opens a link in the browser: open on the Mac, xdg-open on a Linux desktop.
func (a *app) openURL(u string) error {
	if a.browse != nil {
		return a.browse(u)
	}
	name := "xdg-open"
	if runtime.GOOS == "darwin" {
		name = "open"
	}
	return exec.Command(name, u).Start()
}

// agentText makes a message of the agent safe for the terminal and keeps its line breaks (the
// nginx rule of a failed probe).
func agentText(s string) string {
	lines := strings.Split(s, "\n")
	for i, l := range lines {
		lines[i] = agentapi.CleanText(l)
	}
	return strings.Join(lines, "\n")
}

// shown returns a name or address from the agent for the terminal: as it is when that is safe,
// else quoted.
func shown(s string) string {
	if agentapi.CleanText(s) == s {
		return s
	}
	return agentapi.Printable(s)
}

// pullError adds the next step to profile-related errors; the original error stays for the exit code.
func pullError(err error, site *sites.Site) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, pull.ErrNoProfile):
		return cliout.Hint(cliout.Usage(err), fmt.Sprintf("noch kein Pull-Profil – zuerst wpsync scan %s", site.Name))
	case errors.Is(err, pull.ErrNoInfosheet):
		return cliout.Hint(err, fmt.Sprintf("die Site hat noch kein Infosheet – wpsync scan %s --refresh", site.Name))
	case errors.Is(err, pull.ErrAgentCannotAnonymize):
		return cliout.Hint(err, fmt.Sprintf("der wpsync-Agent auf %s kann noch nicht anonymisieren – Agent %s installieren. Bewusst im Klartext: wpsync pull %s --no-anonymize", site.URL, agentapi.MinAgentVersion, site.Name))
	case errors.Is(err, pull.ErrPlainNeedsConfirmation):
		return cliout.Hint(err, "--no-anonymize zieht personenbezogene Daten im Klartext – im Terminal bestätigen oder --yes angeben")
	}
	return explain(err, site)
}

// saveProfile stores only the profile, so flags like --rps never end up in the site config.
func saveProfile(s *sites.Site) error {
	stored, err := sites.Load(s.Name)
	if err != nil {
		return err
	}
	stored.Profile = s.Profile
	return sites.Save(stored)
}

// confirm asks a yes/no question on the terminal.
func (a *app) confirm(question string) bool {
	fmt.Fprintf(a.stdout, "%s [j/N] ", question)
	line, _ := bufio.NewReader(a.stdin).ReadString('\n')
	answer := strings.ToLower(strings.TrimSpace(line))
	return answer == "j" || answer == "ja" || answer == "y" || answer == "yes"
}

// listFlag collects a repeatable flag.
type listFlag []string

func (l *listFlag) String() string     { return strings.Join(*l, ",") }
func (l *listFlag) Set(v string) error { *l = append(*l, v); return nil }

// secretStdinFlag registers --secret-stdin (Spec Server-Modus §5).
func secretStdinFlag(fs *flag.FlagSet) *bool {
	return fs.Bool("secret-stdin", false, "Kopplungs-Secret als erste Zeile von stdin lesen (Server-Modus), DB-Passwort als zweite")
}

// secretStore is stdin with --secret-stdin, else the macOS keychain. The stdin store is created
// once: secret and DB password are two lines of the same stdin.
func (a *app) secretStore(fromStdin bool) secretstore.Store {
	if !fromStdin {
		return a.keychainStore()
	}
	if a.stdinSecrets == nil {
		a.stdinSecrets = secretstore.NewStdin(a.stdin)
	}
	return a.stdinSecrets
}

func (a *app) keychainStore() secretstore.Store { return secretstore.Keychain{KC: a.kc} }

// loadSite returns a paired site and its secret.
func loadSite(name string, store secretstore.Store) (*sites.Site, string, error) {
	site, err := sites.Load(name)
	if err != nil {
		return nil, "", cliout.Usage(fmt.Errorf("%v – zuerst wpsync pair ausführen", err))
	}
	secret, err := store.Get(site.Name)
	if errors.Is(err, secretstore.ErrNotFound) {
		return nil, "", fmt.Errorf("kein Secret für %q in der Keychain – neu koppeln mit wpsync pair", site.Name)
	}
	if err != nil {
		return nil, "", cliout.Usage(fmt.Errorf("Secret für %q nicht lesbar: %w", site.Name, err))
	}
	return site, secret, nil
}

// explain turns agent errors into actionable German messages; the agent error stays for the exit code.
func explain(err error, site *sites.Site) error {
	var apiErr *agentapi.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	switch apiErr.Code {
	case "wpsync_unpaired":
		return cliout.Hint(err, fmt.Sprintf("Kopplung wurde auf der Site widerrufen – neu koppeln mit wpsync pair %s <code>", site.URL))
	case "redirect":
		return cliout.Hint(err, fmt.Sprintf("Site leitet jetzt auf %s um – neu koppeln mit wpsync pair", apiErr.Message))
	case "rest_no_route":
		return cliout.Hint(err, fmt.Sprintf("der wpsync-Agent auf %s ist zu alt – Version %s installieren", site.URL, agentapi.MinAgentVersion))
	case "wpsync_https":
		return cliout.Hint(err, fmt.Sprintf("der Agent auf %s sieht die Verbindung als unverschlüsselt – hinter einem Proxy muss WordPress HTTPS erkennen (is_ssl), lokal: define('WPSYNC_ALLOW_HTTP', true); in wp-config.php", site.URL))
	}
	return err
}

func isTerminal() bool {
	return term.IsTerminal(int(os.Stdin.Fd())) && term.IsTerminal(int(os.Stdout.Fd()))
}

// parseInterspersed allows flags after positional arguments (wpsync pull kunde --full).
func parseInterspersed(fs *flag.FlagSet, args []string) ([]string, error) {
	fs.SetOutput(io.Discard)
	var positional []string
	for {
		if err := fs.Parse(args); err != nil {
			return nil, err
		}
		args = fs.Args()
		if len(args) == 0 {
			return positional, nil
		}
		positional = append(positional, args[0])
		args = args[1:]
	}
}

func publicIP() string {
	hc := &http.Client{Timeout: 3 * time.Second}
	resp, err := hc.Get("https://api.ipify.org")
	if err != nil {
		return "unbekannt (curl https://api.ipify.org)"
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(resp.Body, 64))
	return strings.TrimSpace(string(data))
}
