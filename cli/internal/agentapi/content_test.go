package agentapi

import (
	"bufio"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// manifestServer answers /content/manifest with the given pages and records the cursors it got.
func manifestServer(t *testing.T, pages []string) (*Client, *[]string) {
	t.Helper()
	var cursors []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("rest_route") != "/wpsync/v1/content/manifest" {
			t.Errorf("route: %s", r.URL.RawQuery)
		}
		var body struct {
			Cursor json.RawMessage `json:"cursor"`
			Scope  Scope           `json:"scope"`
		}
		data, _ := io.ReadAll(r.Body)
		if err := json.Unmarshal(data, &body); err != nil {
			t.Error(err)
		}
		cursors = append(cursors, string(body.Cursor))
		if len(cursors) > len(pages) {
			t.Fatalf("more requests than pages")
		}
		io.WriteString(w, pages[len(cursors)-1])
	}))
	t.Cleanup(srv.Close)
	c := New(srv.URL, "0123456789abcdef", strings.Repeat("ab", 32), 1000)
	c.Sleep = func(time.Duration) {}
	return c, &cursors
}

const manifestHead = `{"head":{"canon_version":1,"list_version":1,"variants":["plain","esc1","esc2"],"origins":{"home":"https://kunde.de","siteurl":"https://kunde.de"},"id_max":{"posts":1204,"terms":50,"term_taxonomy":50},"engines":{"posts":"InnoDB"},"tables":["posts","options"],"pseudonym":{"rules_version":2,"patterns":[]},"prefix":"wp_","charset":"utf8mb4","pushable":true,"lists":{"version":1}}}`

func TestContentManifestFollowsThePages(t *testing.T) {
	c, cursors := manifestServer(t, []string{
		manifestHead + "\n" + `{"t":"posts","k":"1","h":"aa"}` + "\n" + `{"next":{"t":0,"a":"1"}}` + "\n",
		`{"t":"options","k":"blogname","h":null,"why":"unnormalizable"}` + "\n" + `{"next":null}` + "\n",
	})
	var out strings.Builder
	head, rows, err := c.ContentManifest(Scope{}, &out)
	if err != nil {
		t.Fatal(err)
	}
	if rows != 2 || head.CanonVersion != 1 || head.Origins.Home != "https://kunde.de" || head.IDMax["posts"] != 1204 || !head.Pushable {
		t.Fatalf("rows=%d head=%+v", rows, head)
	}
	lines := strings.Split(strings.TrimSpace(out.String()), "\n")
	if len(lines) != 3 || !strings.HasPrefix(lines[0], `{"head":`) || strings.Contains(out.String(), `"next"`) {
		t.Fatalf("manifest file: %q", out.String())
	}
	if (*cursors)[0] != "null" || (*cursors)[1] != `{"t":0,"a":"1"}` {
		t.Fatalf("cursors: %v", *cursors)
	}
}

func TestContentManifestRefusesBrokenPages(t *testing.T) {
	cases := map[string][]string{
		"no head":           {`{"t":"posts","k":"1","h":"aa"}` + "\n" + `{"next":null}` + "\n"},
		"truncated":         {manifestHead + "\n" + `{"t":"posts","k":"1","h":"aa"}` + "\n"},
		"agent error":       {manifestHead + "\n" + `{"error":"read_failed"}` + "\n"},
		"foreign line":      {manifestHead + "\n" + "<br />Warning: something\n" + `{"next":null}` + "\n"},
		"cursor stands":     {manifestHead + "\n" + `{"next":{"t":0,"a":"1"}}` + "\n", `{"next":{"t":0,"a":"1"}}` + "\n"},
		"head on next page": {manifestHead + "\n" + `{"next":{"t":0,"a":"1"}}` + "\n", manifestHead + "\n" + `{"next":null}` + "\n"},
	}
	for name, pages := range cases {
		c, _ := manifestServer(t, pages)
		if _, _, err := c.ContentManifest(Scope{}, io.Discard); err == nil {
			t.Errorf("%s: expected an error", name)
		}
	}
}

