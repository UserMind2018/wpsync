package agentapi

import (
	"regexp"
	"strings"
	"unicode/utf8"
)

// MinAgentPlugins is the first agent that switches plugins with a push (Spec Content-Push P4, A19).
// The CLI tells it by the version and by the field plugins of the answer to /push/begin: an older
// agent would ignore the unknown fields and push without switching anything.
const MinAgentPlugins = "0.9.0"

// PluginsCodePrefix starts the error code of every refusal of the plugin state of a push; the
// rest is the reason (invalid, requirements, not_allowed, unsupported, failed, rescue_db).
const PluginsCodePrefix = "wpsync_plugins_"

// Bounds for what the CLI takes from the site per answer.
const (
	maxPluginUnits   = 20  // units per direction of one push (PushPlugins::MAX_UNITS)
	maxPluginEntries = 100 // entries of active_plugins per list (ContentPlugins::MAX)
	maxPluginText    = 200 // runes of a name, a version, a needs or has
)

var (
	pluginUnitRe  = regexp.MustCompile(`^plugins/[A-Za-z0-9][A-Za-z0-9._-]*$`)
	pluginEntryRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._/ -]{0,200}\.php$`)
	// agentEntryRe is the agent's rule for an entry (PushRescue::pluginEntry): the path may carry any
	// character but controls and the backslash.
	agentEntryRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]*/[^\x00-\x1f\x7f\\]{1,200}\.php$`)
)

// PluginUnit reports whether s names a plugin as a unit: "plugins/<slug>".
func PluginUnit(s string) bool { return pluginUnitRe.MatchString(s) }

// PluginEntry reports whether s has the form of an entry of active_plugins as the CLI takes it
// from the site and shows unquoted: "<slug>/<path>.php" of letters, digits, dot, underscore, hyphen,
// slash and blank, without "..". The agent's own rule is wider (agentEntry); what only passes that
// is kept as data and shown quoted.
func PluginEntry(s string) bool {
	return len(s) <= 255 && !strings.Contains(s, "..") && pluginEntryRe.MatchString(s)
}

// PluginRequirement is one requirement of a plugin header the target does not meet: requires_php,
// requires_wp, requires_plugins – with what the header needs and what the target has.
type PluginRequirement struct {
	Why   string `json:"why"`
	Needs string `json:"needs"`
	Has   string `json:"has"`
}

// PluginRequirements is the result of the header check. Checked: "head" – at the head the CLI
// sent along – or "at_commit": none was sent, the agent checks the built file in the commit.
type PluginRequirements struct {
	Checked string              `json:"checked"`
	OK      bool                `json:"ok"`
	Failed  []PluginRequirement `json:"failed"`
}

// PluginActivate is the agent's view of one plugin to activate. State is the target before the
// push: new, inactive, active (nothing to do) or skipped (not activated on this target; Why says
// why: disabled_on_staging, requires_skipped). File, Name, Version come from the head sent along;
// nil when none was sent.
type PluginActivate struct {
	Unit         string             `json:"unit"`
	State        string             `json:"state"`
	Why          string             `json:"why,omitempty"`
	File         *string            `json:"file"`
	Name         *string            `json:"name"`
	Version      *string            `json:"version"`
	Requirements PluginRequirements `json:"requirements"`
}

// PluginDeactivate is the agent's view of one plugin to deactivate. State: active (it will be
// switched off), inactive or absent (nothing to do). Files are the entries of active_plugins of
// the target in the plugin's folder, Name and Version come from the file on the target.
// RequiredBy names active plugins that need this one; Hooks: its main file registers a
// deactivation hook, which a push never runs.
type PluginDeactivate struct {
	Unit       string   `json:"unit"`
	State      string   `json:"state"`
	Files      []string `json:"files"`
	Name       *string  `json:"name"`
	Version    *string  `json:"version"`
	RequiredBy []string `json:"required_by"`
	Hooks      bool     `json:"hooks"`
}

// PluginRefusal names one unit a refusal is about (error.plugins). Never a value of the option.
type PluginRefusal struct {
	Unit  string `json:"unit"`
	Why   string `json:"why,omitempty"`
	Needs string `json:"needs,omitempty"`
	Has   string `json:"has,omitempty"`
}

// PluginsFailure is a refusal of the plugin state, in the dry run inside the answer. Code is the
// reason: plugins_invalid, plugins_requirements, plugins_unsupported, plugins_failed – or a reason
// of the content channel (engine_unsupported, content_failed).
type PluginsFailure struct {
	Code    string          `json:"code"`
	Message string          `json:"message"`
	Plugins []PluginRefusal `json:"plugins,omitempty"`
}

