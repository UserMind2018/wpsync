// Package scan shows the agent's infosheet and turns a selection into a pull profile (Spec 5.1c).
package scan

import (
	"errors"
	"fmt"
	"io"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

// Adjust are non-interactive tweaks on top of a preset (flags of wpsync scan).
type Adjust struct {
	ExcludePlugins   []string
	ExcludePostTypes []string
	UploadsSince     string
}

func (a Adjust) apply(p *profile.Profile) {
	p.Plugins.Exclude = append(p.Plugins.Exclude, a.ExcludePlugins...)
	p.PostTypes.Exclude = append(p.PostTypes.Exclude, a.ExcludePostTypes...)
	if a.UploadsSince != "" {
		p.Uploads.Since = a.UploadsSince
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
}

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
		return nil, errors.New("kein Terminal für die Auswahl – Preset angeben, z. B. --preset ohne-transaktionen")
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
