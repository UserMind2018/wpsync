package pull

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

// tableDataServer answers /db-bundle with a frame per requested table and /db with one short
// chunk; every request is counted, so a test can prove nothing was fetched.
func tableDataServer(t *testing.T, requests *int) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		*requests++
		var body struct {
			Tables []string `json:"tables"`
		}
		json.NewDecoder(r.Body).Decode(&body)
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/db-bundle":
			for _, name := range body.Tables {
				fmt.Fprintf(w, "T %s\t1\t6\nPWNED;\n", name)
			}
			w.Write([]byte("E\n"))
		case "/wpsync/v1/db":
			w.Header().Set("X-Wpsync-Mode", "offset")
			w.Header().Set("X-Wpsync-Rows", "0")
			w.Write([]byte("PWNED;"))
		}
	}))
}

// regularFiles lists every file below root; directories alone are no side effect worth reporting.
func regularFiles(t *testing.T, root string) []string {
	t.Helper()
	var files []string
	filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err == nil && !d.IsDir() {
			files = append(files, path)
		}
		return nil
	})
	return files
}

// AC-1, AC-3 (Regression TestD1): ein Name mit ../ verlässt dir nicht, und vorher geht kein Request raus.
func TestDownloadTablesRefusesTraversalBeforeAnyRequest(t *testing.T) {
	for _, name := range []string{"../../../../outside/pwned", "../x"} {
		t.Run(name, func(t *testing.T) {
			var requests int
			srv := tableDataServer(t, &requests)
			defer srv.Close()
			root := t.TempDir()
			outside := filepath.Join(root, "outside")
			if err := os.Mkdir(outside, 0o755); err != nil {
				t.Fatal(err)
			}
			dir := filepath.Join(root, "site", ".wpsync", "db", "tables")
			tables := []agentapi.Table{
				{Name: "wp_ok", Checksum: sp("1"), Rows: 1, Bytes: 10},
				{Name: name, Checksum: sp("1"), Rows: 1, Bytes: 10},
			}

			err := DownloadTables(quickClient(srv.URL), dir, tables, DBOptions{RowsPerChunk: 2000, BundleBytes: 1 << 20})
			if !errors.Is(err, ErrInvalidTableName) {
				t.Fatalf("err = %v, want ErrInvalidTableName", err)
			}
			for _, suffix := range []string{".sql", ".sql.part", ".done"} {
				if _, err := os.Stat(filepath.Join(outside, "pwned"+suffix)); err == nil {
					t.Errorf("outside/pwned%s written", suffix)
				}
			}
			if files := regularFiles(t, root); len(files) != 0 {
				t.Errorf("files written: %v", files)
			}
			if requests != 0 {
				t.Errorf("%d requests before the abort", requests)
			}
		})
	}
}

// AC-2, AC-3: derselbe Schutz für eine grosse Tabelle, die fetchChunks bekäme.
func TestDownloadTablesRefusesTraversalOnChunkPath(t *testing.T) {
	var requests int
	srv := tableDataServer(t, &requests)
	defer srv.Close()
	root := t.TempDir()
	dir := filepath.Join(root, "site", ".wpsync", "db", "tables")
	tables := []agentapi.Table{
		{Name: "wp_ok", Checksum: sp("1"), Rows: 1, Bytes: 10},
		{Name: "../../../../outside/pwned", Checksum: sp("1"), Rows: 5000, Bytes: 10},
	}

	err := DownloadTables(quickClient(srv.URL), dir, tables, DBOptions{RowsPerChunk: 2, BundleBytes: 1 << 20})
	if !errors.Is(err, ErrInvalidTableName) {
		t.Fatalf("err = %v, want ErrInvalidTableName", err)
	}
	if files := regularFiles(t, root); len(files) != 0 {
		t.Errorf("files written: %v", files)
	}
	if requests != 0 {
		t.Errorf("%d requests before the abort", requests)
	}
}

// AC-4: eine fremde x.sql mit passendem Marker wird weder als fertig erkannt noch importiert.
func TestTableNameCannotReadFilesOutsideDir(t *testing.T) {
	var requests int
	srv := tableDataServer(t, &requests)
	defer srv.Close()
	root := t.TempDir()
	dir := filepath.Join(root, "tables")
	outside := filepath.Join(root, "outside")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Mkdir(outside, 0o755); err != nil {
		t.Fatal(err)
	}
	const secret = "INSERT INTO local_secret VALUES ('nicht-von-der-site');"
	os.WriteFile(filepath.Join(outside, "x.sql"), []byte(secret), 0o644)
	os.WriteFile(filepath.Join(outside, "x.done"), []byte("full:1"), 0o644)
	tables := []agentapi.Table{{Name: "../outside/x", Checksum: sp("1"), Mode: profile.ModeFull, Rows: 1, Bytes: 10}}

	err := DownloadTables(quickClient(srv.URL), dir, tables, DBOptions{RowsPerChunk: 2000, BundleBytes: 1 << 20})
	if !errors.Is(err, ErrInvalidTableName) {
		t.Fatalf("DownloadTables err = %v, want ErrInvalidTableName", err)
	}
	if requests != 0 {
		t.Errorf("%d requests", requests)
	}

	r, closeAll, err := ImportReader(dir, tables)
	if !errors.Is(err, ErrInvalidTableName) {
		t.Fatalf("ImportReader err = %v, want ErrInvalidTableName", err)
	}
	if r != nil {
		defer closeAll()
		if all, _ := io.ReadAll(r); strings.Contains(string(all), secret) {
			t.Fatal("foreign file reached the import")
		}
	}
	if got, _ := os.ReadFile(filepath.Join(outside, "x.sql")); string(got) != secret {
		t.Errorf("outside/x.sql changed: %q", got)
	}
}

