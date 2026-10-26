package pull

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/baseline"
)

// excludeAgent lists files in the delta as given (an old agent does not filter) and streams every
// requested one. It records the requested paths.
func excludeAgent(t *testing.T, version string, files map[string]string) (*httptest.Server, func() []string) {
	t.Helper()
	var mu sync.Mutex
	var requested []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},"tables":[{"name":"wp_options","class":"config","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			var list []string
			for p, body := range files {
				list = append(list, fmt.Sprintf(`{"path":%q,"size":%d,"mtime":%d}`, p, len(body), fileMTime))
			}
			fmt.Fprintf(w, `{"env":{"php_version":"8.3.35","wp_version":"6.8.1","table_prefix":"wp_","home":"https://kunde.de","siteurl":"https://kunde.de","agent_version":%q,"anon":"1.abcd1234"},
"tables":[{"name":"wp_options","checksum":"c1","rows":1,"bytes":40}],"files":[%s],"skipped":[],"next":null}`, version, strings.Join(list, ","))
		case "/wpsync/v1/files":
			var body struct {
				Paths []string `json:"paths"`
			}
			json.NewDecoder(r.Body).Decode(&body)
			mu.Lock()
			requested = append(requested, body.Paths...)
			mu.Unlock()
			for _, p := range body.Paths {
				fmt.Fprintf(w, "F %s\t%d\t%d\n%s\n", p, len(files[p]), fileMTime, files[p])
			}
			fmt.Fprint(w, "E\n")
		case "/wpsync/v1/db-bundle":
			fmt.Fprintf(w, "T wp_options\t1\t%d\n%s\nE\n", len(tableSQL), tableSQL)
		default:
			http.Error(w, "unexpected", http.StatusNotFound)
		}
	}))
	return srv, func() []string {
		mu.Lock()
		defer mu.Unlock()
		out := append([]string(nil), requested...)
		slices.Sort(out)
		return out
	}
}

const oldAgentHint = "Der Agent auf der Site ist älter als 0.10.0"

// AC-262, AC-264: an agent before 0.10.0 lists excluded files; the CLI neither requests them nor
// keeps them in the baseline, and says once that the agent still walks those folders.
func TestRunExcludePathsWithOldAgent(t *testing.T) {
	for _, version := range []string{"0.9.1", "0.10.0"} {
		t.Run(version, func(t *testing.T) {
			srv, requested := excludeAgent(t, version, map[string]string{
				"wp-content/uploads/videos/a.mp4":         "video",
				"wp-content/uploads/videosammlung/b.jpg":  "jpg",
				"wp-content/uploads/2024/a.mp4":           "video",
				"wp-content/uploads/2024/a.mp4.txt":       "txt",
				"wp-content/uploads/videos/sub/keep.webm": "webm",
			})
			defer srv.Close()
			o := pullOptions(t, srv.URL, newFakeDriver(false))
			o.Site.Profile.Paths.Exclude = []string{"*.MP4", "uploads/videos/"}

			if err := Run(o); err != nil {
				t.Fatalf("Run: %v\n%s", err, o.Out)
			}
			want := []string{"wp-content/uploads/2024/a.mp4.txt", "wp-content/uploads/videosammlung/b.jpg"}
			if got := requested(); !reflect.DeepEqual(got, want) {
				t.Errorf("requested = %q, want %q", got, want)
			}
			base, err := baseline.Load(filepath.Join(o.SitesRoot, "kunde"))
			if err != nil {
				t.Fatal(err)
			}
			if _, ok := base.Files["wp-content/uploads/videos/a.mp4"]; ok {
				t.Error("excluded file in the baseline")
			}
			hints := strings.Count(o.Out.(fmt.Stringer).String(), oldAgentHint)
			if wantHints := map[string]int{"0.9.1": 1, "0.10.0": 0}[version]; hints != wantHints {
				t.Errorf("hint shown %d times, want %d:\n%s", hints, wantHints, o.Out)
			}
		})
	}
}

// AC-265: a file the baseline knows and an exclude path now covers stays locally and is named.
func TestRunExcludePathsKeepLocalCopies(t *testing.T) {
	const video = "wp-content/uploads/videos/a.mp4"
	srv, _ := excludeAgent(t, "0.10.0", map[string]string{"wp-content/uploads/2024/b.jpg": "jpg"})
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	o.Site.Profile.Paths.Exclude = []string{"uploads/videos/"}
	siteDir := filepath.Join(o.SitesRoot, "kunde")
	local := filepath.Join(siteDir, "public", filepath.FromSlash(video))
	if err := os.MkdirAll(filepath.Dir(local), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(local, []byte("video"), 0o644); err != nil {
		t.Fatal(err)
	}
	old := baseline.New(o.Site.URL)
	old.Files[video] = baseline.FileStamp{Size: 5, MTime: fileMTime}
	if err := baseline.Save(siteDir, old); err != nil {
		t.Fatal(err)
	}
	var res Result
	o.Report = &res

	if err := Run(o); err != nil {
		t.Fatalf("Run: %v\n%s", err, o.Out)
	}
	if _, err := os.Stat(local); err != nil {
		t.Fatalf("excluded local file removed: %v", err)
	}
	if res.FilesDeleted != 0 || !reflect.DeepEqual(res.KeptExcluded, []string{video}) {
		t.Errorf("deleted = %d, kept = %q", res.FilesDeleted, res.KeptExcluded)
	}
	if out := o.Out.(fmt.Stringer).String(); !strings.Contains(out, "Ausgeschlossen, lokal behalten (1): "+video) {
		t.Errorf("kept file not named:\n%s", out)
	}
}

// AC-271: once the exclusion is gone, a missing file is loaded; one that is there with the same
// size and mtime is not loaded again but goes into the baseline.
func TestRunExcludePathRemovedLoadsFilesAgain(t *testing.T) {
	const missing, present = "wp-content/uploads/videos/a.mp4", "wp-content/uploads/videos/b.mp4"
	srv, requested := excludeAgent(t, "0.10.0", map[string]string{missing: "video-a", present: "video-b"})
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	o.Site.Profile.Paths.Exclude = []string{}
	siteDir := filepath.Join(o.SitesRoot, "kunde")
	local := filepath.Join(siteDir, "public", filepath.FromSlash(present))
	if err := os.MkdirAll(filepath.Dir(local), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(local, []byte("video-b"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(local, time.Unix(fileMTime, 0), time.Unix(fileMTime, 0)); err != nil {
		t.Fatal(err)
	}
	// Baseline of a pull with the exclusion: neither video is in it.
	if err := baseline.Save(siteDir, baseline.New(o.Site.URL)); err != nil {
		t.Fatal(err)
	}

	if err := Run(o); err != nil {
		t.Fatalf("Run: %v\n%s", err, o.Out)
	}
	if got := requested(); !reflect.DeepEqual(got, []string{missing}) {
		t.Errorf("requested = %q, want only %s", got, missing)
	}
	base, err := baseline.Load(siteDir)
	if err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{missing, present} {
		if _, ok := base.Files[p]; !ok {
			t.Errorf("%s not in the baseline", p)
		}
	}
}
