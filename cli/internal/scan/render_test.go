package scan

import (
	"bytes"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

func TestBytes(t *testing.T) {
	for n, want := range map[int64]string{0: "0 B", 1536: "1,5 KB", 812 << 10: "812 KB", 1288490189: "1,2 GB"} {
		if got := Bytes(n); got != want {
			t.Errorf("Bytes(%d) = %q, want %q", n, got, want)
		}
	}
}

func TestCount(t *testing.T) {
	for n, want := range map[int64]string{0: "0", 999: "999", 12431: "12.431", 1700000: "1.700.000"} {
		if got := Count(n); got != want {
			t.Errorf("Count(%d) = %q, want %q", n, got, want)
		}
	}
}

func TestAge(t *testing.T) {
	for d, want := range map[time.Duration]string{30 * time.Second: "gerade eben", 12 * time.Minute: "vor 12 min", 3 * time.Hour: "vor 3 h", 72 * time.Hour: "vor 3 Tagen"} {
		if got := Age(d); got != want {
			t.Errorf("Age(%s) = %q, want %q", d, got, want)
		}
	}
}

func renderSheet() *agentapi.Infosheet {
	return &agentapi.Infosheet{
		GeneratedAt: 1700000000, Duration: 38, Steps: 9,
		Env: agentapi.Env{WPVersion: "6.8.2", PHPVersion: "8.2.29", DBServer: "10.11.8-MariaDB", MaxExecutionTime: 30, MemoryLimit: "256M", TablePrefix: "wp_"},
		Tables: []agentapi.TableInfo{
			{Name: "wp_posts", Bytes: 2 << 30, Class: "content", Essential: true},
			{Name: "wp_e_submissions_values", Bytes: 80 << 20, Class: "pii", Plugin: "elementor"},
		},
		PostTypes: []agentapi.PostType{
			{Name: "revision", Count: 12431, Bytes: 1 << 30, MetaBytes: 600 << 20, Class: "log"},
			{Name: "page", Count: 46, Bytes: 5 << 20, Class: "content"},
		},
		OrphanMeta: agentapi.OrphanMeta{Rows: 3, Bytes: 300},
		Plugins:    []agentapi.Component{{Slug: "elementor", Active: true, Bytes: 40 << 20}, {Slug: "duplicator-pro", Bytes: 8 << 20}},
		Themes:     []agentapi.Component{{Slug: "astra", Active: true, Bytes: 3 << 20}},
		Uploads:    []agentapi.UploadYear{{Year: "2024", Bytes: 600 << 20}, {Year: "2025", Bytes: 500 << 20}, {Year: "other", Bytes: 1 << 20}},
		Findings: []agentapi.Finding{
			{Kind: "backup_dir", Path: "wp-content/backups-dup-pro", Bytes: 1800 << 20},
			{Kind: "large_file", Path: "wp-content/uploads/video.mp4", Bytes: 300 << 20},
			{Kind: "drop_in", Path: "wp-content/object-cache.php"},
		},
	}
}

func TestRender(t *testing.T) {
	var out bytes.Buffer
	Render(&out, renderSheet(), time.Unix(1700000000, 0).Add(3*time.Hour))
	got := out.String()
	for _, want := range []string{
		"vor 3 h", "WordPress 6.8.2", "PHP 8.2.29",
		"revision", "12.431", "1,6 GB", "log",
		"verwaiste Metadaten",
		"Transaktionen/PII", "wp_e_submissions_values",
		"1 aktiv", "1 inaktiv: duplicator-pro",
		"2024 600 MB", "sonstige 1,0 MB",
		"Backup-Ordner wp-content/backups-dup-pro (1,8 GB) – wird nie gezogen",
		"Datei über 256 MB: wp-content/uploads/video.mp4",
		"Drop-in wp-content/object-cache.php – wird lokal entfernt",
	} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in\n%s", want, got)
		}
	}
}

func TestRenderSummary(t *testing.T) {
	s := renderSheet()
	p, _ := profile.New(s, profile.PresetNoTransactions)
	var out bytes.Buffer
	RenderSummary(&out, s, p)
	got := out.String()
	for _, want := range []string{"ohne-transaktionen", "wp_e_submissions_values", "revision", "duplicator-pro", "ab 2025", "Proxy", "Datenbank ≈"} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in\n%s", want, got)
		}
	}
}

func TestRenderSummaryNamesPersonalData(t *testing.T) {
	s := renderSheet()
	s.Env.Anon = "1.abcd1234"
	p, _ := profile.New(s, profile.PresetNoTransactions)
	var out bytes.Buffer
	RenderSummary(&out, s, p)
	if !strings.Contains(out.String(), "werden auf der Site pseudonymisiert") {
		t.Errorf("standard preset:\n%s", out.String())
	}

	p, _ = profile.New(s, profile.PresetFull)
	out.Reset()
	RenderSummary(&out, s, p)
	if !strings.Contains(out.String(), "KLARTEXT") || !strings.Contains(out.String(), "wp_e_submissions_values") {
		t.Errorf("full preset must name uncovered pii tables:\n%s", out.String())
	}

	s.Env.Anon = ""
	out.Reset()
	RenderSummary(&out, s, p)
	if !strings.Contains(out.String(), "scan --refresh") {
		t.Errorf("sheet of an old agent must ask for a refresh:\n%s", out.String())
	}
}

func TestTableLabel(t *testing.T) {
	covered := tableLabel(agentapi.TableInfo{Name: "wp_wc_orders", Class: "pii", Plugin: "woocommerce", Bytes: 1 << 20, Anonymized: true})
	plain := tableLabel(agentapi.TableInfo{Name: "wp_e_submissions_values", Class: "pii", Plugin: "elementor", Bytes: 1 << 20})
	other := tableLabel(agentapi.TableInfo{Name: "wp_custom", Class: "unknown", Bytes: 1 << 20})
	if !strings.Contains(covered, "woocommerce · pseudonymisiert") {
		t.Errorf("covered = %q", covered)
	}
	if !strings.Contains(plain, "elementor · KLARTEXT") {
		t.Errorf("plain = %q", plain)
	}
	if strings.Contains(other, "KLARTEXT") || strings.Contains(other, "·") {
		t.Errorf("other = %q", other)
	}
}