// AC-5: die Namensregel.
func TestValidTableName(t *testing.T) {
	accepted := []string{
		"wp_posts", "e2e_options", "wp_2_posts", "wp_wfBlocks7",
		"djTui5D_woocommerce_downloadable_product_permissions", "a", "wp_$x", strings.Repeat("a", 64),
	}
	rejected := []string{
		"", strings.Repeat("a", 65), ".", "..", "../x", "a/b", `a\b`, "/etc/x", "wp_posts.sql", ".hidden",
		"a b", "wp_posts ", "a`b", "a\tb", "a\nb", "a\x00b", "a\x1b[2Jb", "a\x7fb", "wp-posts", "-rf",
		"wp_täbelle", "wp\uff3fposts", "wp_posts\n",
	}
	for _, name := range accepted {
		if !validTableName(name) {
			t.Errorf("%q rejected", name)
		}
	}
	for _, name := range rejected {
		if validTableName(name) {
			t.Errorf("%q accepted", name)
		}
	}

	dir := t.TempDir()
	if got, err := tablePath(dir, "wp_posts", sqlSuffix); err != nil || got != filepath.Join(dir, "wp_posts.sql") {
		t.Errorf("tablePath(wp_posts) = %q, %v", got, err)
	}
	for _, name := range []string{"../x", "..", "a/b", "/etc/x", "", "a\x00b"} {
		if got, err := tablePath(dir, name, doneSuffix); !errors.Is(err, ErrInvalidTableName) || got != "" {
			t.Errorf("tablePath(%q) = %q, %v", name, got, err)
		}
	}
}

// AC-9: ein Frame mit einem nicht angefragten Namen wird nirgends abgelegt.
func TestDownloadTablesRejectsForeignFrameName(t *testing.T) {
	for _, foreign := range []string{"../x", "wp_other"} {
		t.Run(foreign, func(t *testing.T) {
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				fmt.Fprintf(w, "T %s\t1\t6\nPWNED;\nE\n", foreign)
			}))
			defer srv.Close()
			root := t.TempDir()
			dir := filepath.Join(root, "site", "tables")
			tables := []agentapi.Table{{Name: "wp_posts", Checksum: sp("1"), Rows: 1, Bytes: 10}}

			if err := DownloadTables(quickClient(srv.URL), dir, tables, DBOptions{RowsPerChunk: 2000, BundleBytes: 1 << 20}); err == nil {
				t.Fatal("foreign frame accepted")
			}
			if files := regularFiles(t, root); len(files) != 0 {
				t.Errorf("files written: %v", files)
			}
		})
	}
}

// AC-6: /delta mit ungültigen Namen bricht pull und status ab, bevor Tabellendaten angefragt werden.
func TestPrepareRefusesInvalidTableNames(t *testing.T) {
	cases := map[string]struct {
		tables  string
		want    []string
		notWant []string
	}{
		"two names": {
			tables: `[{"name":"wp_users","checksum":"1","anonymized":true},{"name":"../x","checksum":"2"},{"name":"a\u001b[2Jb\u007f","checksum":"3"}]`,
			want:   []string{`"../x"`, `a\x1b[2Jb\x7f`},
		},
		"more than ten": {
			tables:  manyBadTables(12),
			want:    []string{`"bad-01"`, `"bad-10"`, "(12 insgesamt)"},
			notWant: []string{"bad-11", "bad-12"},
		},
	}
	for name, tc := range cases {
		for _, ask := range []bool{true, false} {
			t.Run(fmt.Sprintf("%s/ask=%v", name, ask), func(t *testing.T) {
				var data int
				srv := anonServer(t, `{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"}`, tc.tables, nil, &data)
				defer srv.Close()
				o := Options{Site: sites.Site{Name: "kunde", URL: srv.URL, Profile: anonProfile(t)}, Out: &bytes.Buffer{}}

				_, err := prepare(quickClient(srv.URL), &o, ask)
				if !errors.Is(err, ErrInvalidTableName) {
					t.Fatalf("err = %v, want ErrInvalidTableName", err)
				}
				if data != 0 {
					t.Errorf("%d requests besides infosheet and delta", data)
				}
				msg := err.Error()
				for _, w := range append(tc.want, "tables.overrides", "skip") {
					if !strings.Contains(msg, w) {
						t.Errorf("message misses %q:\n%s", w, msg)
					}
				}
				for _, w := range tc.notWant {
					if strings.Contains(msg, w) {
						t.Errorf("message contains %q:\n%s", w, msg)
					}
				}
				if n := strings.Count(msg, `"bad-`); n > maxNamesInError {
					t.Errorf("%d names shown", n)
				}
				for _, b := range []byte(msg) {
					if (b < 0x20 && b != '\n') || b == 0x7f {
						t.Fatalf("control byte %#x in message %q", b, msg)
					}
				}
				if !strings.HasPrefix(msg, "die Site liefert Tabellennamen") {
					t.Errorf("message must start in German: %q", msg)
				}
			})
		}
	}
}

