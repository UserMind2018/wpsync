package pull

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

// AC-29: Änderungen seit dem letzten Pull, ohne Inhalte zu übertragen.
func TestStatusShowsChangesWithoutTransfer(t *testing.T) {
	var routes []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		route := r.URL.Query().Get("rest_route")
		routes = append(routes, route)
		switch route {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},"tables":[{"name":"wp_posts","essential":true},{"name":"wp_options","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			w.Write([]byte(`{"env":{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"},"tables":[{"name":"wp_posts","checksum":"2"},{"name":"wp_options","checksum":"1"}],
"files":[{"path":"wp-content/themes/a/style.css","size":5,"mtime":10},{"path":"wp-content/new.txt","size":1,"mtime":1}],"skipped":[],"next":null}`))
		}
	}))
	defer srv.Close()

	root := t.TempDir()
	base := baseline.New(srv.URL)
	base.PulledAt = time.Unix(1700000000, 0)
	base.Tables = map[string]string{"wp_posts": "1", "wp_options": "1"}
	base.Modes = map[string]string{"wp_posts": profile.ModeFull, "wp_options": profile.ModeFull}
	base.Files = map[string]baseline.FileStamp{"wp-content/themes/a/style.css": {Size: 5, MTime: 10}, "wp-content/gone.txt": {Size: 1, MTime: 1}}
	if err := baseline.Save(filepath.Join(root, "kunde"), base); err != nil {
		t.Fatal(err)
	}
	prof, _ := profile.New(&agentapi.Infosheet{Tables: []agentapi.TableInfo{{Name: "wp_posts", Essential: true}, {Name: "wp_options", Essential: true}}}, profile.PresetFull)

	var out bytes.Buffer
	err := Status(Options{
		Site:      sites.Site{Name: "kunde", URL: srv.URL, KeyID: "0123456789abcdef", RPS: 1000, Profile: prof},
		Secret:    "secret",
		SitesRoot: root,
		Out:       &out,
	})
	if err != nil {
		t.Fatal(err)
	}
	got := out.String()
	for _, want := range []string{"+ wp-content/new.txt", "- wp-content/gone.txt", "  wp_posts", "2 Requests, keine Inhalte übertragen."} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in\n%s", want, got)
		}
	}
	if strings.Contains(got, "  wp_options") || strings.Contains(got, "style.css") {
		t.Errorf("unchanged entries listed:\n%s", got)
	}
	if want := []string{"/wpsync/v1/infosheet", "/wpsync/v1/delta"}; !reflect.DeepEqual(routes, want) {
		t.Errorf("routes = %v, want %v", routes, want)
	}
}

func TestStatusBeforeFirstPull(t *testing.T) {
	var out bytes.Buffer
	err := Status(Options{Site: sites.Site{Name: "kunde", Profile: &profile.Profile{}}, SitesRoot: t.TempDir(), Out: &out})
	if err != nil || !strings.Contains(out.String(), "Noch kein Pull") {
		t.Fatalf("err = %v, out = %q", err, out.String())
	}
}
