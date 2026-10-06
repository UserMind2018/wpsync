// Command wpsync pulls WordPress sites into local DDEV projects.
package main

import (
	"bufio"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"text/tabwriter"
	"time"

	"golang.org/x/term"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/keychain"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/mailguard"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/pull"
	"github.com/usermind/wpsync/internal/push"
	"github.com/usermind/wpsync/internal/scan"
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
`

func main() {
	if len(os.Args) < 2 {
		fmt.Fprint(os.Stderr, usage)
		os.Exit(2)
	}
	var err error
	switch os.Args[1] {
	case "setup":
		err = cmdSetup()
	case "doctor":
		err = cmdDoctor()
	case "pair":
		err = cmdPair(os.Args[2:])
	case "unpair":
		err = cmdUnpair(os.Args[2:])
	case "list":
		err = cmdList()
	case "stop":
		err = cmdStop(os.Args[2:])
	case "scan":
		err = cmdScan(os.Args[2:])
	case "pull":
		err = cmdPull(os.Args[2:])
	case "status":
		err = cmdStatus(os.Args[2:])
	case "trust":
		err = cmdTrust(os.Args[2:])
	case "push":
		err = cmdPush(os.Args[2:])
	case "pushes":
		err = cmdPushes(os.Args[2:])
	case "rollback":
		err = cmdRollback(os.Args[2:])
	case "version":
		fmt.Println("wpsync " + agentapi.Version)
	default:
		fmt.Fprint(os.Stderr, usage)
		os.Exit(2)
	}
	if err != nil {
		fmt.Fprintf(os.Stderr, "\n✗ %v\n", err)
		if pull.IsSuspectedBan(err) {
			fmt.Fprintf(os.Stderr, "  Der Server antwortet nicht mehr – vermutlich eine IP-Sperre (fail2ban/WAF).\n"+
				"  Eigene öffentliche IP: %s – im Server-Schutz entsperren und whitelisten, dann erneut versuchen.\n", publicIP())
		}
		os.Exit(1)
	}
}

// mailguardSource resolves the mailguard file to mount ($WPSYNC_MAILGUARD or bundled).
func mailguardSource() (path, origin string, err error) {
	dir, err := sites.ConfigDir()
	if err != nil {
		return "", "", err
	}
	return mailguard.Resolve(dir)
}

func cmdSetup() error {
	if err := setup.Run(); err != nil {
		return fmt.Errorf("Setup fehlgeschlagen: %w", err)
	}
	return cmdDoctor()
}

func cmdDoctor() error {
	failed := 0
	source, origin, err := mailguardSource()
	if err != nil {
		origin = err.Error()
	}
	env := setup.SystemEnv(source)
	env.MailguardOrigin = origin
	for _, c := range setup.Doctor(env) {
		mark := "✓"
		if !c.OK {
			mark = "✗"
			failed++
		}
		fmt.Printf("%s %s  %s\n", mark, c.Name, c.Detail)
		if !c.OK {
			fmt.Printf("    → %s\n", c.Fix)
		}
	}
	if failed > 0 {
		return fmt.Errorf("%d Prüfung(en) fehlgeschlagen", failed)
	}
	return nil
}

func cmdPair(args []string) error {
	fs := flag.NewFlagSet("pair", flag.ContinueOnError)
	name := fs.String("name", "", "lokaler Name der Site (Standard: aus der URL)")
	device := fs.String("device", "", "Gerätename im WP-Admin (Standard: Rechnername)")
	insecure := fs.Bool("insecure", false, "http:// zulassen – nur für lokale Testumgebungen")
	positional, err := parseInterspersed(fs, args)
	if err != nil {
		return err
	}
	if len(positional) != 2 {
		return errors.New("Aufruf: wpsync pair <url> <code> [--name n] [--insecure]")
	}
	hc := &http.Client{Timeout: 30 * time.Second}
	base, err := agentapi.Discover(hc, positional[0])
	if err != nil {
		return fmt.Errorf("wpsync-Agent unter %s nicht gefunden – ist das Plugin aktiv? (%v)", positional[0], err)
	}
	if !*insecure && !agentapi.IsHTTPS(base) {
		return fmt.Errorf("%s ist nicht verschlüsselt – Secret und Daten liefen im Klartext über die Leitung. Nur für lokale Testumgebungen: --insecure", base)
	}
	if *name == "" {
		if *name, err = sites.NameFromURL(base); err != nil {
			return err
		}
	}
	if !sites.ValidName(*name) {
		return fmt.Errorf("ungültiger Name %q (erlaubt: a-z, 0-9, Bindestrich)", *name)
	}
	if *device == "" {
		host, _ := os.Hostname()
		*device = host
	}
	res, err := agentapi.Pair(hc, base, positional[1], *device)
	if err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Message != "" {
			return fmt.Errorf("Kopplung abgelehnt: %s", apiErr.Message)
		}
		return fmt.Errorf("Kopplung fehlgeschlagen: %w", err)
	}
	if err := (keychain.MacOS{}).Set(sites.KeychainService(*name), *name, res.Secret); err != nil {
		return fmt.Errorf("Secret konnte nicht in der Keychain gespeichert werden: %w", err)
	}
	if err := sites.Save(&sites.Site{Name: *name, URL: base, KeyID: res.KeyID, RPS: 1}); err != nil {
		return err
	}
	fmt.Printf("✓ %s gekoppelt als %q (Agent %s)\n  Nächster Schritt: wpsync scan %s\n", base, *name, res.AgentVersion, *name)
	return nil
}

func cmdUnpair(args []string) error {
	if len(args) != 1 {
		return errors.New("Aufruf: wpsync unpair <site>")
	}
	site, err := sites.Load(args[0])
	if err != nil {
		return err
	}
	(keychain.MacOS{}).Delete(sites.KeychainService(site.Name), site.Name)
	if err := sites.Delete(site.Name); err != nil {
		return err
	}
	fmt.Printf("✓ Kopplung %q lokal entfernt.\n  Bitte zusätzlich im WP-Admin unter Werkzeuge → wpsync widerrufen: %s\n", site.Name, site.URL)
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
		return nil, err
	}
	return localenv.Merge(found, paired), nil
}

func cmdList() error {
	d, err := ddevDriver(nil, nil)
	if err != nil {
		return err
	}
	envs, err := localEnvs(d)
	if err != nil {
		return err
	}
	if len(envs) == 0 {
		fmt.Println("Noch keine Site gekoppelt – wpsync pair <url> <code>")
		return nil
	}
	w := tabwriter.NewWriter(os.Stdout, 0, 0, 3, ' ', 0)
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

func cmdStop(args []string) error {
	fs := flag.NewFlagSet("stop", flag.ContinueOnError)
	all := fs.Bool("all", false, "alle laufenden wpsync-Umgebungen stoppen")
	names, err := parseInterspersed(fs, args)
	if err != nil {
		return err
	}
	if *all == (len(names) > 0) {
		return errors.New("Aufruf: wpsync stop <site>… oder wpsync stop --all")
	}
	d, err := ddevDriver(os.Stdout, os.Stderr)
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
			fmt.Printf("%d Umgebung(en) gestoppt.\n", n)
		}
		return err
	}
	switch n {
	case 0:
		fmt.Println("Nichts zu stoppen – keine der Umgebungen läuft.")
	case 1:
		fmt.Println("✓ 1 Umgebung gestoppt")
	default:
		fmt.Printf("✓ %d Umgebungen gestoppt\n", n)
	}
	return nil
}

func cmdScan(args []string) error {
	fs := flag.NewFlagSet("scan", flag.ContinueOnError)
	refresh := fs.Bool("refresh", false, "Infosheet auf der Site neu erstellen")
	preset := fs.String("preset", "", "ohne Rückfrage: ohne-transaktionen | nur-content | vollstaendig")
	since := fs.String("uploads-since", "", "Uploads ab diesem Jahr ziehen, ältere per Proxy (nur mit --preset)")
	var plugins, postTypes listFlag
	fs.Var(&plugins, "exclude-plugin", "Plugin nicht ziehen, mehrfach möglich (nur mit --preset)")
	fs.Var(&postTypes, "exclude-post-type", "Post-Typ nicht ziehen, mehrfach möglich (nur mit --preset)")
	positional, err := parseInterspersed(fs, args)
	if err != nil {
		return err
	}
	if len(positional) != 1 {
		return errors.New("Aufruf: wpsync scan <site> [--refresh] [--preset p]")
	}
	if *preset == "" && (len(plugins) > 0 || len(postTypes) > 0 || *since != "") {
		return errors.New("--exclude-plugin, --exclude-post-type und --uploads-since nur zusammen mit --preset")
	}
	if *since != "" && !profile.IsYear(*since) {
		return errors.New("--uploads-since erwartet ein Jahr, z. B. 2025")
	}
	site, secret, err := loadSite(positional[0])
	if err != nil {
		return err
	}
	opts := scan.Options{
		Client:  agentapi.New(site.URL, site.KeyID, secret, site.RPS),
		Current: site.Profile,
		Out:     os.Stdout,
		Now:     time.Now(),
		Refresh: *refresh,
		Preset:  *preset,
		Adjust:  scan.Adjust{ExcludePlugins: plugins, ExcludePostTypes: postTypes, UploadsSince: *since},
	}
	if *preset == "" && isTerminal() {
		opts.Select = scan.Interactive
	}
	p, err := scan.Run(opts)
	if err != nil {
		return explain(err, site)
	}
	site.Profile = p
	if err := sites.Save(site); err != nil {
		return err
	}
	fmt.Printf("✓ Profil gespeichert. Nächster Schritt: wpsync pull %s\n", site.Name)
	return nil
}

func cmdPull(args []string) error {
	fs := flag.NewFlagSet("pull", flag.ContinueOnError)
	full := fs.Bool("full", false, "alles neu laden (Baseline und vorhandene Dateien ignorieren)")
	yes := fs.Bool("yes", false, "neue Tabellen/Plugins ohne Rückfrage nach dem Preset behandeln")
	dryRun := fs.Bool("dry-run", false, "nur anzeigen, was sich geändert hat (wie wpsync status)")
	noAnon := fs.Bool("no-anonymize", false, "personenbezogene Daten im Klartext ziehen (fragt nach; ohne Terminal zusätzlich --yes)")
	rps := fs.Float64("rps", 0, "max. Requests pro Sekunde (Standard aus der Site-Konfiguration)")
	positional, err := parseInterspersed(fs, args)
	if err != nil {
		return err
	}
	if len(positional) != 1 {
		return errors.New("Aufruf: wpsync pull <site> [--full] [--yes] [--dry-run] [--no-anonymize]")
	}
	site, secret, err := loadSite(positional[0])
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
		return fmt.Errorf("local-mailguard nicht verfügbar – ohne Mail-Schutz kein Pull: %w", err)
	}
	state, err := ddevStore(root)
	if err != nil {
		return err
	}
	drv := &ddev.Driver{SitesRoot: root, MailguardSource: guard, State: state, Docker: dockerCLI, Out: os.Stdout, Err: os.Stdout}
	opts := pull.Options{
		Site:            *site,
		Secret:          secret,
		SitesRoot:       root,
		Driver:          drv,
		Full:            *full,
		Yes:             *yes,
		NoAnonymize:     *noAnon,
		SaveSite:        saveProfile,
		Out:             os.Stdout,
		RowsPerChunk:    2000,
		FileBundleBytes: 16 << 20,
		DBBundleBytes:   8 << 20,
	}
	if isTerminal() {
		opts.Confirm = confirm
		drv.Confirm = confirm
	}
	if *dryRun {
		return pullError(pull.Status(opts), site)
	}
	return pullError(pull.Run(opts), site)
}

func cmdStatus(args []string) error {
	if len(args) != 1 {
		return errors.New("Aufruf: wpsync status <site>")
	}
	site, secret, err := loadSite(args[0])
	if err != nil {
		return err
	}
	root, err := sites.SitesRoot()
	if err != nil {
		return err
	}
	return pullError(pull.Status(pull.Options{Site: *site, Secret: secret, SitesRoot: root, Out: os.Stdout}), site)
}

// pushOptions loads the site and builds the options shared by push, pushes and rollback.
func pushOptions(name string) (push.Options, *sites.Site, error) {
	site, secret, err := loadSite(name)
	if err != nil {
		return push.Options{}, nil, err
	}
	root, err := sites.SitesRoot()
	if err != nil {
		return push.Options{}, nil, err
	}
	opts := push.Options{Site: *site, Secret: secret, SitesRoot: root, Out: os.Stdout}
	if isTerminal() {
		opts.Confirm = confirm
	}
	return opts, site, nil
}

func cmdPush(args []string) error {
	fs := flag.NewFlagSet("push", flag.ContinueOnError)
	force := fs.Bool("force", false, "überschreiben, obwohl sich die Site seit dem letzten Pull geändert hat (alter Stand bleibt als Snapshot)")
	yes := fs.Bool("yes", false, "ohne Rückfrage pushen")
	allowVersion := fs.Bool("allow-version-change", false, "mit --yes: geänderte Plugin-/Theme-Version akzeptieren")
	dryRun := fs.Bool("dry-run", false, "nur anzeigen, was gepusht würde")
	rps := fs.Float64("rps", 0, "max. Requests pro Sekunde (Standard aus der Site-Konfiguration)")
	positional, err := parseInterspersed(fs, args)
	if err != nil {
		return err
	}
	if len(positional) < 2 || positional[1] != "code" {
		return errors.New("Aufruf: wpsync push <site> code [plugins/<slug> | themes/<slug> | mu-plugins]… [--dry-run] [--force] [--yes]")
	}
	opts, site, err := pushOptions(positional[0])
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

func cmdPushes(args []string) error {
	fs := flag.NewFlagSet("pushes", flag.ContinueOnError)
	confirmID := fs.String("confirm", "", "einen getauschten, aber nicht bestätigten Push als in Ordnung markieren")
	positional, err := parseInterspersed(fs, args)
	if err != nil {
		return err
	}
	if len(positional) != 1 {
		return errors.New("Aufruf: wpsync pushes <site> [--confirm <push-id>]")
	}
	opts, site, err := pushOptions(positional[0])
	if err != nil {
		return err
	}
	if *confirmID != "" {
		return pushError(push.ConfirmPending(opts, *confirmID), site)
	}
	return pushError(push.Pushes(opts), site)
}

func cmdRollback(args []string) error {
	if len(args) < 1 || len(args) > 2 {
		return errors.New("Aufruf: wpsync rollback <site> [push-id]")
	}
	opts, site, err := pushOptions(args[0])
	if err != nil {
		return err
	}
	id := ""
	if len(args) == 2 {
		id = args[1]
	}
	return pushError(push.Rollback(opts, id), site)
}

// pushError turns push errors into the next step.
func pushError(err error, site *sites.Site) error {
	var pending *push.PendingError
	var rolled *push.RolledBackError
	var skipped *push.SkippedNewError
	var apiErr *agentapi.APIError
	switch {
	case err == nil:
		return nil
	case errors.Is(err, push.ErrNoBaseline):
		return fmt.Errorf("für %s gibt es noch keinen Pull – zuerst wpsync pull %s", site.Name, site.Name)
	case errors.As(err, &skipped):
		return fmt.Errorf("nichts gepusht – lokal neu sind nur %s; neue Einheiten gehen nur mit ausdrücklicher Nennung auf die Site: wpsync push %s code <einheit>",
			strings.Join(skipped.Units, ", "), site.Name)
	case errors.Is(err, push.ErrNothing):
		return fmt.Errorf("nichts zu pushen – lokal ist nichts geändert seit dem letzten Pull von %s", site.Name)
	case errors.Is(err, push.ErrRollbackWindow):
		return fmt.Errorf("%w – ein Administrator muss das Push-Fenster unter %s/wp-admin/tools.php?page=wpsync öffnen oder den Push dort selbst zurückrollen", err, site.URL)
	case errors.Is(err, push.ErrWindowClosed):
		return fmt.Errorf("das Push-Fenster ist geschlossen – öffnen unter %s/wp-admin/tools.php?page=wpsync", site.URL)
	case errors.Is(err, push.ErrConflict):
		return fmt.Errorf("die Site hat sich seit dem letzten Pull geändert – zuerst wpsync pull %s, lokal zusammenführen und erneut pushen (bewusst überschreiben: --force)", site.Name)
	case errors.Is(err, push.ErrNotWritable):
		return errors.New("der Webserver darf das Verzeichnis nicht ersetzen – Besitzer und Rechte von wp-content/plugins bzw. themes auf dem Server prüfen")
	case errors.Is(err, push.ErrAgentTooOld):
		return fmt.Errorf("der wpsync-Agent auf %s kann noch nicht pushen – Agent %s installieren", site.URL, push.MinAgent)
	case errors.Is(err, push.ErrVersionChange):
		return errors.New("die Versionsnummer ändert sich (mögliche Datenbank-Migration) – im Terminal bestätigen oder --yes --allow-version-change angeben")
	case errors.Is(err, push.ErrRescueUnreachable):
		return fmt.Errorf("%w – ohne Rückweg wird nicht gepusht. Sperrt ein Sicherheits-Plugin oder der Server direkte PHP-Aufrufe unter wp-content/plugins/?", err)
	case errors.As(err, &pending):
		return fmt.Errorf("%w.\n  Site prüfen, dann entweder  wpsync pushes %s --confirm %s\n  oder                        wpsync rollback %s %s", err, site.Name, push.ShowID(pending.PushID), site.Name, push.ShowID(pending.PushID))
	case errors.As(err, &rolled):
		if len(rolled.StillWorse) > 0 {
			return fmt.Errorf("%w.\n  Nach dem Rollback noch auffällig: %s", err, strings.Join(rolled.StillWorse, "; "))
		}
		return fmt.Errorf("%w.\n  Die Site ist wieder auf dem alten Stand; lokal ist nichts verändert", err)
	case errors.As(err, &apiErr) && apiErr.Code == "rest_no_route":
		return fmt.Errorf("der wpsync-Agent auf %s kann noch nicht pushen – Agent %s installieren", site.URL, push.MinAgent)
	case errors.As(err, &apiErr) && strings.HasPrefix(apiErr.Code, "wpsync_push_"):
		return errors.New(apiErr.Message)
	}
	return explain(err, site)
}

// pullError adds the next step to profile-related errors.
func pullError(err error, site *sites.Site) error {
	switch {
	case errors.Is(err, pull.ErrNoProfile):
		return fmt.Errorf("noch kein Pull-Profil – zuerst wpsync scan %s", site.Name)
	case errors.Is(err, pull.ErrNoInfosheet):
		return fmt.Errorf("die Site hat noch kein Infosheet – wpsync scan %s --refresh", site.Name)
	case errors.Is(err, pull.ErrAgentCannotAnonymize):
		return fmt.Errorf("der wpsync-Agent auf %s kann noch nicht anonymisieren – Agent 0.3.0 installieren. Bewusst im Klartext: wpsync pull %s --no-anonymize", site.URL, site.Name)
	case errors.Is(err, pull.ErrPlainNeedsConfirmation):
		return errors.New("--no-anonymize zieht personenbezogene Daten im Klartext – im Terminal bestätigen oder --yes angeben")
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
func confirm(question string) bool {
	fmt.Printf("%s [j/N] ", question)
	line, _ := bufio.NewReader(os.Stdin).ReadString('\n')
	answer := strings.ToLower(strings.TrimSpace(line))
	return answer == "j" || answer == "ja" || answer == "y" || answer == "yes"
}

// listFlag collects a repeatable flag.
type listFlag []string

func (l *listFlag) String() string     { return strings.Join(*l, ",") }
func (l *listFlag) Set(v string) error { *l = append(*l, v); return nil }

// loadSite returns a paired site and its secret from the keychain.
func loadSite(name string) (*sites.Site, string, error) {
	site, err := sites.Load(name)
	if err != nil {
		return nil, "", fmt.Errorf("%v – zuerst wpsync pair ausführen", err)
	}
	secret, err := (keychain.MacOS{}).Get(sites.KeychainService(site.Name), site.Name)
	if err != nil {
		return nil, "", fmt.Errorf("kein Secret für %q in der Keychain – neu koppeln mit wpsync pair", site.Name)
	}
	return site, secret, nil
}

// explain turns agent errors into actionable German messages.
func explain(err error, site *sites.Site) error {
	var apiErr *agentapi.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	switch apiErr.Code {
	case "wpsync_unpaired":
		return fmt.Errorf("Kopplung wurde auf der Site widerrufen – neu koppeln mit wpsync pair %s <code>", site.URL)
	case "redirect":
		return fmt.Errorf("Site leitet jetzt auf %s um – neu koppeln mit wpsync pair", apiErr.Message)
	case "rest_no_route":
		return fmt.Errorf("der wpsync-Agent auf %s ist zu alt – Version 0.2.0 installieren", site.URL)
	case "wpsync_https":
		return fmt.Errorf("der Agent auf %s sieht die Verbindung als unverschlüsselt – hinter einem Proxy muss WordPress HTTPS erkennen (is_ssl), lokal: define('WPSYNC_ALLOW_HTTP', true); in wp-config.php", site.URL)
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
