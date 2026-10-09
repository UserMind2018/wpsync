package push

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

// packageText builds a package like the studio does: compact JSON, "\n" after every line, the
// sha256 of the body in the head. head overrides fields of the head.
func packageText(t *testing.T, rows []string, head map[string]any) string {
	t.Helper()
	body := ""
	for _, r := range rows {
		body += r + "\n"
	}
	sum := sha256.Sum256([]byte(body))
	h := map[string]any{
		"list_version": 2, "extensions": map[string]any{}, "canon_version": 1, "variants": []string{"plain", "esc1", "esc2"},
		"corridor": map[string]any{"offset": 1000000, "posts": []int{1000001, 1999999}, "terms": []int{1000001, 1999999}, "term_taxonomy": []int{1000001, 1999999}},
		"home":     "https://kunde.de", "map_id": strings.Repeat("ab", 32), "local_host": "kunde.ddev.site",
		"rows": len(rows), "sha256": hex.EncodeToString(sum[:]),
	}
	for k, v := range head {
		h[k] = v
	}
	line, err := json.Marshal(map[string]any{"head": h})
	if err != nil {
		t.Fatal(err)
	}
	return string(line) + "\n" + body
}

func writePackage(t *testing.T, text string) string {
	t.Helper()
	file := filepath.Join(t.TempDir(), "package.jsonl")
	if err := os.WriteFile(file, []byte(text), 0o644); err != nil {
		t.Fatal(err)
	}
	return file
}

const (
	rowUpdate = `{"op":"update","table":"posts","key":"219","expected":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","row":{"post_title":"TmV1"}}`
	rowTrash  = `{"op":"trash","table":"posts","key":"220","expected":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}`
	rowMeta   = `{"op":"insert","table":"postmeta","key":"219\u0000_x","expected":"absent","row":{"values":["eA=="]}}`
)

// A trash may carry row with post_date and post_date_gmt; the form is the agent's to check.
func TestLoadPackageKeepsTheDatesOfATrash(t *testing.T) {
	dates := `{"post_date":"MjAyNi0xMC0wOSAxNjoxMzoyMA==","post_date_gmt":"MjAyNi0xMC0wOSAxNDoxMzoyMA=="}`
	row := strings.Replace(rowTrash, `"}`, `","row":`+dates+`}`, 1)
	p, err := LoadPackage(writePackage(t, packageText(t, []string{row, rowTrash219()}, nil)))
	if err != nil {
		t.Fatal(err)
	}
	if p.Rows[0].Op != "trash" || string(p.Rows[0].Row) != dates || p.Rows[1].Row != nil {
		t.Errorf("rows = %+v", p.Rows)
	}
}

func rowTrash219() string { return strings.Replace(rowTrash, "220", "219", 1) }

func TestLoadPackageReadsHeadRowsAndTheNameOfTheFile(t *testing.T) {
	text := packageText(t, []string{rowUpdate, rowTrash, rowMeta}, nil)
	p, err := LoadPackage(writePackage(t, text))
	if err != nil {
		t.Fatal(err)
	}
	whole := sha256.Sum256([]byte(text))
	if p.SHA256 != hex.EncodeToString(whole[:]) || string(p.Data) != text {
		t.Errorf("sha256 = %s", p.SHA256)
	}
	if p.Head.Rows != 3 || p.Head.Home != "https://kunde.de" || p.Head.CanonVersion != 1 || p.Head.MapID != strings.Repeat("ab", 32) {
		t.Errorf("head = %+v", p.Head)
	}
	if len(p.Rows) != 3 || p.Rows[0].Op != "update" || string(p.Rows[0].Row) != `{"post_title":"TmV1"}` || p.Rows[1].Row != nil || p.Rows[2].Key != "219\x00_x" {
		t.Errorf("rows = %+v", p.Rows)
	}
}