// PluginsPlan is the part "plugins" of the answer to /push/begin (agent 0.9.0).
type PluginsPlan struct {
	OK         bool               `json:"ok"`
	Error      *PluginsFailure    `json:"error"`
	Activate   []PluginActivate   `json:"activate"`
	Deactivate []PluginDeactivate `json:"deactivate"`
	// Warnings: deactivation_review, requirements_unchecked.
	Warnings []string `json:"warnings"`
	// HealthURLs: a page in the admin context of the target (admin-ajax.php), checked before and after.
	HealthURLs []string `json:"health_urls"`
}

// PluginActivated, PluginDeactivated and PluginSkipped are the entries of PluginsApplied.
type PluginActivated struct {
	Unit string `json:"unit"`
	File string `json:"file"`
}

type PluginDeactivated struct {
	Unit  string   `json:"unit"`
	Files []string `json:"files"`
}

type PluginSkipped struct {
	Unit string `json:"unit"`
	Why  string `json:"why,omitempty"`
}

// PluginsApplied is the part "plugins" of the answer to /push/commit: what the transaction changed
// in active_plugins. Unchanged names units that were in the wanted state already.
type PluginsApplied struct {
	Activated   []PluginActivated   `json:"activated"`
	Deactivated []PluginDeactivated `json:"deactivated"`
	Unchanged   []string            `json:"unchanged"`
	Skipped     []PluginSkipped     `json:"skipped"`
}

// RollbackPlugins is what a rollback changed in active_plugins: the entries it took out again
// and the ones it brought back. Entries already in the state before the push are not named.
type RollbackPlugins struct {
	Deactivated []string `json:"deactivated"`
	Reactivated []string `json:"reactivated"`
	// DeactivatedTotal, ReactivatedTotal: how many entries there are, when a list does not show all of
	// them (more than 100, or entries that are none); omitted when the list is complete.
	DeactivatedTotal int `json:"deactivated_total,omitempty"`
	ReactivatedTotal int `json:"reactivated_total,omitempty"`
}

// PluginsKept names the entries of a push that still stand because rescue.php left the database
// part of the push on the site (warning plugins_not_restored).
type PluginsKept struct {
	Added   []string `json:"added"`
	Removed []string `json:"removed"`
	// AddedTotal, RemovedTotal: as in RollbackPlugins. Unknown: the commit of the push never noted
	// what it changed (it died right after the COMMIT) – empty lists then mean "not known", not "nothing".
	AddedTotal   int  `json:"added_total,omitempty"`
	RemovedTotal int  `json:"removed_total,omitempty"`
	Unknown      bool `json:"unknown,omitempty"`
}

func cleanWord(s string) string {
	if stepRe.MatchString(s) {
		return s
	}
	return ""
}

func cleanShort(s string) string {
	s = CleanText(s)
	if r := []rune(s); len(r) > maxPluginText {
		s = string(r[:maxPluginText])
	}
	return s
}

func cleanLabel(p *string) *string {
	if p == nil {
		return nil
	}
	s := cleanShort(*p)
	return &s
}

// agentEntry reports whether s is an entry of active_plugins as the agent switches and names it:
// "<slug>/<path>.php", valid UTF-8, no empty segment, no "." and no "..". Such an entry is data for a
// caller (JSON); for people it is shown as it is only in the narrow form of PluginEntry, quoted otherwise.
func agentEntry(s string) bool {
	if len(s) > 255 || !utf8.ValidString(s) || !agentEntryRe.MatchString(s) {
		return false
	}
	for _, seg := range strings.Split(s, "/") {
		if seg == "" || seg == "." || seg == ".." {
			return false
		}
	}
	return true
}

// cleanEntries keeps entries of active_plugins in the agent's form, at most 100; never nil.
func cleanEntries(in []string) []string {
	out := []string{}
	for _, e := range in {
		if len(out) < maxPluginEntries && agentEntry(e) {
			out = append(out, e)
		}
	}
	return out
}

// entriesTotal is how many entries a list has when that is more than it shows: the larger of what the
// site says and what it sent; 0 when the list is complete. A list is never silently incomplete (S6).
func entriesTotal(said, sent, shown int) int {
	total := max(said, sent)
	if total <= shown || total > 1<<20 {
		if sent > shown {
			return sent
		}
		return 0
	}
	return total
}

// cleanUnits keeps names of plugin units, at most 2×20; never nil.
func cleanUnits(in []string) []string {
	out := []string{}
	for _, u := range in {
		if len(out) < 2*maxPluginUnits && pluginUnitRe.MatchString(u) {
			out = append(out, u)
		}
	}
	return out
}

func cleanRefusals(in []PluginRefusal) []PluginRefusal {
	var out []PluginRefusal
	for _, p := range in {
		if len(out) == 2*maxPluginUnits || !pluginUnitRe.MatchString(p.Unit) {
			continue
		}
		out = append(out, PluginRefusal{Unit: p.Unit, Why: cleanWord(p.Why), Needs: cleanShort(p.Needs), Has: cleanShort(p.Has)})
	}
	return out
}

