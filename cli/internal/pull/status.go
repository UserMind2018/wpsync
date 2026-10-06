package pull

import (
	"fmt"
	"io"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// StatusResult is what changed on the source since the last pull (wpsync status --json).
type StatusResult struct {
	Pulled       bool          `json:"pulled"`
	LastPull     *time.Time    `json:"last_pull,omitempty"`
	Source       string        `json:"source"`
	FilesChanged []string      `json:"files_changed"`
	FilesDeleted []string      `json:"files_deleted"`
	Tables       []TableChange `json:"tables_changed"`
	Requests     int           `json:"requests"`
}

// TableChange is a changed table; PreviousMode is empty for a table new since the last pull.
type TableChange struct {
	Name         string `json:"name"`
	Mode         string `json:"mode"`
	PreviousMode string `json:"previous_mode"`
}

// StatusReport compares the source with the last pull, without transferring content (AC-29).
// It asks the agent for infosheet and delta only; profile deviations are just reported.
func StatusReport(o Options) (*StatusResult, error) {
	res := &StatusResult{Source: o.Site.URL, FilesChanged: []string{}, FilesDeleted: []string{}, Tables: []TableChange{}}
	siteDir, _, err := o.dirs()
	if err != nil {
		return nil, err
	}
	base, err := baseline.Load(siteDir)
	if err != nil {
		return nil, fmt.Errorf("load baseline: %w", err)
	}
	if base.Empty() {
		return res, nil
	}
	client := agentapi.New(o.Site.URL, o.Site.KeyID, o.Secret, o.Site.RPS)
	client.Ctx = o.Ctx
	p, err := prepare(client, &o, false)
	if err != nil {
		return nil, err
	}
	changed, deleted := DiffFiles(p.delta.Files, base, nil)
	res.Pulled, res.LastPull = true, &base.PulledAt
	for _, f := range changed {
		res.FilesChanged = append(res.FilesChanged, f.Path)
	}
	res.FilesDeleted = append(res.FilesDeleted, inScope(deleted, p.scope)...)
	for _, t := range ChangedTables(p.delta.Tables, base) {
		res.Tables = append(res.Tables, TableChange{Name: t.Name, Mode: t.Mode, PreviousMode: base.Mode(t.Name)})
	}
	res.Requests = client.Stats.Requests
	return res, nil
}

// Status prints StatusReport for the terminal.
func Status(o Options) error {
	res, err := StatusReport(o)
	if err != nil {
		return err
	}
	if !res.Pulled {
		fmt.Fprintf(o.Out, "Noch kein Pull – wpsync pull %s\n", o.Site.Name)
		return nil
	}
	fmt.Fprintf(o.Out, "Letzter Pull: %s von %s\n", res.LastPull.Local().Format("02.01.2006 15:04"), o.Site.URL)
	fmt.Fprintf(o.Out, "\nDateien: %d neu/geändert, %d gelöscht\n", len(res.FilesChanged), len(res.FilesDeleted))
	var lines []string
	for _, path := range res.FilesChanged {
		lines = append(lines, "  + "+path)
	}
	for _, path := range res.FilesDeleted {
		lines = append(lines, "  - "+path)
	}
	printLimited(o.Out, lines, 20)

	fmt.Fprintf(o.Out, "\nTabellen: %d geändert\n", len(res.Tables))
	lines = nil
	for _, t := range res.Tables {
		lines = append(lines, "  "+t.Name+modeNote(t))
	}
	printLimited(o.Out, lines, 20)

	fmt.Fprintf(o.Out, "\n%d Requests, keine Inhalte übertragen.\n", res.Requests)
	return nil
}

func modeNote(t TableChange) string {
	switch {
	case t.PreviousMode == "":
		return " (neu)"
	case t.PreviousMode != t.Mode:
		return fmt.Sprintf(" (Profil: %s → %s)", t.PreviousMode, t.Mode)
	}
	return ""
}

func printLimited(w io.Writer, lines []string, limit int) {
	for i, line := range lines {
		if i == limit {
			fmt.Fprintf(w, "  … und %d weitere\n", len(lines)-limit)
			return
		}
		fmt.Fprintln(w, line)
	}
}
