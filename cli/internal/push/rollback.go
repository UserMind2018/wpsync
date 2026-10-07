package push

import (
	"errors"
	"fmt"
	"sort"
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
	fmt.Fprintln(w, "PUSH\tZEIT\tZIEL\tGERÄT\tSTATUS\tEINHEITEN")
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
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\t%s\t%s\n", ShowID(r.PushID), time.Unix(r.Created, 0).Format("02.01.2006 15:04"),
			targetLabel(r.Target), agentapi.Printable(r.Device), status, strings.Join(units, ", "))
	}
	return w.Flush()
}

// List returns the push log of the site, newest first (pushes --json). Device, status, target
// and unit paths are the agent's words, unescaped.
func List(o Options) ([]agentapi.PushRecord, error) {
	o = o.defaults()
	return o.Client.PushList()
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
// answers. Without pushID it picks the latest push of the target that still has a snapshot – of
// live unless Target names staging (V10). With pushID the push itself decides where the rollback
// happens; a Target that names the other side stops it before any request.
func Rollback(o Options, pushID string) error {
	if err := o.checkTarget(); err != nil {
		return err
	}
	unlock, err := lock(o)
	if err != nil {
		return err
	}
	defer unlock()
	o = o.defaults()
	siteDir, _, err := o.dirs()
	if err != nil {
		return err
	}
	target := "" // where the push went, as far as anyone can tell
	var units []string
	if pushID == "" {
		pushID, target = latest(o, siteDir)
		if pushID == "" {
			return fmt.Errorf("kein Push nach %s gefunden, der sich zurückrollen lässt – wpsync pushes zeigt das Protokoll", targetLabel(o.target()))
		}
	}
	if !pushIDRe.MatchString(pushID) {
		return fmt.Errorf("ungültige Push-ID %q", pushID)
	}
	j, jerr := LoadJournal(siteDir, pushID)
	switch {
	case jerr == nil && target != "" && target != j.target():
		return fmt.Errorf("%w: der Agent führt Push %s als %s, das Journal dieses Rechners als %s – nichts zurückgerollt",
			ErrTargetMismatch, pushID, targetLabel(target), targetLabel(j.target()))
	case jerr == nil:
		target = j.target()
		for unit := range j.Units {
			units = append(units, unit)
		}
		sort.Strings(units)
	case target == "" && (o.Target != "" || o.Report != nil):
		// A push from another machine: only the agent knows where it went.
		target, units = recorded(o, pushID)
	}
	if o.Target != "" && target != o.Target {
		if target == "" {
			return fmt.Errorf("%w: wohin Push %s ging, lässt sich nicht prüfen – ohne --to wiederholen", ErrTargetMismatch, pushID)
		}
		return &TargetError{PushID: pushID, Is: target, Want: o.Target}
	}

	if err := o.Client.PushRollback(pushID); err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Code == "wpsync_push_window" {
			return fmt.Errorf("Push %s: %w", pushID, ErrRollbackWindow) // never around the window through rescue.php
		}
		if errors.As(err, &apiErr) && apiErr.Code != "" && apiErr.Status < 500 {
			return agentError(target, err) // the agent answered and refused: superseded, pruned or not ours
		}
		// WordPress does not answer – the reason this script exists.
		if jerr != nil {
			return fmt.Errorf("der Agent antwortet nicht (%v) und %w – im WP-Admin unter Werkzeuge → wpsync zurückrollen", err, jerr)
		}
		// The journal is writable from the containers; the key goes only to the site paired in the
		// configuration. rescue.php finds the push by its ID in the wp-content it was swapped in.
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
	if o.Report != nil {
		if units == nil {
			units = []string{}
		}
		*o.Report = Result{PushID: pushID, Target: target, Status: "rolled_back", Units: units}
	}

	if target == TargetStaging {
		fmt.Fprintln(o.Out, "  Push nach Staging – die Baseline bildet Live ab und bleibt unverändert.")
		if err := o.forgetStagingPush(siteDir, pushID); err != nil {
			return fmt.Errorf("save staging base: %w", err)
		}
		return nil
	}
	if jerr != nil || !j.Applied {
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
	if err := o.Commit(siteDir, fmt.Sprintf("rollback %s on %s", pushID, o.Site.URL)); err != nil {
		if snapshotWarning(err) == WarningSnapshotFailed {
			fmt.Fprintf(o.Out, "  ! Schnappschuss im lokalen Git fehlgeschlagen – der Rollback ist durch, der Stand fehlt in der Historie: %v\n", err)
		}
		if o.Report != nil {
			o.Report.Warnings = append(o.Report.Warnings, snapshotWarning(err))
		}
	}
	return nil
}

// recordTarget is the target of a line of the push log: live for an agent that names none, ""
// for a word this CLI does not know.
func recordTarget(r agentapi.PushRecord) string {
	switch r.Target {
	case "", TargetLive:
		return TargetLive
	case TargetStaging:
		return TargetStaging
	}
	return ""
}

// latest asks the agent for the newest push of the target with a snapshot; if it does not
// answer, the newest journal of this machine for that target decides.
func latest(o Options, siteDir string) (pushID, target string) {
	records, err := o.Client.PushList()
	if err != nil {
		return LatestJournal(siteDir, o.target()), ""
	}
	for _, r := range records {
		if !r.Pruned && (r.Status == "committed" || r.Status == "confirmed") && recordTarget(r) == o.target() {
			return r.PushID, o.target()
		}
	}
	return "", ""
}

// recorded looks a push up in the agent's log: its target and units, "" if the agent does not
// answer or no longer lists it.
func recorded(o Options, pushID string) (target string, units []string) {
	records, err := o.Client.PushList()
	if err != nil {
		return "", nil
	}
	for _, r := range records {
		if r.PushID != pushID {
			continue
		}
		for _, u := range r.Units {
			if ValidUnit(u.Path) {
				units = append(units, u.Path)
			}
		}
		return recordTarget(r), units
	}
	return "", nil
}
