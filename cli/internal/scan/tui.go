package scan

import (
	"errors"
	"fmt"
	"slices"
	"sort"
	"strings"

	"charm.land/huh/v2"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

// Interactive lets the user pick a preset and optionally adjust it item by item (Spec 5.1c).
func Interactive(sheet *agentapi.Infosheet, current *profile.Profile) (*profile.Profile, error) {
	preset := profile.PresetNoTransactions
	if current != nil {
		preset = current.Preset
	}
	adjust := false
	var presets []huh.Option[string]
	for _, p := range profile.Presets {
		presets = append(presets, huh.NewOption(p.Label, p.Name))
	}
	if err := huh.NewForm(huh.NewGroup(
		huh.NewSelect[string]().Title("Was soll gezogen werden?").Options(presets...).Value(&preset),
		huh.NewConfirm().Title("Auswahl im Detail anpassen?").Affirmative("Ja").Negative("Nein").Value(&adjust),
	)).Run(); err != nil {
		return nil, aborted(err)
	}

	p, err := profile.New(sheet, preset)
	if err != nil {
		return nil, err
	}
	if current != nil && current.Preset == preset {
		kept := *current // earlier choices are the starting point
		p = &kept
	}
	if !adjust {
		p.MarkSeen(sheet)
		return p, nil
	}

	sel := p.Preselected(sheet)
	var groups []*huh.Group
	add := func(title string, opts []huh.Option[string], value *[]string) {
		if len(opts) > 0 {
			groups = append(groups, huh.NewGroup(huh.NewMultiSelect[string]().
				Title(title).Options(opts...).Value(value).Filterable(true).Height(min(len(opts)+2, 20))))
		}
	}
	add("Post-Typen, die gezogen werden", postTypeOptions(sheet, sel.PostTypes), &sel.PostTypes)
	add("Tabellen mit Daten (ohne Haken: nur Struktur)", tableOptions(sheet, sel.Tables), &sel.Tables)
	add("Plugins, die gezogen werden", componentOptions(sheet.Plugins, sel.Plugins, false), &sel.Plugins)
	add("Inaktive Themes, die gezogen werden", componentOptions(sheet.Themes, sel.Themes, true), &sel.Themes)
	groups = append(groups, huh.NewGroup(huh.NewSelect[string]().
		Title("Uploads ab Jahr (ältere lädt der Proxy bei Bedarf)").Options(yearOptions(sheet)...).Value(&sel.UploadsSince)))

	if err := huh.NewForm(groups...).Run(); err != nil {
		return nil, aborted(err)
	}
	p.Apply(sheet, sel)
	return p, nil
}

func aborted(err error) error {
	if errors.Is(err, huh.ErrUserAborted) {
		return errors.New("abgebrochen – Profil nicht geändert")
	}
	return err
}

func postTypeOptions(sheet *agentapi.Infosheet, selected []string) []huh.Option[string] {
	var out []huh.Option[string]
	for _, t := range sheet.PostTypes {
		label := fmt.Sprintf("%-24s %-8s %10s  %9s", t.Name, t.Class, Count(t.Count), Bytes(t.Bytes+t.MetaBytes))
		out = append(out, huh.NewOption(label, t.Name).Selected(slices.Contains(selected, t.Name)))
	}
	return out
}

func tableOptions(sheet *agentapi.Infosheet, selected []string) []huh.Option[string] {
	var tables []agentapi.TableInfo
	for _, t := range sheet.Tables {
		if !t.Essential {
			tables = append(tables, t)
		}
	}
	sort.Slice(tables, func(i, j int) bool {
		ci, cj := slices.Index(classOrder, tables[i].Class), slices.Index(classOrder, tables[j].Class)
		if ci != cj {
			return ci < cj
		}
		return tables[i].Bytes > tables[j].Bytes
	})
	var out []huh.Option[string]
	for _, t := range tables {
		label := tableLabel(t)
		out = append(out, huh.NewOption(label, t.Name).Selected(slices.Contains(selected, t.Name)))
	}
	return out
}

// tableLabel is one line of the table checklist: name, class, size, plugin and whether a
// pii table arrives pseudonymized or in plain text.
func tableLabel(t agentapi.TableInfo) string {
	note := t.Plugin
	switch {
	case t.Anonymized:
		note += " · pseudonymisiert"
	case t.Class == "pii":
		note += " · KLARTEXT"
	}
	return fmt.Sprintf("%-44s %-8s %9s  %s", t.Name, t.Class, Bytes(t.Bytes), strings.TrimPrefix(note, " · "))
}

func componentOptions(items []agentapi.Component, selected []string, onlyInactive bool) []huh.Option[string] {
	var out []huh.Option[string]
	for _, c := range items {
		if onlyInactive && c.Active {
			continue
		}
		state := "inaktiv"
		if c.Active {
			state = "aktiv"
		}
		label := fmt.Sprintf("%-36s %-8s %9s", c.Slug, state, Bytes(c.Bytes))
		out = append(out, huh.NewOption(label, c.Slug).Selected(slices.Contains(selected, c.Slug)))
	}
	return out
}

func yearOptions(sheet *agentapi.Infosheet) []huh.Option[string] {
	var years []agentapi.UploadYear
	var total int64
	for _, u := range sheet.Uploads {
		total += u.Bytes
		if profile.IsYear(u.Year) {
			years = append(years, u)
		}
	}
	out := []huh.Option[string]{huh.NewOption(fmt.Sprintf("alle Jahre (%s)", Bytes(total)), "")}
	sort.Slice(years, func(i, j int) bool { return years[i].Year > years[j].Year })
	var cumulative int64
	for _, y := range years {
		cumulative += y.Bytes
		out = append(out, huh.NewOption(fmt.Sprintf("ab %s (%s)", y.Year, Bytes(cumulative)), y.Year))
	}
	return out
}
