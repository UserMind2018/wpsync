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
	"os/signal"
	"strings"
	"syscall"
	"text/tabwriter"
	"time"

	"golang.org/x/term"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/cliout"
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
)

const usage = `wpsync – WordPress Live ↔ Lokal

  wpsync setup                         einmalig pro Mac (fragt einmal nach dem Passwort)
  wpsync doctor                        Umgebung prüfen
  wpsync pair <url> <code> [--name n]  Site koppeln (Code aus Werkzeuge → wpsync; nur https, lokal: --insecure)
  wpsync unpair <site>                 Kopplung lokal entfernen
  wpsync list                          lokale Umgebungen mit Status, lokaler und Live-URL
  wpsync stop <site>… | --all          lokale Umgebung(en) stoppen (Daten bleiben erhalten)
  wpsync scan <site> [--refresh]       zeigen, was auf der Site liegt, und auswählen, was gezogen wird
  wpsync pull <site> [--full] [--yes]  Site nach ~/wpsync-sites/<site> ziehen (--dry-run: nur anzeigen,
                                       --no-anonymize: personenbezogene Daten im Klartext)
  wpsync status <site>                 was sich seit dem letzten Pull geändert hat, ohne Transfer
  wpsync trust <site>                  eigene Änderungen in .ddev ansehen und freigeben
                                       (ohne Terminal: --fingerprint <fp> aus der Anzeige)
  wpsync push <site> code [einheit…]   lokal geänderte Plugins/Themes/mu-plugins auf die Site bringen
                                       (braucht ein offenes Push-Fenster; --dry-run, --force, --yes;
                                       lokal neue Einheiten nur, wenn sie genannt werden)
  wpsync pushes <site>                 Protokoll der Pushes (--confirm <id>: hängenden Push bestätigen)
  wpsync rollback <site> [push-id]     letzten bzw. einen bestimmten Push zurücknehmen
  wpsync version

  Server-Modus: --json (pair, scan, pull, status, unpair, doctor, version) schreibt JSON auf stdout,
  Meldungen auf stderr, und fragt nie nach; --secret-stdin liest das Secret von stdin.
`

// jsonCommands accept --json (Spec Server-Modus §4). push, pushes, rollback, trust, list, stop
// and setup stay text-only.
var jsonCommands = map[string]bool{"pair": true, "scan": true, "pull": true, "status": true, "unpair": true, "doctor": true, "version": true}

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
}

// out receives human messages: stdout, with --json stderr.
func (a *app) out() io.Writer {
	if a.json {
		return a.stderr
	}
	return a.stdout
}

// interactive: questions only on a terminal and never with --json.
func (a *app) interactive() bool { return !a.json && isTerminal() }