func manyBadTables(n int) string {
	parts := make([]string, n)
	for i := range parts {
		parts[i] = fmt.Sprintf(`{"name":"bad-%02d","checksum":"1"}`, i+1)
	}
	return "[" + strings.Join(parts, ",") + "]"
}

// AC-6 (status): dieselbe Ablehnung über den öffentlichen Einstieg.
func TestStatusRefusesInvalidTableNames(t *testing.T) {
	var data int
	srv := anonServer(t, `{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"}`, `[{"name":"../x","checksum":"1"}]`, nil, &data)
	defer srv.Close()
	root := t.TempDir()
	base := baseline.New(srv.URL)
	base.Tables = map[string]string{"wp_users": "1"}
	if err := baseline.Save(filepath.Join(root, "kunde"), base); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	err := Status(Options{
		Site:      sites.Site{Name: "kunde", URL: srv.URL, KeyID: "0123456789abcdef", RPS: 1000, Profile: anonProfile(t)},
		Secret:    "secret",
		SitesRoot: root,
		Out:       &out,
	})
	if !errors.Is(err, ErrInvalidTableName) {
		t.Fatalf("err = %v, want ErrInvalidTableName", err)
	}
	if data != 0 {
		t.Errorf("%d requests besides infosheet and delta", data)
	}
}

// AC-7: ein abgebrochener pull ruft kein ddev auf und legt unter SitesRoot nichts an.
// Ein ddev-Skript im PATH protokolliert jeden Aufruf; deshalb kein t.Parallel.
func TestRunWithInvalidTableNameLeavesNoTrace(t *testing.T) {
	bin := t.TempDir()
	calls := filepath.Join(t.TempDir(), "ddev-calls")
	script := "#!/bin/sh\necho \"$@\" >> '" + calls + "'\n"
	if err := os.WriteFile(filepath.Join(bin, "ddev"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin)

	var data int
	srv := anonServer(t, `{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"}`,
		`[{"name":"wp_users","checksum":"1","anonymized":true},{"name":"../../x","checksum":"2"}]`, nil, &data)
	defer srv.Close()
	root := t.TempDir()
	err := Run(Options{
		Site:      sites.Site{Name: "kunde", URL: srv.URL, KeyID: "0123456789abcdef", RPS: 1000, Profile: anonProfile(t)},
		Secret:    "secret",
		SitesRoot: root,
		Yes:       true,
		Out:       &bytes.Buffer{},
	})
	if !errors.Is(err, ErrInvalidTableName) {
		t.Fatalf("err = %v, want ErrInvalidTableName", err)
	}
	if got, err := os.ReadFile(calls); err == nil {
		t.Errorf("ddev called:\n%s", got)
	}
	if entries, _ := os.ReadDir(root); len(entries) != 0 {
		t.Errorf("SitesRoot not empty: %v", entries)
	}
	if _, err := os.Stat(filepath.Join(root, "kunde", ".wpsync", "baseline.json")); err == nil {
		t.Error("baseline.json written")
	}
	if data != 0 {
		t.Errorf("%d requests besides infosheet and delta", data)
	}
}

// AC-8: steht die Tabelle auf skip, geht sie in den Scope, und ein /delta ohne sie läuft durch.
func TestSkipOverrideIsTheWayOut(t *testing.T) {
	const bad = "wp-legacy"
	var body map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},
"tables":[{"name":"wp_posts","class":"content","essential":true},{"name":"` + bad + `","class":"content"}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			json.NewDecoder(r.Body).Decode(&body)
			w.Write([]byte(`{"env":{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"},"tables":[{"name":"wp_posts","checksum":"1"}],"files":[],"skipped":[],"next":null}`))
		default:
			t.Errorf("unexpected request %s", r.URL.Query().Get("rest_route"))
		}
	}))
	defer srv.Close()
	prof, err := profile.New(&agentapi.Infosheet{Tables: []agentapi.TableInfo{
		{Name: "wp_posts", Class: "content", Essential: true}, {Name: bad, Class: "content"},
	}}, profile.PresetFull)
	if err != nil {
		t.Fatal(err)
	}
	prof.Tables.Overrides = map[string]string{bad: profile.ModeSkip}
	o := Options{Site: sites.Site{Name: "kunde", URL: srv.URL, Profile: prof}, Out: &bytes.Buffer{}}

	if _, err := prepare(quickClient(srv.URL), &o, true); err != nil {
		t.Fatal(err)
	}
	scope, _ := body["scope"].(map[string]any)
	tables, _ := scope["tables"].(map[string]any)
	if tables[bad] != profile.ModeSkip {
		t.Errorf("scope = %v", scope)
	}
}
