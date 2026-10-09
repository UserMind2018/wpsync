// Package profile decides what a pull transfers (Spec 5.2): a preset plus explicit choices,
// resolved against the agent's infosheet. It is stored in the site config under "profile".
package profile

import (
	"fmt"
	"slices"
	"sort"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Table modes (Spec 5.2).
const (
	ModeFull      = "full"
	ModeStructure = "structure"
	ModeSkip      = "skip"
)

// Presets (Spec 5.2).
const (
	PresetNoTransactions = "ohne-transaktionen"
	PresetContent        = "nur-content"
	PresetFull           = "vollstaendig"
)

// Presets in display order.
var Presets = []struct{ Name, Label string }{
	{PresetNoTransactions, "Ohne Transaktionsdaten (Standard) – ohne PII-, Log-, Cache- und Backup-Daten, ohne Revisionen, ohne inaktive Plugins/Themes"},
	{PresetContent, "Nur Content – zusätzlich ohne Daten unbekannter Plugin-Tabellen"},
	{PresetFull, "Vollständig – alles wie auf der Quelle"},
}

// UploadsBudget: presets pull the newest upload years up to this size, older ones come via proxy.
const UploadsBudget int64 = 1 << 30

// Profile is the pull scope of one site. The JSON names (scan --json) follow the YAML names.
type Profile struct {
	Preset    string  `yaml:"preset" json:"preset"`
	Tables    Tables  `yaml:"tables,omitempty" json:"tables"`
	PostTypes Choice  `yaml:"post_types,omitempty" json:"post_types"`
	Plugins   Choice  `yaml:"plugins,omitempty" json:"plugins"`
	Themes    Choice  `yaml:"themes,omitempty" json:"themes"`
	Uploads   Uploads `yaml:"uploads" json:"uploads"`
	Seen      Seen    `yaml:"seen,omitempty" json:"seen"`
}

// Tables overrides the preset mode of single tables (full, structure, skip).
type Tables struct {
	Overrides map[string]string `yaml:"overrides,omitempty" json:"overrides,omitempty"`
}

// Choice overrides the preset rule for single post types, plugins or themes.
type Choice struct {
	Include []string `yaml:"include,omitempty" json:"include,omitempty"`
	Exclude []string `yaml:"exclude,omitempty" json:"exclude,omitempty"`
}

// Uploads: Since is the oldest year pulled ("" = all years); older years come via proxy.
type Uploads struct {
	Since string `yaml:"since,omitempty" json:"since"`
	Proxy bool   `yaml:"proxy" json:"proxy"`
}

// Seen is what the profile was decided on – new entries are reported by scan and pull.
type Seen struct {
	Tables    []string `yaml:"tables,omitempty" json:"tables,omitempty"`
	Plugins   []string `yaml:"plugins,omitempty" json:"plugins,omitempty"`
	PostTypes []string `yaml:"post_types,omitempty" json:"post_types,omitempty"`
}

// New creates a profile from a preset.
func New(sheet *agentapi.Infosheet, preset string) (*Profile, error) {
	if !ValidPreset(preset) {
		return nil, fmt.Errorf("unbekanntes Preset %q (ohne-transaktionen, nur-content, vollstaendig)", preset)
	}
	p := &Profile{Preset: preset, Uploads: Uploads{Proxy: true}}
	if preset != PresetFull {
		p.Uploads.Since = DefaultSince(sheet.Uploads, UploadsBudget)
	}
	p.MarkSeen(sheet)
	return p, nil
}

// ValidPreset reports whether name is a known preset.
func ValidPreset(name string) bool {
	for _, p := range Presets {
		if p.Name == name {
			return true
		}
	}
	return false
}

// IsYear reports whether s is a four-digit year.
func IsYear(s string) bool {
	if len(s) != 4 {
		return false
	}
	for _, r := range s {
		if r < '0' || r > '9' {
			return false
		}
	}
	return true
}

// DefaultSince keeps the newest upload years within budget (at least the newest one); "" if all fit.
func DefaultSince(uploads []agentapi.UploadYear, budget int64) string {
	var years []agentapi.UploadYear
	for _, u := range uploads {
		if IsYear(u.Year) {
			years = append(years, u)
		}
	}
	if len(years) == 0 {
		return ""
	}
	sort.Slice(years, func(i, j int) bool { return years[i].Year > years[j].Year })
	since := ""
	var total int64
	for i, y := range years {
		total += y.Bytes
		if i > 0 && total > budget {
			break
		}
		since = y.Year
	}
	if since == years[len(years)-1].Year {
		return ""
	}
	return since
}

// TableModes resolves every table: explicit override, else the preset rule of its class.
func (p *Profile) TableModes(tables []agentapi.TableInfo) map[string]string {
	out := make(map[string]string, len(tables))
	for _, t := range tables {
		if m, ok := p.Tables.Overrides[t.Name]; ok {
			out[t.Name] = m
			continue
		}
		out[t.Name] = p.presetTableMode(t)
	}
	return out
}

func (p *Profile) presetTableMode(t agentapi.TableInfo) string {
	if t.Essential || p.Preset == PresetFull {
		return ModeFull
	}
	switch t.Class {
	case "pii", "log", "cache", "backup":
		return ModeStructure
	case "unknown":
		if p.Preset == PresetContent {
			return ModeStructure
		}
	}
	return ModeFull
}

// PlainPII lists pii tables that are pulled with data although the agent has no anonymization
// rule for them – best effort must stay visible (Konzept 5.1a). Sorted.
func (p *Profile) PlainPII(tables []agentapi.TableInfo) []string {
	modes := p.TableModes(tables)
	var out []string
	for _, t := range tables {
		if t.Class == "pii" && !t.Anonymized && modes[t.Name] == ModeFull {
			out = append(out, t.Name)
		}
	}
	sort.Strings(out)
	return out
}

// ExcludedPostTypes lists post types whose rows stay on the server (sorted).
func (p *Profile) ExcludedPostTypes(types []agentapi.PostType) []string {
	out := slices.Clone(p.PostTypes.Exclude)
	for _, t := range types {
		if p.presetExcludesPostType(t) && !slices.Contains(p.PostTypes.Include, t.Name) {
			out = append(out, t.Name)
		}
	}
	return sortedUnique(out)
}

func (p *Profile) presetExcludesPostType(t agentapi.PostType) bool {
	return p.Preset != PresetFull && (t.Class == "log" || t.Class == "pii")
}

// ExcludedPlugins lists plugins that are not pulled; active ones get deactivated locally (AC-15).
func (p *Profile) ExcludedPlugins(plugins []agentapi.Component) []string {
	return p.excluded(plugins, p.Plugins, false)
}

// ExcludedThemes lists themes that are not pulled; the active theme and its parent never are.
func (p *Profile) ExcludedThemes(themes []agentapi.Component) []string {
	return p.excluded(themes, p.Themes, true)
}

func (p *Profile) excluded(items []agentapi.Component, c Choice, keepActive bool) []string {
	var out []string
	active := map[string]bool{}
	for _, it := range items {
		active[it.Slug] = it.Active
		if p.presetExcludesComponent(it) && !slices.Contains(c.Include, it.Slug) {
			out = append(out, it.Slug)
		}
	}
	for _, slug := range c.Exclude {
		if !keepActive || !active[slug] {
			out = append(out, slug)
		}
	}
	return sortedUnique(out)
}

func (p *Profile) presetExcludesComponent(c agentapi.Component) bool {
	return p.Preset != PresetFull && !c.Active
}

// MarkSeen records the sheet's tables, plugins and post types.
func (p *Profile) MarkSeen(sheet *agentapi.Infosheet) {
	p.Seen = Seen{}
	for _, t := range sheet.Tables {
		p.Seen.Tables = append(p.Seen.Tables, t.Name)
	}
	for _, c := range sheet.Plugins {
		p.Seen.Plugins = append(p.Seen.Plugins, c.Slug)
	}
	for _, t := range sheet.PostTypes {
		p.Seen.PostTypes = append(p.Seen.PostTypes, t.Name)
	}
	sort.Strings(p.Seen.Tables)
	sort.Strings(p.Seen.Plugins)
	sort.Strings(p.Seen.PostTypes)
}

func sortedUnique(in []string) []string {
	sort.Strings(in)
	return slices.Compact(in)
}

func toSet(items []string) map[string]bool {
	set := make(map[string]bool, len(items))
	for _, it := range items {
		set[it] = true
	}
	return set
}
