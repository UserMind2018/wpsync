// Package scan shows the agent's infosheet and turns a selection into a pull profile (Spec 5.1c).
package scan

import (
	"errors"
	"fmt"
	"io"
	"sort"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

// Adjust are non-interactive tweaks on top of a preset (flags of wpsync scan).
type Adjust struct {
	ExcludePlugins   []string
	ExcludePostTypes []string
	UploadsSince     string
	// AllUploads pulls every upload year (--uploads-since alle); needed without uploads proxy.
	AllUploads bool
	// Tables are overrides of single tables (--table <name>=structure|skip), see ParseTables.
	Tables map[string]string
}

// ErrEssentialTable: --table names a table that is always pulled with data.
var ErrEssentialTable = errors.New("--table: Kern-Tabelle kommt immer mit Daten und lässt sich nicht herabstufen")

// ParseTables reads the values of --table: <name>=structure|skip, the full table name with its
// prefix. Naming a table twice is fine as long as the mode is the same. nil without values.
func ParseTables(specs []string) (map[string]string, error) {
	var out map[string]string
	for _, spec := range specs {
		name, mode, ok := strings.Cut(spec, "=")
		if !ok || name == "" {
			return nil, fmt.Errorf("--table erwartet <tabelle>=structure|skip, nicht %q", spec)
		}
		if mode != profile.ModeStructure && mode != profile.ModeSkip {
			return nil, fmt.Errorf("--table %s: Modus %q unbekannt – structure oder skip", name, mode)
		}
		if prev, seen := out[name]; seen && prev != mode {
			return nil, fmt.Errorf("--table %s zweimal mit verschiedenen Modi (%s, %s)", name, prev, mode)
		}
		if out == nil {
			out = map[string]string{}
		}
		out[name] = mode
	}
	return out, nil
}

// check refuses an override of an essential table (the sheet says which ones are).
func (a Adjust) check(sheet *agentapi.Infosheet) error {
	var essential []string
	for _, t := range sheet.Tables {
		if _, ok := a.Tables[t.Name]; ok && t.Essential {
			essential = append(essential, t.Name)
		}
	}
	if len(essential) == 0 {
		return nil
	}
	sort.Strings(essential)
	return fmt.Errorf("%w: %s", ErrEssentialTable, strings.Join(essential, ", "))
}

// UnknownTables lists the tables of --table the sheet does not have (sorted). Their override is
// stored all the same and takes effect once the infosheet lists the table.
func (a Adjust) UnknownTables(sheet *agentapi.Infosheet) []string {
	known := make(map[string]bool, len(sheet.Tables))
	for _, t := range sheet.Tables {
		known[t.Name] = true
	}
	var out []string
	for name := range a.Tables {
		if !known[name] {
			out = append(out, name)
		}
	}
	sort.Strings(out)
	return out
}

func (a Adjust) apply(p *profile.Profile) {
	p.Plugins.Exclude = append(p.Plugins.Exclude, a.ExcludePlugins...)
	p.PostTypes.Exclude = append(p.PostTypes.Exclude, a.ExcludePostTypes...)
	if a.UploadsSince != "" {
		p.Uploads.Since = a.UploadsSince
	}
	if a.AllUploads {
		p.Uploads.Since = ""
	}
	for name, mode := range a.Tables {
		if p.Tables.Overrides == nil {
			p.Tables.Overrides = map[string]string{}
		}
		p.Tables.Overrides[name] = mode
	}
}

// Options for one scan.
type Options struct {
	Client  *agentapi.Client
	Current *profile.Profile // stored profile or nil
	Out     io.Writer
	Now     time.Time
	Refresh bool
	Preset  string // non-interactive: build the profile from this preset
	Adjust  Adjust
	// Select asks the user; nil without a terminal.
	Select func(sheet *agentapi.Infosheet, current *profile.Profile) (*profile.Profile, error)
	// OnSheet receives the infosheet the profile was decided on (scan --json); nil = not needed.
	OnSheet func(sheet *agentapi.Infosheet)
}

// ErrNeedsPreset: without a terminal (or with --json) the selection needs --preset.
var ErrNeedsPreset = errors.New("kein Terminal für die Auswahl – Preset angeben, z. B. --preset ohne-transaktionen")

// maxRefreshRounds bounds the refresh loop (1 request per second by default).
const maxRefreshRounds = 2000

var phaseNames = map[string]string{"meta": "Übersicht", "posts": "Beiträge", "postmeta": "Metadaten", "files": "Dateien", "done": "fertig"}

// Run fetches the infosheet (refreshing it if asked or missing), shows it and returns the profile to store.
func Run(o Options) (*profile.Profile, error) {
	sheet, job, err := o.Client.Infosheet()
	if err != nil {
		return nil, err
	}
	if o.Refresh || sheet == nil {
		if sheet, err = refresh(o.Client, o.Out); err != nil {
			return nil, err
		}
	} else if job.Running {
		fmt.Fprintf(o.Out, "Hinweis: Die Site aktualisiert ihr Infosheet gerade (%s) – angezeigt wird der letzte fertige Stand.\n\n", progressLine(job))
	}
	if o.OnSheet != nil {
		o.OnSheet(sheet)
	}
	Render(o.Out, sheet, o.Now)
	if o.Current != nil {
		if dev := o.Current.Deviations(sheet); !dev.Empty() {
			fmt.Fprintln(o.Out, "\nNeu seit dem gespeicherten Profil:")
			for _, line := range dev.Lines() {
				fmt.Fprintf(o.Out, "  %s\n", line)
			}
		}
	}

	var next *profile.Profile
	switch {
	case o.Preset != "":
		if err = o.Adjust.check(sheet); err != nil {
			return nil, err
		}
		if next, err = profile.New(sheet, o.Preset); err != nil {
			return nil, err
		}
		o.Adjust.apply(next)
	case o.Select != nil:
		if next, err = o.Select(sheet, o.Current); err != nil {
			return nil, err
		}
	case o.Current != nil:
		next = o.Current
		fmt.Fprintln(o.Out, "\nProfil unverändert (ändern: wpsync scan im Terminal oder mit --preset).")
	default:
		return nil, ErrNeedsPreset
	}
	RenderSummary(o.Out, sheet, next)
	fmt.Fprintf(o.Out, "\nRequests: %d\n", o.Client.Stats.Requests)
	return next, nil
}

// refresh lets the agent build a new infosheet slice by slice – works without WP-Cron (AC-9).
func refresh(c *agentapi.Client, out io.Writer) (*agentapi.Infosheet, error) {
	fmt.Fprintln(out, "Infosheet wird erstellt …")
	st, err := c.RefreshInfosheet(true)
	for round := 0; err == nil && st.Running; round++ {
		if round >= maxRefreshRounds {
			return nil, errors.New("infosheet: no progress after many rounds")
		}
		fmt.Fprintf(out, "  %s\n", progressLine(st))
		st, err = c.RefreshInfosheet(false)
	}
	if err != nil {
		return nil, err
	}
	sheet, _, err := c.Infosheet()
	if err != nil {
		return nil, err
	}
	if sheet == nil {
		return nil, errors.New("infosheet: agent finished but returned none")
	}
	fmt.Fprintf(out, "Infosheet erstellt in %.0f s (%d Schritte).\n\n", sheet.Duration, sheet.Steps)
	return sheet, nil
}

func progressLine(st agentapi.InfosheetStatus) string {
	name := phaseNames[st.Phase]
	if name == "" {
		name = st.Phase
	}
	switch {
	case st.Total > 0:
		return fmt.Sprintf("%s %s/%s", name, Count(st.Done), Count(st.Total))
	case st.Done > 0:
		return fmt.Sprintf("%s %s", name, Count(st.Done))
	}
	return name
}
