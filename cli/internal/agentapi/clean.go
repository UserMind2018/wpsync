package agentapi

import (
	"strings"
	"unicode/utf8"
)

// Unsafe reports characters a terminal interprets instead of showing: C0 controls (including
// newline and tab), DEL, C1 controls and the Bidi embeddings, overrides and isolates that reorder
// what is shown. Invalid UTF-8 counts as unsafe too – a lone byte 0x9b is an 8-bit CSI.
func Unsafe(r rune) bool {
	return r < 0x20 || (r >= 0x7f && r <= 0x9f) ||
		(r >= 0x202a && r <= 0x202e) || (r >= 0x2066 && r <= 0x2069) || r == utf8.RuneError
}

// CleanText makes text from the site safe for the terminal while keeping it readable: unsafe
// characters become U+FFFD, umlauts and other Unicode stay as they are. For messages; names and
// paths that must stay unambiguous go through Printable.
func CleanText(s string) string {
	if !strings.ContainsFunc(s, Unsafe) && utf8.ValidString(s) {
		return s
	}
	var b strings.Builder
	for _, r := range s {
		if Unsafe(r) {
			r = utf8.RuneError
		}
		b.WriteRune(r)
	}
	return b.String()
}
