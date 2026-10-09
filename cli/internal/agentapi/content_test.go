package agentapi

import (
	"encoding/json"
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