// A line of the manifest is bounded: the head may be large (it carries the lists), a row is a
// table, a key and a fingerprint. Whatever is longer never piles up in memory.
func TestContentManifestRefusesOverlongLines(t *testing.T) {
	defer func(head, line int) { maxManifestHead, maxManifestLine = head, line }(maxManifestHead, maxManifestLine)
	maxManifestHead, maxManifestLine = len(manifestHead)+1, 64
	row := `{"t":"posts","k":"1","h":"aa"}` + "\n"
	c, _ := manifestServer(t, []string{manifestHead + "\n" + row + `{"next":null}` + "\n"})
	if _, rows, err := c.ContentManifest(Scope{}, io.Discard); err != nil || rows != 1 {
		t.Fatalf("within the limits: rows=%d err=%v", rows, err)
	}
	long := `{"t":"posts","k":"` + strings.Repeat("9", 64) + `","h":"aa"}` + "\n"
	cases := map[string][]string{
		"row":             {manifestHead + "\n" + row + long + `{"next":null}` + "\n"},
		"row, no newline": {manifestHead + "\n" + row + strings.Repeat("x", 4096)},
		"row on page two": {manifestHead + "\n" + `{"next":{"t":0,"a":"1"}}` + "\n", long + `{"next":null}` + "\n"},
		"next":            {manifestHead + "\n" + `{"next":"` + strings.Repeat("9", 64) + `"}` + "\n"},
		"head":            {strings.Replace(manifestHead, `"prefix":"wp_"`, `"prefix":"wp_","x":"`+strings.Repeat("A", 64)+`"`, 1) + "\n" + `{"next":null}` + "\n"},
		// Only the first line of the first page gets the room of a head.
		"second line as long as a head": {manifestHead + "\n" + `{"t":"posts","k":"` + strings.Repeat("9", len(manifestHead)-40) + `","h":"aa"}` + "\n" + `{"next":null}` + "\n"},
	}
	for name, pages := range cases {
		c, _ := manifestServer(t, pages)
		var out strings.Builder
		_, _, err := c.ContentManifest(Scope{}, &out)
		if !errors.Is(err, ErrManifest) || !errors.Is(err, ErrLineTooLong) {
			t.Errorf("%s: err = %v", name, err)
		}
		if strings.Contains(out.String(), "999999") {
			t.Errorf("%s: the overlong line was written", name)
		}
	}
}

func TestReadLine(t *testing.T) {
	br := bufio.NewReaderSize(strings.NewReader("ab\n"+strings.Repeat("x", 100)+"\nlast"), 16)
	if line, err := ReadLine(br, 3); string(line) != "ab\n" || err != nil {
		t.Fatalf("%q %v", line, err)
	}
	if line, err := ReadLine(br, 101); string(line) != strings.Repeat("x", 100)+"\n" || err != nil {
		t.Fatalf("a line longer than the buffer: %d bytes, %v", len(line), err)
	}
	if line, err := ReadLine(br, 10); string(line) != "last" || err != io.EOF {
		t.Fatalf("%q %v", line, err)
	}
	br = bufio.NewReaderSize(strings.NewReader(strings.Repeat("x", 100)+"\n"), 16)
	if line, err := ReadLine(br, 100); line != nil || !errors.Is(err, ErrLineTooLong) {
		t.Fatalf("%d bytes, %v", len(line), err)
	}
}

func TestAtLeast(t *testing.T) {
	for _, c := range []struct {
		have, want string
		ok         bool
	}{{"0.7.0", "0.7.0", true}, {"0.10.0", "0.7.0", true}, {"0.6.9", "0.7.0", false}, {"0.7.0-dev", "0.7.0", true}, {"test", "0.7.0", false}, {"", "0.7.0", false}} {
		if AtLeast(c.have, c.want) != c.ok {
			t.Errorf("AtLeast(%q, %q) != %v", c.have, c.want, c.ok)
		}
	}
}
