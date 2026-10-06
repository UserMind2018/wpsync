package push

import (
	"errors"
	"fmt"
	"path/filepath"
	"strings"
	"text/tabwriter"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// ErrRollbackWindow: a confirmed push goes back through the agent only while the push window of
// this device is open; otherwise an administrator rolls it back in the WP admin (U18).
var ErrRollbackWindow = errors.New("der Push ist bestätigt und das Push-Fenster geschlossen")

var statusLabel = map[string]string{
	"uploading":   "Upload läuft",
	"committed":   "getauscht, nicht bestätigt",
	"confirmed":   "bestätigt",
	"rolled_back": "zurückgerollt",
	"failed":      "fehlgeschlagen",
	"expired":     "verfallen",
}

// Pushes prints the push log of the site.
func Pushes(o Options) error {
	o = o.defaults()
	records, err := o.Client.PushList()
	if err != nil {
		return err
	}
	if len(records) == 0 {
		fmt.Fprintln(o.Out, "Noch kein Push auf dieser Site.")
		return nil
	}
	w := tabwriter.NewWriter(o.Out, 0, 0, 3, ' ', 0)
	fmt.Fprintln(w, "PUSH\tZEIT\tGERÄT\tSTATUS\tEINHEITEN")
	for _, r := range records {
		status := statusLabel[r.Status]
		if status == "" {
			status = agentapi.Printable(r.Status)
		}
		if r.Forced {
			status += " (--force)"
		}
		if r.Pruned && r.Status == "confirmed" {
			status += ", Snapshot aufgeräumt"
		}
		var units []string
		for _, u := range r.Units {
			if ValidUnit(u.Path) {
				units = append(units, u.Path)
			} else {
				units = append(units, agentapi.Printable(u.Path))
			}
		}
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\t%s\n", ShowID(r.PushID), time.Unix(r.Created, 0).Format("02.01.2006 15:04"),
			agentapi.Printable(r.Device), status, strings.Join(units, ", "))
	}
	return w.Flush()
}

// ConfirmPending marks a push as healthy that was swapped in but never confirmed, e.g. because
// the CLI was interrupted (U7). The baseline of this machine is refreshed by the next pull.
func ConfirmPending(o Options, pushID string) error {
	o = o.defaults()
	if !pushIDRe.MatchString(pushID) {
		return fmt.Errorf("ungültige Push-ID %q", pushID)
	}
	if err := o.Client.PushConfirm(pushID); err != nil {
		return err
	}
	fmt.Fprintf(o.Out, "✓ Push %s bestätigt.\n  Baseline auffrischen: wpsync pull %s\n", pushID, o.Site.Name)
	return nil
}

// Rollback takes a push back: through the agent, or through rescue.php when WordPress no longer
// answers. Without pushID it picks the latest push that still has a snapshot.
func Rollback(o Options, pushID string) error {
	o = o.defaults()
	siteDir := filepath.Join(o.SitesRoot, o.Site.Name)
	if pushID == "" {
		pushID = latest(o, siteDir)
		if pushID == "" {
			return errors.New("kein Push gefunden, der sich zurückrollen lässt – wpsync pushes zeigt das Protokoll")
		}
	}
	if !pushIDRe.MatchString(pushID) {
		return fmt.Errorf("ungültige Push-ID %q", pushID)
	}

	if err := o.Client.PushRollback(pushID); err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Code == "wpsync_push_window" {
			return fmt.Errorf("Push %s: %w", pushID, ErrRollbackWindow) // never around the window through rescue.php
		}
		if errors.As(err, &apiErr) && apiErr.Code != "" && apiErr.Status < 500 {
			return err // the agent answered and refused: superseded, pruned or not ours
		}
		// WordPress does not answer – the reason this script exists.
		j, jerr := LoadJournal(siteDir, pushID)
		if jerr != nil {
			return fmt.Errorf("der Agent antwortet nicht (%v) und %w – im WP-Admin unter Werkzeuge → wpsync zurückrollen", err, jerr)
		}
		// The journal is writable from the containers; the key goes only to the site paired in the
		// configuration.
		if !onSite(o.Site.URL, j.RescueURL) {
			return fmt.Errorf("der Agent antwortet nicht (%v) und das Journal zu Push %s nennt eine Rescue-URL ausserhalb von %s (%s) – "+
				"rescue.php wird nicht aufgerufen; im WP-Admin unter Werkzeuge → wpsync zurückrollen", err, pushID, o.Site.URL, agentapi.Printable(j.RescueURL))
		}
		fmt.Fprintln(o.Out, "  der Agent antwortet nicht – nehme den Weg über rescue.php")
		if rerr := RescueRollback(o.HTTP, j.RescueURL, pushID, RescueKey(o.Secret, pushID, j.Salt)); rerr != nil {
			return fmt.Errorf("Rollback über rescue.php fehlgeschlagen: %w", rerr)
		}
	}
	fmt.Fprintf(o.Out, "✓ Push %s ist zurückgerollt.\n", pushID)

	j, err := LoadJournal(siteDir, pushID)
	if err != nil || !j.Applied {
		fmt.Fprintf(o.Out, "  Die Baseline dieses Rechners kennt den Push nicht – auffrischen mit: wpsync pull %s\n", o.Site.Name)
		return nil
	}
	base, err := baseline.Load(siteDir)
	if err != nil {
		return fmt.Errorf("load baseline: %w", err)
	}
	Revert(base, j)
	if err := baseline.Save(siteDir, base); err != nil {
		return fmt.Errorf("save baseline: %w", err)
	}
	j.Applied = false
	if err := SaveJournal(siteDir, j); err != nil {
		return err
	}
	return o.Commit(siteDir, fmt.Sprintf("rollback %s on %s", pushID, o.Site.URL))
}

// latest asks the agent for the newest push with a snapshot; if it does not answer, the newest
// journal of this machine decides.
func latest(o Options, siteDir string) string {
	records, err := o.Client.PushList()
	if err != nil {
		return LatestJournal(siteDir)
	}
	for _, r := range records {
		if !r.Pruned && (r.Status == "committed" || r.Status == "confirmed") {
			return r.PushID
		}
	}
	return ""
}