// Clean bounds and cleans what came from the site.
func (f *PluginsFailure) Clean() {
	if !stepRe.MatchString(f.Code) {
		f.Code = "plugins_failed"
	}
	f.Message = CleanText(f.Message)
	f.Plugins = cleanRefusals(f.Plugins)
}

// Clean bounds and cleans what came from the site: units and entries only in their form, names
// and versions without control characters, codes only as codes.
func (p *PluginsPlan) Clean() {
	if p.Error != nil {
		p.Error.Clean()
	}
	activate := []PluginActivate{}
	for _, a := range p.Activate {
		if len(activate) == maxPluginUnits || !pluginUnitRe.MatchString(a.Unit) {
			continue
		}
		a.State, a.Why = cleanWord(a.State), cleanWord(a.Why)
		if a.File != nil && len(cleanEntries([]string{*a.File})) == 0 {
			a.File = nil
		}
		a.Name, a.Version = cleanLabel(a.Name), cleanLabel(a.Version)
		a.Requirements.Checked = cleanWord(a.Requirements.Checked)
		failed := []PluginRequirement{}
		for _, r := range a.Requirements.Failed {
			if len(failed) < 10 && stepRe.MatchString(r.Why) {
				failed = append(failed, PluginRequirement{Why: r.Why, Needs: cleanShort(r.Needs), Has: cleanShort(r.Has)})
			}
		}
		a.Requirements.Failed = failed
		activate = append(activate, a)
	}
	p.Activate = activate
	deactivate := []PluginDeactivate{}
	for _, d := range p.Deactivate {
		if len(deactivate) == maxPluginUnits || !pluginUnitRe.MatchString(d.Unit) {
			continue
		}
		d.State = cleanWord(d.State)
		d.Files, d.RequiredBy = cleanEntries(d.Files), cleanUnits(d.RequiredBy)
		d.Name, d.Version = cleanLabel(d.Name), cleanLabel(d.Version)
		deactivate = append(deactivate, d)
	}
	p.Deactivate = deactivate
	warnings := []string{}
	for _, w := range p.Warnings {
		if len(warnings) < 10 && warningRe.MatchString(w) {
			warnings = append(warnings, w)
		}
	}
	p.Warnings = warnings
	if len(p.HealthURLs) > 3 {
		p.HealthURLs = p.HealthURLs[:3]
	}
}

// Clean bounds and cleans what came from the site.
func (a *PluginsApplied) Clean() {
	var activated []PluginActivated
	for _, e := range a.Activated {
		if len(activated) < maxPluginUnits && pluginUnitRe.MatchString(e.Unit) && len(cleanEntries([]string{e.File})) == 1 {
			activated = append(activated, e)
		}
	}
	var deactivated []PluginDeactivated
	for _, e := range a.Deactivated {
		if len(deactivated) < maxPluginUnits && pluginUnitRe.MatchString(e.Unit) {
			deactivated = append(deactivated, PluginDeactivated{Unit: e.Unit, Files: cleanEntries(e.Files)})
		}
	}
	var skipped []PluginSkipped
	for _, e := range a.Skipped {
		if len(skipped) < maxPluginUnits && pluginUnitRe.MatchString(e.Unit) {
			skipped = append(skipped, PluginSkipped{Unit: e.Unit, Why: cleanWord(e.Why)})
		}
	}
	a.Activated, a.Deactivated, a.Skipped = activated, deactivated, skipped
	if a.Unchanged = cleanUnits(a.Unchanged); len(a.Unchanged) == 0 {
		a.Unchanged = nil
	}
}

func (p *RollbackPlugins) clean() *RollbackPlugins {
	if p == nil {
		return nil
	}
	out := &RollbackPlugins{Deactivated: cleanEntries(p.Deactivated), Reactivated: cleanEntries(p.Reactivated)}
	out.DeactivatedTotal = entriesTotal(p.DeactivatedTotal, len(p.Deactivated), len(out.Deactivated))
	out.ReactivatedTotal = entriesTotal(p.ReactivatedTotal, len(p.Reactivated), len(out.Reactivated))
	return out
}

func (p *PluginsKept) clean() *PluginsKept {
	if p == nil {
		return nil
	}
	out := &PluginsKept{Added: cleanEntries(p.Added), Removed: cleanEntries(p.Removed), Unknown: p.Unknown}
	out.AddedTotal = entriesTotal(p.AddedTotal, len(p.Added), len(out.Added))
	out.RemovedTotal = entriesTotal(p.RemovedTotal, len(p.Removed), len(out.Removed))
	return out
}
