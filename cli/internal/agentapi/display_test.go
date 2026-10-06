package agentapi

import (
	"strings"
	"testing"
)

func TestPrintable(t *testing.T) {
	cases := map[string]string{
		"wp_posts":         `"wp_posts"`,
		"a\x1b[2Jb\x7f":    `"a\x1b[2Jb\x7f"`,
		"a\nb\x00":         `"a\nb\x00"`,
		"wp_t\u00e4belle":  "\"wp_t\\u00e4belle\"",
		"wp\uff3fposts":    "\"wp\\uff3fposts\"",
		"wp_\u202egnp.sql": "\"wp_\\u202egnp.sql\"", // Bidi override would reverse the shown name
		"../../etc/x":      `"../../etc/x"`,
	}
	for in, want := range cases {
		if got := Printable(in); got != want {
			t.Errorf("Printable(%q) = %s, want %s", in, got, want)
		}
	}

	long := strings.Repeat("a", 79) + "\x1b" + strings.Repeat("b", 100)
	got := Printable(long)
	if want := `"` + strings.Repeat("a", 79) + `\x1b"…`; got != want {
		t.Errorf("long = %s, want %s", got, want)
	}
	if got := Printable(strings.Repeat("c", 80)); strings.HasSuffix(got, "…") {
		t.Errorf("exactly 80 bytes must not be cut: %s", got)
	}
}

// M3: messages of the agent stay readable, but cannot steer the terminal.
func TestCleanText(t *testing.T) {
	cases := map[string]string{
		"Zurückrollen": "Zurückrollen",
		"Push-Fenster geschlossen – öffnen ß": "Push-Fenster geschlossen – öffnen ß",
		"日本語 € 😀":                             "日本語 € 😀",
		"\x1b]52;c;ZWNobyBoaQ==\x07":          "�]52;c;ZWNobyBoaQ==�",
		"a\x1b[2Jb\x7f":                       "a�[2Jb�",
		"a\nb\tc\rd\x00":                      "a�b�c�d�",
		"a\u009b31mb\u0085":                   "a�31mb�",
		"wp_‮gnp.sql":                         "wp_�gnp.sql",
		"‪‫‬‭⁦⁧⁨⁩":                            strings.Repeat("�", 8),
		"a\x9bb":                              "a�b", // invalid UTF-8: an 8-bit CSI
	}
	for in, want := range cases {
		if got := CleanText(in); got != want {
			t.Errorf("CleanText(%q) = %q, want %q", in, got, want)
		}
	}
}