func TestLoadPackageRefusesWhatIsNoPackage(t *testing.T) {
	good := packageText(t, []string{rowUpdate, rowTrash}, nil)
	cases := map[string]string{
		"empty file":          "",
		"no final newline":    strings.TrimSuffix(good, "\n"),
		"crlf":                strings.ReplaceAll(good, "\n", "\r\n"),
		"no head":             rowUpdate + "\n",
		"wrong checksum":      packageText(t, []string{rowUpdate}, map[string]any{"sha256": strings.Repeat("0", 64)}),
		"fewer rows":          packageText(t, []string{rowUpdate}, map[string]any{"rows": 2}),
		"no rows":             packageText(t, nil, nil),
		"unknown op":          packageText(t, []string{strings.Replace(rowUpdate, "update", "delete", 1)}, nil),
		"unknown table":       packageText(t, []string{strings.Replace(rowUpdate, "posts", "users", 1)}, nil),
		"row is no object":    packageText(t, []string{`"text"`}, nil),
		"the same key twice":  packageText(t, []string{rowUpdate, strings.Replace(rowTrash, "220", "219", 1)}, nil),
		"an empty line":       packageText(t, []string{rowUpdate, ""}, map[string]any{"rows": 1}),
		"head without sha256": packageText(t, []string{rowUpdate}, map[string]any{"sha256": ""}),
	}
	for name, text := range cases {
		_, err := LoadPackage(writePackage(t, text))
		var refused *ContentError
		if !errors.As(err, &refused) || refused.Reason != "package_invalid" {
			t.Errorf("%s: err = %v", name, err)
		}
	}
	if _, err := LoadPackage(filepath.Join(t.TempDir(), "missing.jsonl")); err == nil || errors.As(err, new(*ContentError)) {
		t.Errorf("a missing file is no verdict on a package: %v", err)
	}
}

func TestContentErrorNamesKeysNeverValues(t *testing.T) {
	e := contentFailure(&agentapi.ContentFailure{Code: "conflict", Message: "Auf dem Ziel seit dem Pull geändert",
		Keys: []agentapi.ContentKey{{Table: "posts", Key: "219"}, {Table: "postmeta", Key: "219\x00_elementor_data"}, {Table: "options", Key: "blog\x1bname"}}})
	msg := e.Error()
	for _, want := range []string{"Auf dem Ziel seit dem Pull geändert", `posts "219"`, `postmeta "219 _elementor_data"`, "(conflict)"} {
		if !strings.Contains(msg, want) {
			t.Errorf("message lacks %q: %s", want, msg)
		}
	}
	if strings.ContainsAny(msg, "\x00\x1b") {
		t.Errorf("message carries control characters: %q", msg)
	}
	many := &ContentError{Reason: "blocked_row", Message: "gesperrt"}
	for i := 0; i < 9; i++ {
		many.Keys = append(many.Keys, agentapi.ContentKey{Table: "posts", Key: "1"})
	}
	if !strings.Contains(many.Error(), "und 4 weitere") {
		t.Errorf("message = %s", many.Error())
	}
	paths := &ContentError{Reason: "upload_missing", Message: "fehlt", Paths: []string{"2026/10/a.jpg"}}
	if !strings.Contains(paths.Error(), `"wp-content/uploads/2026/10/a.jpg"`) {
		t.Errorf("message = %s", paths.Error())
	}
}

func TestContentErrorFromTheAgentsAnswer(t *testing.T) {
	api := &agentapi.APIError{Status: 409, Code: "wpsync_content_changed_since_push", Message: "seit dem Push geändert", Keys: []agentapi.ContentKey{{Table: "posts", Key: "219"}}}
	err := contentError(api)
	var refused *ContentError
	var back *agentapi.APIError
	if !errors.As(err, &refused) || refused.Reason != "changed_since_push" || len(refused.Keys) != 1 || !errors.As(err, &back) || back != api {
		t.Fatalf("err = %#v", err)
	}
	for _, code := range []string{"wpsync_push_window", "wpsync_content_offset", "wpsync_content_hash", "wpsync_content_stage"} {
		other := &agentapi.APIError{Status: 409, Code: code}
		if got := contentError(other); got != error(other) {
			t.Errorf("%s became %v", code, got)
		}
	}
	if contentError(nil) != nil {
		t.Error("nil stays nil")
	}
}
