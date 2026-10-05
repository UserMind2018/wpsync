package agentapi

import "strconv"

// maxDisplayBytes caps one server string in a message: a hostile site must not flood the terminal.
const maxDisplayBytes = 80

// Printable quotes a string chosen by the site for an error message. Control characters and
// non-ASCII are escaped, so the terminal neither interprets them nor shows look-alikes; overlong
// strings are cut.
func Printable(s string) string {
	if len(s) > maxDisplayBytes {
		return strconv.QuoteToASCII(s[:maxDisplayBytes]) + "…"
	}
	return strconv.QuoteToASCII(s)
}
