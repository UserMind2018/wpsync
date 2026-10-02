package scan

import (
	"fmt"
	"io"
	"sort"
	"strings"
	"text/tabwriter"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

var classOrder = []string{"content", "config", "pii", "log", "cache", "backup", "unknown"}

var classLabels = map[string]string{
	"content": "Content", "config": "Konfiguration", "pii": "Transaktionen/PII",
	"log": "Log", "cache": "Cache", "backup": "Backup", "unknown": "unbekannt",
}

// Render prints the infosheet overview (Spec 5.1c).
func Render(w io.Writer, s *agentapi.Infosheet, now time.Time) {
	generated := time.Unix(s.GeneratedAt, 0)
	fmt.Fprintf(w, "Infosheet vom %s (%s), erstellt in %.0f s / %d Schritten\n",
		generated.Format("02.01.2006 15:04"), Age(now.Sub(generated)), s.Duration, s.Steps)
	fmt.Fprintf(w, "WordPress %s · PHP %s · DB %s · max_execution_time %d s · Speicher %s\n",
		s.Env.WPVersion, s.Env.PHPVersion, s.Env.DBServer, s.Env.MaxExecutionTime, s.Env.MemoryLimit)

	fmt.Fprintln(w, "\nPost-Typen (inkl. Metadaten)")
	tw := tabwriter.NewWriter(w, 0, 0, 2, ' ', 0)
	for _, t := range s.PostTypes {
		fmt.Fprintf(tw, "  %s\t%s\t%s\t%s\n", t.Name, Count(t.Count), Bytes(t.Bytes+t.MetaBytes), t.Class)
	}
	if s.OrphanMeta.Rows > 0 {
		fmt.Fprintf(tw, "  (verwaiste Metadaten)\t%s\t%s\t\n", Count(s.OrphanMeta.Rows), Bytes(s.OrphanMeta.Bytes))
	}
	tw.Flush()

	fmt.Fprintln(w, "\nTabellen nach Einstufung")
	tw = tabwriter.NewWriter(w, 0, 0, 2, ' ', 0)
	for _, class := range classOrder {
		var tables []agentapi.TableInfo
		var total int64
		for _, t := range s.Tables {
			if t.Class == class {
				tables = append(tables, t)
				total += t.Bytes
			}
		}
		if len(tables) == 0 {
			continue
		}
		sort.Slice(tables, func(i, j int) bool { return tables[i].Bytes > tables[j].Bytes })
		var names []string
		for i, t := range tables {
			if i == 3 {
				names = append(names, "…")
				break
			}
			names = append(names, t.Name)
		}
		fmt.Fprintf(tw, "  %s\t%d Tabellen\t%s\t%s\n", classLabels[class], len(tables), Bytes(total), strings.Join(names, ", "))
	}
	tw.Flush()

	fmt.Fprintf(w, "\nPlugins  %s\n", components(s.Plugins))
	fmt.Fprintf(w, "Themes   %s\n", components(s.Themes))

	var uploads []string
	for _, u := range s.Uploads {
		year := u.Year
		if year == "other" {
			year = "sonstige"
		}
		uploads = append(uploads, year+" "+Bytes(u.Bytes))
	}
	fmt.Fprintf(w, "Uploads  %s\n", strings.Join(uploads, " · "))

	if len(s.Findings) > 0 {
		fmt.Fprintln(w, "\nAuffälligkeiten")
	}
	for _, f := range s.Findings {
		switch f.Kind {
		case "backup_dir":
			fmt.Fprintf(w, "  ! Backup-Ordner %s (%s) – wird nie gezogen\n", f.Path, Bytes(f.Bytes))
		case "large_file":
			fmt.Fprintf(w, "  ! Datei über 256 MB: %s (%s) – wird übersprungen\n", f.Path, Bytes(f.Bytes))
		case "drop_in":
			if strings.HasSuffix(f.Path, "maintenance.php") {
				fmt.Fprintf(w, "  ! Drop-in %s – Wartungsmodus auf der Quelle?\n", f.Path)
			} else {
				fmt.Fprintf(w, "  ! Drop-in %s – wird lokal entfernt\n", f.Path)
			}
		}
	}
}

func components(items []agentapi.Component) string {
	var active, inactive int
	var activeBytes, inactiveBytes int64
	var inactiveNames []string
	for _, c := range items {
		if c.Active {
			active++
			activeBytes += c.Bytes
		} else {
			inactive++
			inactiveBytes += c.Bytes
			inactiveNames = append(inactiveNames, c.Slug)
		}
	}
	line := fmt.Sprintf("%d aktiv (%s)", active, Bytes(activeBytes))
	if inactive > 0 {
		line += fmt.Sprintf(", %d inaktiv: %s (%s)", inactive, strings.Join(inactiveNames, ", "), Bytes(inactiveBytes))
	}
	return line
}

// RenderSummary prints what a pull with profile p transfers.
func RenderSummary(w io.Writer, s *agentapi.Infosheet, p *profile.Profile) {
	var structure []string
	for name, mode := range p.TableModes(s.Tables) {
		if mode != profile.ModeFull {
			structure = append(structure, name)
		}
	}
	sort.Strings(structure)
	db, files := p.Estimate(s)

	fmt.Fprintf(w, "\nPull-Profil „%s“\n", p.Preset)
	fmt.Fprintf(w, "  Tabellen ohne Daten:       %s\n", list(structure))
	fmt.Fprintf(w, "  Post-Typen nicht gezogen:  %s\n", list(p.ExcludedPostTypes(s.PostTypes)))
	fmt.Fprintf(w, "  Plugins nicht gezogen:     %s\n", list(p.ExcludedPlugins(s.Plugins)))
	fmt.Fprintf(w, "  Themes nicht gezogen:      %s\n", list(p.ExcludedThemes(s.Themes)))
	uploads := "alle Jahre"
	if p.Uploads.Since != "" {
		uploads = "ab " + p.Uploads.Since
		if p.Uploads.Proxy {
			uploads += ", ältere per Proxy von der Quelle"
		}
	}
	fmt.Fprintf(w, "  Uploads:                   %s\n", uploads)
	switch plain := p.PlainPII(s.Tables); {
	case s.Env.Anon == "":
		fmt.Fprintln(w, "  Personenbezogene Daten:    Infosheet stammt von einem Agent ohne Anonymisierung – Agent 0.3.0 installieren, dann wpsync scan --refresh")
	case len(plain) == 0:
		fmt.Fprintln(w, "  Personenbezogene Daten:    werden auf der Site pseudonymisiert (Benutzer, Kommentare, WooCommerce)")
	default:
		fmt.Fprintf(w, "  Personenbezogene Daten:    pseudonymisiert – ausser (keine Regel, kommen im KLARTEXT): %s\n", list(plain))
	}
	fmt.Fprintf(w, "  Geschätzter Erst-Pull:     Datenbank ≈ %s, Dateien ≈ %s\n", Bytes(db), Bytes(files))
}

func list(items []string) string {
	if len(items) == 0 {
		return "–"
	}
	if len(items) > 8 {
		return fmt.Sprintf("%s … (+%d)", strings.Join(items[:8], ", "), len(items)-8)
	}
	return strings.Join(items, ", ")
}
