package pull

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

func TestDownloadTablesBundlesSmallChunksLargeAndResumes(t *testing.T) {
	var requests []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		route := r.URL.Query().Get("rest_route")
		requests = append(requests, route)
		var body map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		switch route {
		case "/wpsync/v1/db-bundle":
			w.Write([]byte("T wp_small\t1\t6\nSMALL;\nE\n"))
		case "/wpsync/v1/db":
			w.Header().Set("X-Wpsync-Mode", "keyset")
			if body["after"] == nil {
				w.Header().Set("X-Wpsync-Rows", "2")
				w.Header().Set("X-Wpsync-Next", base64.StdEncoding.EncodeToString([]byte("2")))
				w.Write([]byte("PART1;"))
				return
			}
			if body["after"] != "2" {
				t.Errorf("after = %v, want 2", body["after"])
			}
			w.Header().Set("X-Wpsync-Rows", "1")
			w.Write([]byte("PART2;"))
		}
	}))
	defer srv.Close()

	c := agentapi.New(srv.URL, "0123456789abcdef", "secret", 1000)
	dir := t.TempDir()
	tables := []agentapi.Table{
		{Name: "wp_small", Checksum: sp("1"), Rows: 1, Bytes: 100},
		{Name: "wp_large", Checksum: sp("2"), Rows: 5000, Bytes: 100},
	}
	opts := DBOptions{RowsPerChunk: 2, BundleBytes: 1 << 20}

	if err := DownloadTables(c, dir, tables, opts); err != nil {
		t.Fatal(err)
	}
	large, _ := os.ReadFile(filepath.Join(dir, "wp_large.sql"))
	if string(large) != "PART1;PART2;" {
		t.Fatalf("large = %q", large)
	}
	if strings.Join(requests, ",") != "/wpsync/v1/db-bundle,/wpsync/v1/db,/wpsync/v1/db" {
		t.Fatalf("requests = %v", requests)
	}

	requests = nil
	if err := DownloadTables(c, dir, tables, opts); err != nil {
		t.Fatal(err)
	}
	if len(requests) != 0 {
		t.Fatalf("resume must skip finished tables, got %v", requests)
	}

	r, closeAll, err := ImportReader(dir, tables)
	if err != nil {
		t.Fatal(err)
	}
	defer closeAll()
	all, _ := io.ReadAll(r)
	s := string(all)
	if !strings.HasPrefix(s, "SET NAMES utf8mb4;") || !strings.Contains(s, "SMALL;") || !strings.Contains(s, "PART1;PART2;") ||
		!strings.Contains(s, "sql_mode='NO_AUTO_VALUE_ON_ZERO'") {
		t.Fatalf("import = %q", s)
	}
}

func TestDownloadTablesSendsScope(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Tables []string       `json:"tables"`
			Scope  agentapi.Scope `json:"scope"`
		}
		json.NewDecoder(r.Body).Decode(&body)
		if len(body.Scope.ExcludePostTypes) != 1 || body.Scope.ExcludePostTypes[0] != "revision" {
			t.Errorf("scope = %+v", body.Scope)
		}
		for _, name := range body.Tables {
			fmt.Fprintf(w, "T %s\t0\t2\nX;\n", name)
		}
		w.Write([]byte("E\n"))
	}))
	defer srv.Close()
	c := agentapi.New(srv.URL, "0123456789abcdef", "secret", 1000)
	tables := []agentapi.Table{{Name: "wp_posts", Checksum: sp("1"), Rows: 1, Bytes: 10}}
	opts := DBOptions{RowsPerChunk: 2000, BundleBytes: 1 << 20, Scope: agentapi.Scope{ExcludePostTypes: []string{"revision"}}}
	if err := DownloadTables(c, t.TempDir(), tables, opts); err != nil {
		t.Fatal(err)
	}
}

func TestMarkerWithoutChecksumNeverMatches(t *testing.T) {
	dir := t.TempDir()
	tb := agentapi.Table{Name: "wp_x"}
	writeMarker(dir, tb)
	if markerMatches(dir, tb) {
		t.Fatal("tables without checksum must always be downloaded")
	}
}

func TestStructureTablesAreBundledAndResumable(t *testing.T) {
	var bundles int
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("rest_route") != "/wpsync/v1/db-bundle" {
			t.Errorf("structure tables must not be chunked: %s", r.URL.Query().Get("rest_route"))
		}
		bundles++
		var body struct {
			Tables []string `json:"tables"`
		}
		json.NewDecoder(r.Body).Decode(&body)
		for _, name := range body.Tables {
			sql := "CREATE " + name + ";"
			fmt.Fprintf(w, "T %s\t0\t%d\n%s\n", name, len(sql), sql)
		}
		w.Write([]byte("E\n"))
	}))
	defer srv.Close()
	c := agentapi.New(srv.URL, "0123456789abcdef", "secret", 1000)
	dir := t.TempDir()
	tables := []agentapi.Table{
		{Name: "wp_huge_log", Rows: 9_000_000, Bytes: 5 << 30, Mode: profile.ModeStructure},
		{Name: "wp_other_log", Rows: 1_000_000, Bytes: 1 << 30, Mode: profile.ModeStructure},
	}
	opts := DBOptions{RowsPerChunk: 2000, BundleBytes: 8 << 20}
	if err := DownloadTables(c, dir, tables, opts); err != nil {
		t.Fatal(err)
	}
	if err := DownloadTables(c, dir, tables, opts); err != nil {
		t.Fatal(err)
	}
	if bundles != 1 {
		t.Fatalf("bundles = %d, want 1 (both in one bundle, second run resumes)", bundles)
	}
}
