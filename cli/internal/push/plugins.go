package push

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"regexp"
	"slices"
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// PluginsUnit is how the push log names the plugin state of a push. It is no unit of a request.
const PluginsUnit = "plugins"

var (
	// ErrPluginSwitch: --activate or --deactivate names something a push cannot switch, or a unit
	// to activate is not there to be pushed (exit code usage).
	ErrPluginSwitch = errors.New("ungültiger Plugin-Schalter")
	// ErrAgentNoPlugins: the agent cannot switch plugins with a push (agent < 0.9.0).
	ErrAgentNoPlugins = errors.New("der Agent auf der Site kann mit einem Push noch keine Plugins schalten")
)

// Limits the agent has too (PushPlugins::MAX_UNITS, HEAD_BYTES, MAX_HEADS).
const (
	maxSwitches = 20
	headBytes   = 8192
	maxHeads    = 5
	// maxHookScan bounds what is read of one PHP file to look for an activation hook.
	maxHookScan = 2 << 20
)

// headFileRe is a file name the agent accepts in plugin_heads.
var headFileRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]*\.php$`)

// switches is the plugin state a push asks for: unit names, normalised.
type switches struct{ Activate, Deactivate []string }

func (s switches) any() bool { return len(s.Activate)+len(s.Deactivate) > 0 }

// switches checks Activate and Deactivate before anything is scanned or sent (Spec Content-Push P4
// §4.1): only plugins in a folder, never the agent, at most 20 per switch, none twice, none in
// both; and nothing to activate together with --no-code.
func (o Options) switches() (switches, error) {
	var s switches
	seen := map[string]string{}
	for _, side := range []struct {
		flag string
		in   []string
		out  *[]string
	}{{"--activate", o.Activate, &s.Activate}, {"--deactivate", o.Deactivate, &s.Deactivate}} {
		if len(side.in) > maxSwitches {
			return switches{}, fmt.Errorf("%w: %s nennt mehr als %d Plugins", ErrPluginSwitch, side.flag, maxSwitches)
		}
		for _, raw := range side.in {
			name := unitName(raw)
			if !agentapi.PluginUnit(name) || !ValidUnit(name) {
				return switches{}, fmt.Errorf("%w: %s %q – schalten lässt sich nur ein Plugin in seinem Ordner, plugins/<slug>: kein Theme, nicht mu-plugins, nie plugins/wpsync-agent",
					ErrPluginSwitch, side.flag, raw)
			}
			key := strings.ToLower(name)
			switch prev, ok := seen[key]; {
			case ok && prev == side.flag:
				return switches{}, fmt.Errorf("%w: %s nennt %s doppelt", ErrPluginSwitch, side.flag, name)
			case ok:
				return switches{}, fmt.Errorf("%w: %s steht in --activate und in --deactivate", ErrPluginSwitch, name)
			}
			seen[key] = side.flag
			*side.out = append(*side.out, name)
		}
	}
	if len(s.Activate) > 0 && o.NoCode {
		return switches{}, fmt.Errorf("%w: --activate geht nicht mit --no-code – aktiviert wird nur ein Plugin, dessen Einheit derselbe Push überträgt", ErrPluginSwitch)
	}
	return s, nil
}

// unitBase collects the baseline entries of one unit, keyed relative to the unit – as scan does.
func unitBase(b *baseline.Baseline, unit string) map[string]baseline.FileStamp {
	out := map[string]baseline.FileStamp{}
	for path, stamp := range b.Files {
		if u, rel, ok := UnitOf(path); ok && u == unit {
			out[rel] = stamp
		}
	}
	return out
}

// withActivated adds every unit of --activate to the set, changed or not (A3): the agent activates
// only code the same push uploaded and checked. units are the units picked so far, all the changed
// units of the scan, links the units that are symlinks. A unit that is missing locally, empty or a
// symlink is a wrong call.
func withActivated(docroot string, base *baseline.Baseline, units, all []Unit, activate, links []string) ([]Unit, error) {
	if len(activate) == 0 {
		return units, nil
	}
	for _, name := range activate {
		if slices.Contains(links, name) {
			return nil, fmt.Errorf("%w: --activate %s – %s %w", ErrPluginSwitch, name, showDir(docroot, "wp-content/"+name), ErrSymlink)
		}
		if slices.ContainsFunc(units, func(u Unit) bool { return u.Path == name }) {
			continue
		}
		if i := slices.IndexFunc(all, func(u Unit) bool { return u.Path == name }); i >= 0 {
			units = append(units, all[i]) // changed, only not named
			continue
		}
		if _, ok, err := realDir(docroot, "wp-content/"+name); err != nil {
			return nil, err
		} else if !ok {
			return nil, fmt.Errorf("%w: --activate %s – der Ordner %s fehlt lokal; aktiviert wird nur ein Plugin, das derselbe Push überträgt",
				ErrPluginSwitch, name, showDir(docroot, "wp-content/"+name))
		}
		u, err := scanUnit(docroot, name, unitBase(base, name))
		if err != nil {
			return nil, err
		}
		if len(u.Files) == 0 {
			return nil, fmt.Errorf("%w: --activate %s – der Ordner enthält keine pushbare Datei", ErrPluginSwitch, name)
		}
		sort.Strings(u.Changed)
		units = append(units, u)
	}
	sort.Slice(units, func(i, j int) bool { return units[i].Path < units[j].Path })
	return units, nil
}

// pluginHeads reads, for a unit to activate, what the agent checks already in the dry run (A10):
// the first 8192 bytes of every PHP file directly in the folder that names a plugin header. The
// agent resolves the main file and reads the header itself – the CLI only delivers the bytes.
//
// heads is nil when the heads cannot be sent as the agent takes them (more than five, or a file
// name it would refuse): then none is sent and the agent checks the built file in the commit. An
// empty map means: no file with a header – the agent says no_plugin_file in the dry run.
// hook reports whether any PHP file of the unit registers an activation hook (a heuristic for the
// hint that a push does not run it).
func pluginHeads(docroot string, u *Unit) (heads map[string][]byte, hook bool, err error) {
	root, err := u.open(docroot)
	if err != nil {
		return nil, false, err
	}
	defer root.Close()
	names := make([]string, 0, len(u.Files))
	for rel := range u.Files {
		if strings.HasSuffix(rel, ".php") {
			names = append(names, rel)
		}
	}
	sort.Strings(names)
	heads = map[string][]byte{}
	sendable := true
	for _, rel := range names {
		file, err := openFile(root, u.Path, rel, u.Files[rel])
		if err != nil {
			return nil, false, err
		}
		data, err := io.ReadAll(io.LimitReader(file, maxHookScan))
		file.Close()
		if err != nil {
			return nil, false, err
		}
		if bytes.Contains(data, []byte("register_activation_hook")) {
			hook = true
		}
		if strings.Contains(rel, "/") || strings.HasPrefix(rel, ".") {
			continue // only files directly in the folder can be the main file
		}
		head := data[:min(len(data), headBytes)]
		if !bytes.Contains(bytes.ToLower(head), []byte("plugin name:")) {
			continue
		}
		if !headFileRe.MatchString(rel) || len(heads) == maxHeads {
			sendable = false
			continue
		}
		heads[rel] = head
	}
	if !sendable {
		return nil, hook, nil
	}
	return heads, hook, nil
}

// Warnings of a push with a plugin state (Spec Content-Push P4 §4.5); never an error.
const (
	// WarningDeactivationReview: the set switches at least one active plugin off. Always in the
	// plan, so that a caller has to show it (A22): what follows from switching a consent or security
	// plugin off, no health check sees.
	WarningDeactivationReview = "deactivation_review"
	// WarningRequirementsUnchecked: for a plugin to activate no head was sent; its requirements are
	// checked only in the commit.
	WarningRequirementsUnchecked = "requirements_unchecked"
	// WarningActivationHooksSkipped: an activated unit registers an activation hook, and it did not
	// run – a push writes active_plugins itself (A5). Way out: deactivate and activate once in the WP admin.
	WarningActivationHooksSkipped = "activation_hooks_skipped"
	// WarningDeactivationHooksSkipped: a deactivated plugin registers a deactivation hook; it did not run (A17).
	WarningDeactivationHooksSkipped = "deactivation_hooks_skipped"
	// WarningPluginsNotRestored: rescue.php left the database part of the push on the site – and with
	// it the plugin state: active_plugins still carries the entries of the push (A18).
	WarningPluginsNotRestored = "plugins_not_restored"
)

// PluginsReport is the plugin part of a push that stands: the units it activated and deactivated,
// the ones that were in the wanted state already and the ones the target skipped.
type PluginsReport struct {
	Activated   []string `json:"activated"`
	Deactivated []string `json:"deactivated"`
	Unchanged   []string `json:"unchanged"`
	Skipped     []string `json:"skipped"`
}

// PluginsError is a refusal of the plugin state of a push – by the agent, in the dry run or later.
// Reason is the error.reason of --json: plugins_invalid, plugins_requirements, plugins_not_allowed,
// plugins_unsupported, plugins_failed – or rescue_db_unavailable, then Detail says why the agent has
// no rescue envelope for the push. Plugins names the units it is about; never a value of the option.
type PluginsError struct {
	Reason  string
	Message string
	Detail  string
	Plugins []agentapi.PluginRefusal
	Err     error // the agent's answer, if it was one
}

func (e *PluginsError) Error() string {
	msg := e.Message
	if msg == "" {
		msg = "Plugin-Zustand abgelehnt"
	}
	var shown []string
	for i, p := range e.Plugins {
		if i == 5 {
			shown = append(shown, fmt.Sprintf("und %d weitere", len(e.Plugins)-5))
			break
		}
		s := p.Unit
		if p.Why != "" {
			s += " (" + p.Why
			if p.Needs != "" {
				s += ": verlangt " + agentapi.Printable(p.Needs)
				if p.Has != "" {
					s += ", vorhanden " + agentapi.Printable(p.Has)
				}
			}
			s += ")"
		}
		shown = append(shown, s)
	}
	if len(shown) > 0 {
		msg += " – " + strings.Join(shown, ", ")
	}
	if e.Detail != "" {
		return fmt.Sprintf("%s (%s: %s)", msg, e.Reason, e.Detail)
	}
	return fmt.Sprintf("%s (%s)", msg, e.Reason)
}

func (e *PluginsError) Unwrap() error { return e.Err }

// pluginsFailure turns the refusal inside a dry run's answer into the error. A reason of the
// content channel (engine_unsupported, content_failed) stays one.
func pluginsFailure(f *agentapi.PluginsFailure) error {
	if !strings.HasPrefix(f.Code, "plugins_") {
		return &ContentError{Reason: f.Code, Message: f.Message}
	}
	return &PluginsError{Reason: f.Code, Message: f.Message, Plugins: f.Plugins}
}

// pluginsError ties a refusal of the agent (code wpsync_plugins_<reason>) to a PluginsError; the
// agent's answer stays reachable for errors.As. Other errors pass.
func pluginsError(err error) error {
	var apiErr *agentapi.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	reason, ok := strings.CutPrefix(apiErr.Code, agentapi.PluginsCodePrefix)
	if !ok {
		return err
	}
	if reason == "rescue_db" {
		return &PluginsError{Reason: WarningRescueDBUnavailable, Message: apiErr.Message, Detail: apiErr.Detail, Err: err}
	}
	return &PluginsError{Reason: "plugins_" + reason, Message: apiErr.Message, Plugins: apiErr.Plugins, Err: err}
}

// pluginLabel is "Name Version" of a plugin as the agent names it, or the unit when it names none.
// Name and version passed agentapi.CleanText: they are safe to show, umlauts stay.
func pluginLabel(unit string, name, version *string) string {
	if name == nil || *name == "" {
		return unit
	}
	if version == nil || *version == "" {
		return *name
	}
	return *name + " " + *version
}

// deactivating lists the plugins the set switches off, by name – for the question before the push (A22).
func deactivating(plan *agentapi.PluginsPlan) []string {
	var out []string
	for _, d := range plan.Deactivate {
		if d.State == "active" {
			out = append(out, pluginLabel(d.Unit, d.Name, d.Version))
		}
	}
	return out
}

// deactHooked lists the units to deactivate whose main file registers a deactivation hook.
func deactHooked(plan *agentapi.PluginsPlan) []string {
	var out []string
	for _, d := range plan.Deactivate {
		if d.State == "active" && d.Hooks {
			out = append(out, d.Unit)
		}
	}
	return out
}

// printPlugins shows the agent's plan for the plugin state.
func printPlugins(out io.Writer, plan *agentapi.PluginsPlan) {
	for _, a := range plan.Activate {
		line := "  aktivieren:   " + a.Unit
		if a.Name != nil && *a.Name != "" {
			line += " – " + pluginLabel(a.Unit, a.Name, a.Version)
		}
		switch a.State {
		case "new":
			line += " (neu)"
		case "active":
			line += " (schon aktiv – nichts zu tun)"
		case "skipped":
			line += " (auf diesem Ziel nicht aktiviert: " + a.Why + ")"
		}
		fmt.Fprintln(out, line)
		for _, r := range a.Requirements.Failed {
			fmt.Fprintf(out, "    ! %s: verlangt %s, das Ziel hat %s\n", r.Why, agentapi.Printable(r.Needs), agentapi.Printable(r.Has))
		}
		if a.Requirements.Checked == "at_commit" && a.State != "skipped" {
			fmt.Fprintln(out, "    ! Kopf nicht vorab geprüft – PHP-, WordPress-Version und Abhängigkeiten prüft der Agent erst im Commit")
		}
	}
	for _, d := range plan.Deactivate {
		line := "  deaktivieren: " + d.Unit
		if d.Name != nil && *d.Name != "" {
			line += " – " + pluginLabel(d.Unit, d.Name, d.Version)
		}
		switch d.State {
		case "active":
			line += " (läuft danach nicht mehr)"
		case "inactive":
			line += " (schon inaktiv – nichts zu tun)"
		case "absent":
			line += " (auf dem Ziel nicht vorhanden – nichts zu tun)"
		}
		fmt.Fprintln(out, line)
		if len(d.RequiredBy) > 0 {
			fmt.Fprintf(out, "    ! vorausgesetzt von: %s\n", strings.Join(d.RequiredBy, ", "))
		}
	}
	if !plan.OK && plan.Error != nil {
		fmt.Fprintf(out, "  ! %v\n", pluginsFailure(plan.Error))
	}
	if slices.Contains(plan.Warnings, WarningDeactivationReview) {
		fmt.Fprintln(out, "  ! Ein abgeschaltetes Plugin fehlt der Site sofort – bei einem Consent- oder Sicherheits-Plugin ohne dass ein Health-Check es merkt.")
	}
}

// pluginsReport is what a push switched, as units – the form of data.plugins.
func pluginsReport(a *agentapi.PluginsApplied) *PluginsReport {
	r := &PluginsReport{Activated: []string{}, Deactivated: []string{}, Unchanged: append([]string{}, a.Unchanged...), Skipped: []string{}}
	for _, p := range a.Activated {
		r.Activated = append(r.Activated, p.Unit)
	}
	for _, p := range a.Deactivated {
		r.Deactivated = append(r.Deactivated, p.Unit)
	}
	for _, p := range a.Skipped {
		r.Skipped = append(r.Skipped, p.Unit)
	}
	return r
}

// switchedAsAsked reports whether the commit answers for exactly the plugins the push asked for:
// every unit once, one to activate never as deactivated and the other way round, and no unit
// beyond them. The answer is the site's word – an agent that names other plugins did not do this push.
func switchedAsAsked(sw switches, a *agentapi.PluginsApplied) bool {
	seen, on, off := map[string]int{}, map[string]bool{}, map[string]bool{}
	for _, p := range a.Activated {
		seen[p.Unit]++
		on[p.Unit] = true
	}
	for _, p := range a.Skipped {
		seen[p.Unit]++
		on[p.Unit] = true
	}
	for _, p := range a.Deactivated {
		seen[p.Unit]++
		off[p.Unit] = true
	}
	for _, u := range a.Unchanged {
		seen[u]++
	}
	for _, u := range sw.Activate {
		if seen[u] != 1 || off[u] {
			return false
		}
	}
	for _, u := range sw.Deactivate {
		if seen[u] != 1 || on[u] {
			return false
		}
	}
	return len(seen) == len(sw.Activate)+len(sw.Deactivate)
}

// showEntry is an entry of active_plugins for a line of output: as it is while it has the form the
// CLI takes from the site (agentapi.PluginEntry – nothing a terminal would act on), quoted otherwise.
func showEntry(e string) string {
	if agentapi.PluginEntry(e) {
		return e
	}
	return agentapi.Printable(e)
}

// printSwitched shows what the commit did to the plugin state.
func printSwitched(out io.Writer, a *agentapi.PluginsApplied) {
	for _, p := range a.Activated {
		fmt.Fprintf(out, "  aktiviert: %s (%s)\n", p.Unit, showEntry(p.File))
	}
	for _, p := range a.Deactivated {
		files := make([]string, 0, len(p.Files))
		for _, f := range p.Files {
			files = append(files, showEntry(f))
		}
		fmt.Fprintf(out, "  deaktiviert: %s (%s)\n", p.Unit, strings.Join(files, ", "))
	}
	for _, u := range a.Unchanged {
		fmt.Fprintf(out, "  schon im gewünschten Zustand: %s\n", u)
	}
	for _, p := range a.Skipped {
		fmt.Fprintf(out, "  auf diesem Ziel nicht aktiviert: %s (%s)\n", p.Unit, p.Why)
	}
}

// printEntries prints one line of plugin entries; they are the agent's words. total is how many
// there are when the list does not show all of them (0: it is complete).
func printEntries(out io.Writer, label string, entries []string, total int) {
	if len(entries) == 0 && total == 0 {
		return
	}
	shown := make([]string, 0, len(entries))
	for _, e := range entries {
		shown = append(shown, showEntry(e))
	}
	line := strings.Join(shown, ", ")
	if total > len(entries) {
		line = strings.TrimSpace(fmt.Sprintf("%s (und %d weitere)", line, total-len(entries)))
	}
	fmt.Fprintf(out, "  %s: %s\n", label, line)
}

// printPluginsBack names what a rollback changed in the list of active plugins – or, when the
// database part of the push stayed, what of the push still stands there (A18).
func printPluginsBack(out io.Writer, notes agentapi.RollbackNotes) {
	if p := notes.Plugins; p != nil {
		printEntries(out, "wieder deaktiviert", p.Deactivated, p.DeactivatedTotal)
		printEntries(out, "wieder aktiviert", p.Reactivated, p.ReactivatedTotal)
	}
	if p := notes.PluginsNotRestored; p != nil {
		fmt.Fprintln(out, "  ! Der Plugin-Zustand des Pushs steht noch auf der Site:")
		printEntries(out, "  noch aktiv", p.Added, p.AddedTotal)
		printEntries(out, "  noch inaktiv", p.Removed, p.RemovedTotal)
		if p.Unknown {
			fmt.Fprintln(out, "    welche Einträge der Push geändert hat, ist auf der Site nicht vermerkt – unter Plugins im WP-Admin nachsehen")
		}
	}
}

// cacheStepFailed names the first failed post action that decides whether the new list of active
// plugins is in effect: object_cache and plugins_cache drop what WordPress cached, plugins_effective
// is the agent reading back what the next request will load. "" when none failed.
func cacheStepFailed(actions []agentapi.PostAction) string {
	for _, a := range actions {
		if !a.OK && (a.Step == "object_cache" || a.Step == "plugins_cache" || a.Step == "plugins_effective") {
			return a.Step
		}
	}
	return ""
}