func (a *app) main(args []string) int {
	if len(args) < 1 {
		fmt.Fprint(a.stderr, usage)
		return cliout.ExitUsage
	}
	cmd, rest := args[0], args[1:]
	a.json = jsonCommands[cmd] && hasJSONFlag(rest)
	if a.json {
		a.jw = cliout.NewWriter(a.stdout)
	}
	err := a.dispatch(cmd, rest)
	if a.json {
		return a.jw.Result(cmd, a.data, err)
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
		return a.cmdList()
	case "stop":
		return a.cmdStop(args)
	case "scan":
		return a.cmdScan(args)
	case "pull":
		return a.cmdPull(args)
	case "status":
		return a.cmdStatus(args)
	case "trust":
		return a.cmdTrust(args)
	case "push":
		return a.cmdPush(args)
	case "pushes":
		return a.cmdPushes(args)
	case "rollback":
		return a.cmdRollback(args)
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
	if _, err := a.parse(fs, args, exactly(0), "wpsync doctor [--json]"); err != nil {
		return err
	}
	source, origin, err := mailguardSource()
	if err != nil {
		origin = err.Error()
	}
	env := setup.SystemEnv(source)
	env.MailguardOrigin = origin
	return a.report(setup.Doctor(env))
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
	if err := sites.Save(&sites.Site{Name: *name, URL: base, KeyID: res.KeyID, RPS: 1}); err != nil {
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

func (a *app) cmdList() error {
	d, err := ddevDriver(nil, nil)
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
	names, err := a.parse(fs, args, nil, "")
	if err != nil {
		return err
	}
	if *all == (len(names) > 0) {
		return cliout.Usage(errors.New("Aufruf: wpsync stop <site>… oder wpsync stop --all"))
	}
	d, err := ddevDriver(a.stdout, a.stderr)
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
	since := fs.String("uploads-since", "", "Uploads ab diesem Jahr ziehen, ältere per Proxy (nur mit --preset)")
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
	if *since != "" && !profile.IsYear(*since) {
		return cliout.Usage(errors.New("--uploads-since erwartet ein Jahr, z. B. 2025"))
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
		Adjust:  scan.Adjust{ExcludePlugins: plugins, ExcludePostTypes: postTypes, UploadsSince: *since},
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
	noAnon := fs.Bool("no-anonymize", false, "personenbezogene Daten im Klartext ziehen (fragt nach; ohne Terminal zusätzlich --yes)")
	rps := fs.Float64("rps", 0, "max. Requests pro Sekunde (Standard aus der Site-Konfiguration)")
	secretStdin := secretStdinFlag(fs)
	positional, err := a.parse(fs, args, exactly(1), "wpsync pull <site> [--full] [--yes] [--dry-run] [--no-anonymize] [--json] [--secret-stdin]")
	if err != nil {
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
	guard, _, err := mailguardSource()
	if err != nil {
		return localenv.Wrap("mailguard", fmt.Errorf("local-mailguard nicht verfügbar – ohne Mail-Schutz kein Pull: %w", err))
	}
	state, err := ddevStore(root)
	if err != nil {
		return err
	}
	drv := &ddev.Driver{SitesRoot: root, MailguardSource: guard, State: state, Docker: dockerCLI, Out: a.out(), Err: a.out()}
	var report pull.Result
	opts := pull.Options{
		Site:            *site,
		Secret:          secret,
		SitesRoot:       root,
		Driver:          drv,
		Full:            *full,
		Yes:             *yes,
		NoAnonymize:     *noAnon,
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
		drv.Confirm = a.confirm
	}
	if a.json {
		opts.Progress = a.jw.Phase
	}
	if *dryRun {
		return a.status(opts, site)
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
	positional, err := a.parse(fs, args, exactly(1), "wpsync status <site> [--json] [--secret-stdin]")
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
	return a.status(pull.Options{Site: *site, Secret: secret, SitesRoot: root, Out: a.out(), Ctx: a.ctx}, site)
}

// status prints the changes since the last pull, or returns them as JSON data.
func (a *app) status(opts pull.Options, site *sites.Site) error {
	if !a.json {
		return pullError(pull.Status(opts), site)
	}
	res, err := pull.StatusReport(opts)
	if err != nil {
		return pullError(err, site)
	}
	a.data = res
	return nil
}

// pushOptions loads the site and builds the options shared by push, pushes and rollback. Push
// stays a Mac command: keychain only, no --json, no container driver.
func (a *app) pushOptions(name string) (push.Options, *sites.Site, error) {
	site, secret, err := loadSite(name, a.keychainStore())
	if err != nil {
		return push.Options{}, nil, err
	}
	root, err := sites.SitesRoot()
	if err != nil {
		return push.Options{}, nil, err
	}
	opts := push.Options{Site: *site, Secret: secret, SitesRoot: root, Out: a.stdout}
	if a.interactive() {
		opts.Confirm = a.confirm
	}
	return opts, site, nil
}

func (a *app) cmdPush(args []string) error {
	fs := a.flags("push")
	force := fs.Bool("force", false, "überschreiben, obwohl sich die Site seit dem letzten Pull geändert hat (alter Stand bleibt als Snapshot)")
	yes := fs.Bool("yes", false, "ohne Rückfrage pushen")
	allowVersion := fs.Bool("allow-version-change", false, "mit --yes: geänderte Plugin-/Theme-Version akzeptieren")
	dryRun := fs.Bool("dry-run", false, "nur anzeigen, was gepusht würde")
	rps := fs.Float64("rps", 0, "max. Requests pro Sekunde (Standard aus der Site-Konfiguration)")
	positional, err := a.parse(fs, args, func(n int) bool { return n >= 2 }, "wpsync push <site> code [plugins/<slug> | themes/<slug> | mu-plugins]… [--dry-run] [--force] [--yes]")
	if err != nil {
		return err
	}
	if positional[1] != "code" {
		return cliout.Usage(errors.New("Aufruf: wpsync push <site> code [plugins/<slug> | themes/<slug> | mu-plugins]… [--dry-run] [--force] [--yes]"))
	}
	opts, site, err := a.pushOptions(positional[0])
	if err != nil {
		return err
	}
	if *rps > 0 {
		opts.Site.RPS = *rps
	}
	opts.Units = positional[2:]
	opts.Force, opts.Yes, opts.AllowVersionChange, opts.DryRun = *force, *yes, *allowVersion, *dryRun
	return pushError(push.Run(opts), site)
}

func (a *app) cmdPushes(args []string) error {
	fs := a.flags("pushes")
	confirmID := fs.String("confirm", "", "einen getauschten, aber nicht bestätigten Push als in Ordnung markieren")
	positional, err := a.parse(fs, args, exactly(1), "wpsync pushes <site> [--confirm <push-id>]")
	if err != nil {
		return err
	}
	opts, site, err := a.pushOptions(positional[0])
	if err != nil {
		return err
	}
	if *confirmID != "" {
		return pushError(push.ConfirmPending(opts, *confirmID), site)
	}
	return pushError(push.Pushes(opts), site)
}

func (a *app) cmdRollback(args []string) error {
	if len(args) < 1 || len(args) > 2 {
		return cliout.Usage(errors.New("Aufruf: wpsync rollback <site> [push-id]"))
	}
	opts, site, err := a.pushOptions(args[0])
	if err != nil {
		return err
	}
	id := ""
	if len(args) == 2 {
		id = args[1]
	}
	return pushError(push.Rollback(opts, id), site)
}

// pushError turns push errors into the next step; the original error stays for the exit code.
func pushError(err error, site *sites.Site) error {
	var pending *push.PendingError
	var rolled *push.RolledBackError
	var skipped *push.SkippedNewError
	var apiErr *agentapi.APIError
	switch {
	case err == nil:
		return nil
	case errors.Is(err, push.ErrNoBaseline):
		return cliout.Hint(cliout.Usage(err), fmt.Sprintf("für %s gibt es noch keinen Pull – zuerst wpsync pull %s", site.Name, site.Name))
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
	case errors.Is(err, push.ErrRescueUnreachable):
		return cliout.Hint(err, fmt.Sprintf("%v – ohne Rückweg wird nicht gepusht. Sperrt ein Sicherheits-Plugin oder der Server direkte PHP-Aufrufe unter wp-content/plugins/?", err))
	case errors.As(err, &pending):
		return cliout.Hint(err, fmt.Sprintf("%v.\n  Site prüfen, dann entweder  wpsync pushes %s --confirm %s\n  oder                        wpsync rollback %s %s", err, site.Name, push.ShowID(pending.PushID), site.Name, push.ShowID(pending.PushID)))
	case errors.As(err, &rolled):
		if len(rolled.StillWorse) > 0 {
			return cliout.Hint(err, fmt.Sprintf("%v.\n  Nach dem Rollback noch auffällig: %s", err, strings.Join(rolled.StillWorse, "; ")))
		}
		return cliout.Hint(err, fmt.Sprintf("%v.\n  Die Site ist wieder auf dem alten Stand; lokal ist nichts verändert", err))
	case errors.As(err, &apiErr) && apiErr.Code == "rest_no_route":
		return cliout.Hint(&agentapi.OutdatedError{Required: push.MinAgent, Err: err},
			fmt.Sprintf("der wpsync-Agent auf %s kann noch nicht pushen – Agent %s installieren", site.URL, push.MinAgent))
	case errors.As(err, &apiErr) && strings.HasPrefix(apiErr.Code, "wpsync_push_"):
		return cliout.Hint(err, apiErr.Message)
	}
	return explain(err, site)
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
