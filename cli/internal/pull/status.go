package pull

import (
	"fmt"
	"io"
	"path/filepath"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// Status shows what changed on the source since the last pull, without transferring content
// (AC-29). It asks the agent for infosheet and delta only; profile deviations are just reported.
func Status(o Options) error {
	siteDir := filepath.Join(o.SitesRoot, o.Site.Name)
	base, err := baseline.Load(siteDir)
	if err != nil {
		return fmt.Errorf("load baseline: %w", err)
	}
	if base.Empty() {
		fmt.Fprintf(o.Out, "Noch kein Pull – wpsync pull %s\n", o.Site.Name)
		return nil
	}
	client := agentapi.New(o.Site.URL, o.Site.KeyID, o.Secret, o.Site.RPS)
	p, err := prepare(client, &o, false)
	if err != nil {
		return err
	}
	changed, deleted := DiffFiles(p.delta.Files, base, nil)
	deleted = inScope(deleted, p.scope)
	tables := ChangedTables(p.delta.Tables, base)

	fmt.Fprintf(o.Out, "Letzter Pull: %s von %s\n", base.PulledAt.Local().Format("02.01.2006 15:04"), o.Site.URL)
	fmt.Fprintf(o.Out, "\nDateien: %d neu/geändert, %d gelöscht\n", len(changed), len(deleted))
	var lines []string
	for _, f := range changed {
		lines = append(lines, "  + "+f.Path)
	}
	for _, path := range deleted {
		lines = append(lines, "  - "+path)
	}
	printLimited(o.Out, lines, 20)

	fmt.Fprintf(o.Out, "\nTabellen: %d geändert\n", len(tables))
	lines = nil
	for _, t := range tables {
		lines = append(lines, "  "+t.Name+modeNote(t, base))
	}
	printLimited(o.Out, lines, 20)

	fmt.Fprintf(o.Out, "\n%d Requests, keine Inhalte übertragen.\n", client.Stats.Requests)
	return nil
}

func modeNote(t agentapi.Table, b *baseline.Baseline) string {
	old := b.Mode(t.Name)
	switch {
	case old == "":
		return " (neu)"
	case old != t.Mode:
		return fmt.Sprintf(" (Profil: %s → %s)", old, t.Mode)
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
