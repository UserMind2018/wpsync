package profile

import (
	"slices"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Deviations are sheet entries the profile has not seen yet.
type Deviations struct {
	Tables, Plugins, PostTypes []string
}

// Empty reports whether nothing is new.
func (d Deviations) Empty() bool { return len(d.Tables)+len(d.Plugins)+len(d.PostTypes) == 0 }

// Lines describes the deviations in German, one line per kind.
func (d Deviations) Lines() []string {
	var out []string
	for _, kind := range []struct {
		label string
		items []string
	}{{"Tabellen", d.Tables}, {"Plugins", d.Plugins}, {"Post-Typen", d.PostTypes}} {
		if len(kind.items) > 0 {
			out = append(out, kind.label+": "+strings.Join(kind.items, ", "))
		}
	}
	return out
}

// Deviations lists what the sheet has that the profile has not seen.
func (p *Profile) Deviations(sheet *agentapi.Infosheet) Deviations {
	var d Deviations
	for _, t := range sheet.Tables {
		if !slices.Contains(p.Seen.Tables, t.Name) {
			d.Tables = append(d.Tables, t.Name)
		}
	}
	for _, c := range sheet.Plugins {
		if !slices.Contains(p.Seen.Plugins, c.Slug) {
			d.Plugins = append(d.Plugins, c.Slug)
		}
	}
	for _, t := range sheet.PostTypes {
		if !slices.Contains(p.Seen.PostTypes, t.Name) {
			d.PostTypes = append(d.PostTypes, t.Name)
		}
	}
	return d
}

// Selection is what is ticked in the scan checklist.
type Selection struct {
	PostTypes    []string // pulled
	Tables       []string // pulled with data; only non-essential tables are offered
	Plugins      []string // pulled
	Themes       []string // pulled; only inactive themes are offered
	UploadsSince string
}

// Preselected is the checklist's starting point for this profile.
func (p *Profile) Preselected(sheet *agentapi.Infosheet) Selection {
	sel := Selection{UploadsSince: p.Uploads.Since}
	modes := p.TableModes(sheet.Tables)
	for _, t := range sheet.Tables {
		if !t.Essential && modes[t.Name] == ModeFull {
			sel.Tables = append(sel.Tables, t.Name)
		}
	}
	skip := toSet(p.ExcludedPostTypes(sheet.PostTypes))
	for _, t := range sheet.PostTypes {
		if !skip[t.Name] {
			sel.PostTypes = append(sel.PostTypes, t.Name)
		}
	}
	skip = toSet(p.ExcludedPlugins(sheet.Plugins))
	for _, c := range sheet.Plugins {
		if !skip[c.Slug] {
			sel.Plugins = append(sel.Plugins, c.Slug)
		}
	}
	skip = toSet(p.ExcludedThemes(sheet.Themes))
	for _, c := range sheet.Themes {
		if !c.Active && !skip[c.Slug] {
			sel.Themes = append(sel.Themes, c.Slug)
		}
	}
	return sel
}

// Apply stores sel as explicit choices wherever it differs from the preset rule.
func (p *Profile) Apply(sheet *agentapi.Infosheet, sel Selection) {
	p.Tables = Tables{}
	p.PostTypes, p.Plugins, p.Themes = Choice{}, Choice{}, Choice{}

	withData := toSet(sel.Tables)
	for _, t := range sheet.Tables {
		if t.Essential {
			continue
		}
		want := ModeStructure
		if withData[t.Name] {
			want = ModeFull
		}
		if want != p.presetTableMode(t) {
			if p.Tables.Overrides == nil {
				p.Tables.Overrides = map[string]string{}
			}
			p.Tables.Overrides[t.Name] = want
		}
	}
	pulled := toSet(sel.PostTypes)
	for _, t := range sheet.PostTypes {
		p.PostTypes = choose(p.PostTypes, t.Name, !pulled[t.Name], p.presetExcludesPostType(t))
	}
	pulled = toSet(sel.Plugins)
	for _, c := range sheet.Plugins {
		p.Plugins = choose(p.Plugins, c.Slug, !pulled[c.Slug], p.presetExcludesComponent(c))
	}
	pulled = toSet(sel.Themes)
	for _, c := range sheet.Themes {
		if !c.Active {
			p.Themes = choose(p.Themes, c.Slug, !pulled[c.Slug], p.presetExcludesComponent(c))
		}
	}
	p.Uploads.Since = sel.UploadsSince
	p.MarkSeen(sheet)
}

// choose records name when the wanted exclusion differs from the preset rule.
func choose(c Choice, name string, exclude, presetExcludes bool) Choice {
	switch {
	case exclude && !presetExcludes:
		c.Exclude = append(c.Exclude, name)
	case !exclude && presetExcludes:
		c.Include = append(c.Include, name)
	}
	return c
}

// Estimate approximates the transfer size of a first pull with this profile.
func (p *Profile) Estimate(sheet *agentapi.Infosheet) (dbBytes, fileBytes int64) {
	skipTypes := toSet(p.ExcludedPostTypes(sheet.PostTypes))
	var postsCut, metaCut int64
	for _, t := range sheet.PostTypes {
		if skipTypes[t.Name] {
			postsCut += t.Bytes
			metaCut += t.MetaBytes
		}
	}
	modes := p.TableModes(sheet.Tables)
	for _, t := range sheet.Tables {
		if modes[t.Name] != ModeFull {
			continue
		}
		b := t.Bytes
		switch strings.TrimPrefix(t.Name, sheet.Env.TablePrefix) {
		case "posts":
			b -= postsCut
		case "postmeta":
			b -= metaCut
		}
		dbBytes += max(b, 0)
	}
	skip := toSet(p.ExcludedPlugins(sheet.Plugins))
	for _, c := range sheet.Plugins {
		if !skip[c.Slug] {
			fileBytes += c.Bytes
		}
	}
	skip = toSet(p.ExcludedThemes(sheet.Themes))
	for _, c := range sheet.Themes {
		if !skip[c.Slug] {
			fileBytes += c.Bytes
		}
	}
	for _, u := range sheet.Uploads {
		if !IsYear(u.Year) || p.Uploads.Since == "" || u.Year >= p.Uploads.Since {
			fileBytes += u.Bytes
		}
	}
	return dbBytes, fileBytes
}
