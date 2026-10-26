// Package pathmatch decides which paths below wp-content a profile excludes (Spec 0.10.0 A). The
// agent has the same rules in PathPatterns.php; both are tested against
// agent/tests/fixtures/pathmatch.json.
package pathmatch

import (
	"fmt"
	"sort"
	"strings"
)

// Limits of a pattern list (Spec 0.10.0 A4).
const (
	MaxEntries = 200
	MaxLen     = 512
)

// ErrTooMany: more than MaxEntries patterns.
var ErrTooMany = fmt.Errorf("mehr als %d Ausschluss-Muster", MaxEntries)

type kind int

const (
	dirPattern kind = iota
	filePattern
	namePattern
)

type pattern struct {
	kind kind
	segs []string // lower case
}

// Set is a compiled list; the zero value matches nothing.
type Set struct{ list []pattern }

// Normalize validates, deduplicates (case-insensitive) and sorts the patterns. The result is never nil.
func Normalize(in []string) ([]string, error) {
	if len(in) > MaxEntries {
		return nil, ErrTooMany
	}
	out := []string{}
	seen := map[string]bool{}
	for _, raw := range in {
		p, err := clean(raw)
		if err != nil {
			return nil, err
		}
		if key := lower(p); !seen[key] {
			seen[key] = true
			out = append(out, p)
		}
	}
	sort.SliceStable(out, func(i, j int) bool { return lower(out[i]) < lower(out[j]) })
	return out, nil
}

// Compile turns normalized or raw patterns into a Set.
func Compile(in []string) (*Set, error) {
	norm, err := Normalize(in)
	if err != nil {
		return nil, err
	}
	s := &Set{}
	for _, p := range norm {
		s.list = append(s.list, parse(p))
	}
	return s, nil
}

// MustCompile is Compile for lists that were validated before (the stored profile).
func MustCompile(in []string) *Set {
	s, err := Compile(in)
	if err != nil {
		panic(err)
	}
	return s
}

// Empty reports whether the set excludes nothing.
func (s *Set) Empty() bool { return s == nil || len(s.list) == 0 }

// Match reports whether rel (relative to wp-content, "/"-separated) is excluded.
func (s *Set) Match(rel string, isDir bool) bool {
	if s.Empty() {
		return false
	}
	segs := strings.Split(lower(rel), "/")
	for _, p := range s.list {
		switch p.kind {
		case dirPattern:
			n := len(p.segs)
			if len(segs) >= n && (len(segs) > n || isDir) && segsMatch(p.segs, segs[:n]) {
				return true
			}
		case filePattern:
			if !isDir && len(segs) == len(p.segs) && segsMatch(p.segs, segs) {
				return true
			}
		case namePattern:
			if !isDir && glob(p.segs[0], segs[len(segs)-1]) {
				return true
			}
		}
	}
	return false
}

// Touches reports whether a pattern may exclude something inside a push unit ("plugins/foo",
// "themes/bar", "mu-plugins").
func (s *Set) Touches(unit string) bool {
	if s.Empty() {
		return false
	}
	us := strings.Split(lower(unit), "/")
	for _, p := range s.list {
		if p.kind == namePattern {
			return true
		}
		if len(p.segs) > len(us) && segsMatch(p.segs[:len(us)], us) {
			return true
		}
	}
	return false
}

func parse(p string) pattern {
	switch {
	case strings.HasSuffix(p, "/"):
		return pattern{kind: dirPattern, segs: strings.Split(lower(strings.TrimSuffix(p, "/")), "/")}
	case strings.Contains(p, "/"):
		return pattern{kind: filePattern, segs: strings.Split(lower(p), "/")}
	}
	return pattern{kind: namePattern, segs: []string{lower(p)}}
}

func clean(raw string) (string, error) {
	p := strings.TrimSpace(raw)
	for strings.Contains(p, "//") {
		p = strings.ReplaceAll(p, "//", "/")
	}
	bad := func(why string) error {
		return fmt.Errorf("Ausschluss-Muster %q: %s", raw, why)
	}
	switch {
	case p == "" || p == "/":
		return "", bad("leer")
	case len(p) > MaxLen:
		return "", bad(fmt.Sprintf("länger als %d Zeichen", MaxLen))
	case strings.HasPrefix(p, "/"):
		return "", bad("relativ zu wp-content angeben, ohne führenden /")
	case strings.ContainsRune(p, '\\'):
		return "", bad("Backslash nicht erlaubt, Trenner ist /")
	case strings.ContainsFunc(p, func(r rune) bool { return r < 0x20 || r == 0x7f }):
		return "", bad("Steuerzeichen nicht erlaubt")
	case strings.Contains(p, "**"):
		return "", bad("** wird nicht unterstützt – * gilt innerhalb eines Ordnernamens")
	case strings.HasPrefix(p, "!"):
		return "", bad("Ausnahmen mit ! werden nicht unterstützt")
	case strings.Trim(p, "*?/") == "":
		return "", bad("würde alle Dateien ausschliessen")
	}
	segs := strings.Split(strings.TrimSuffix(p, "/"), "/")
	for _, s := range segs {
		if s == "." || s == ".." || s == "" {
			return "", bad("Ordner . und .. nicht erlaubt")
		}
	}
	isDir := strings.HasSuffix(p, "/")
	switch lower(segs[0]) {
	case "plugins", "themes":
		if len(segs) <= 2 {
			return "", bad("ganze Plugins und Themes über --exclude-plugin abwählen; erlaubt sind Unterordner wie plugins/<slug>/cache/")
		}
	case "mu-plugins":
		if len(segs) == 1 && isDir {
			return "", bad("mu-plugins lassen sich nicht als Ganzes ausschliessen")
		}
	}
	return p, nil
}

func segsMatch(pat, segs []string) bool {
	for i := range pat {
		if !glob(pat[i], segs[i]) {
			return false
		}
	}
	return true
}

// glob matches one segment: * any bytes, ? one byte, everything else literally.
func glob(pat, s string) bool {
	px, sx, star, mark := 0, 0, -1, 0
	for sx < len(s) {
		switch {
		case px < len(pat) && (pat[px] == '?' || pat[px] == s[sx]):
			px++
			sx++
		case px < len(pat) && pat[px] == '*':
			star, mark = px, sx
			px++
		case star >= 0:
			px, mark = star+1, mark+1
			sx = mark
		default:
			return false
		}
	}
	for px < len(pat) && pat[px] == '*' {
		px++
	}
	return px == len(pat)
}

// lower folds ASCII only – PHP's strtolower does the same, so both sides agree.
func lower(s string) string {
	b := []byte(s)
	for i, c := range b {
		if c >= 'A' && c <= 'Z' {
			b[i] = c + 32
		}
	}
	return string(b)
}
