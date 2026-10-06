package main

import (
	"errors"
	"flag"
	"fmt"
	"path/filepath"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/pull"
	"github.com/usermind/wpsync/internal/sites"
)

// dockerCLI runs docker for trust, stop and pull; tests replace it.
var dockerCLI ddev.Docker = ddev.DockerExec{}

// ddevStore is where the trusted .ddev state of every site lives.
func ddevStore(sitesRoot string) (ddev.Store, error) {
	dir, err := sites.ConfigDir()
	if err != nil {
		return ddev.Store{}, err
	}
	return ddev.NewStore(dir, sitesRoot)
}

// cmdTrust shows how .ddev differs from the trusted state and stores it after an explicit
// approval: in the terminal, or with the fingerprint of exactly what was shown. --yes never does.
func (a *app) cmdTrust(args []string) error {
	fs := flag.NewFlagSet("trust", flag.ContinueOnError)
	fingerprint := fs.String("fingerprint", "", "ohne Terminal: genau den angezeigten Stand freigeben")
	positional, err := parseInterspersed(fs, args)
	if err != nil {
		return cliout.Usage(err)
	}
	if len(positional) != 1 || !sites.ValidName(positional[0]) {
		return cliout.Usage(errors.New("Aufruf: wpsync trust <site> [--fingerprint <fp>]"))
	}
	name := positional[0]
	root, err := sites.SitesRoot()
	if err != nil {
		return err
	}
	siteDir := filepath.Join(root, name)
	if !ddev.Exists(siteDir) {
		return fmt.Errorf("keine lokale wpsync-Umgebung: %s (wpsync list zeigt alle)", name)
	}
	store, err := ddevStore(root)
	if err != nil {
		return err
	}
	project, err := ddev.OpenProject(name, siteDir, store, dockerCLI)
	if err != nil {
		return err
	}
	// A container that can still write to .ddev could change it between display and approval.
	stopped, err := ddev.StopUnhardened(dockerCLI, name, siteDir)
	if err != nil {
		return fmt.Errorf("DDEV-Container von %s prüfen: %w", name, err)
	}
	if stopped {
		fmt.Fprintf(a.stdout, "DDEV-Container von %s konnten .ddev noch beschreiben – ohne ddev gestoppt.\n", name)
	}
	changes, shown, content, err := project.Review()
	if err != nil {
		return err
	}
	takeover := !project.Trusted()
	if !takeover && len(changes) == 0 {
		fmt.Fprintf(a.stdout, ".ddev von %s entspricht dem geprüften Stand – nichts freizugeben.\n", name)
		return nil
	}
	var own map[string]string
	if source, _, err := mailguardSource(); err == nil {
		own, _ = ddev.OwnFiles(source)
	}
	if takeover {
		fmt.Fprintf(a.stdout, "Noch kein geprüfter Stand für .ddev von %s – übernommen wird:\n", name)
	} else {
		fmt.Fprintf(a.stdout, ".ddev von %s weicht vom geprüften Stand ab:\n", name)
	}
	ddev.DescribeContent(a.stdout, changes, content, own, takeover)
	for _, c := range changes {
		if c.Unsafe {
			return fmt.Errorf("%w: %s", ddev.ErrUnsafeEntry, agentapi.Printable(c.Path))
		}
	}
	fp := ddev.Fingerprint(changes, shown)
	fmt.Fprintf(a.stdout, "\nDDEV führt Hooks, Host-Kommandos und Compose-Dateien bei jedem Start auf dem Mac bzw. mit Zugriff auf den Mac aus.\nFingerprint: %s\n", fp)
	switch {
	case *fingerprint != "":
		if *fingerprint != fp {
			return errors.New("der Fingerprint passt nicht zum angezeigten Stand – .ddev hat sich geändert oder der Wert ist falsch")
		}
	case a.interactive():
		if !a.confirm("Diesen Stand von .ddev freigeben?") {
			return pull.ErrAborted
		}
	default:
		return cliout.Usage(fmt.Errorf("ohne Terminal nur mit Fingerprint: wpsync trust %s --fingerprint %s", name, fp))
	}
	if err := project.Trust(shown); err != nil {
		return err
	}
	fmt.Fprintln(a.stdout, "✓ freigegeben – der nächste Pull nutzt diesen Stand")
	return nil
}
