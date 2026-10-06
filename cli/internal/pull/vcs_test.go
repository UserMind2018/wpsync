package pull

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/usermind/wpsync/internal/baseline"
)

const vcsGitfile = "wp-content/plugins/a/.git"

// vcsAgent is agentServer with extra paths in the delta and an extra frame in the file stream.
// It records every path the CLI requests.
func vcsAgent(t *testing.T, deltaExtra []string, streamExtra string) (*httptest.Server, func() []string) {
	t.Helper()
	var mu sync.Mutex
	var requested []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},"tables":[{"name":"wp_options","class":"config","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			files := fmt.Sprintf(`{"path":"wp-content/plugins/a/a.php","size":%d,"mtime":%d}`, len(fileBody), fileMTime)
			for _, p := range deltaExtra {
				files += fmt.Sprintf(`,{"path":%q,"size":12,"mtime":%d}`, p, fileMTime)
			}
			fmt.Fprintf(w, `{"env":{"php_version":"8.3.35","wp_version":"6.8.1","table_prefix":"wp_","home":"https://kunde.de","siteurl":"https://kunde.de","agent_version":"0.3.1","anon":"1.abcd1234"},
"tables":[{"name":"wp_options","checksum":"c1","rows":1,"bytes":40}],"files":[%s],"skipped":[],"next":null}`, files)
		case "/wpsync/v1/files":
			var body struct {
				Paths []string `json:"paths"`
			}
			json.NewDecoder(r.Body).Decode(&body)
			mu.Lock()
			requested = append(requested, body.Paths...)
			mu.Unlock()
			fmt.Fprint(w, streamExtra)
			fmt.Fprintf(w, "F wp-content/plugins/a/a.php\t%d\t%d\n%s\nE\n", len(fileBody), fileMTime, fileBody)
		case "/wpsync/v1/db-bundle":
			fmt.Fprintf(w, "T wp_options\t1\t%d\n%s\nE\n", len(tableSQL), tableSQL)
		default:
			http.Error(w, "unexpected", http.StatusNotFound)
		}
	}))
	return srv, func() []string {
		mu.Lock()
		defer mu.Unlock()
		return append([]string(nil), requested...)
	}
}

// W11: VCS-Pfade im Delta werden nicht angefordert, nicht gezählt, nicht geschrieben und stehen
// nicht in der Baseline; der Pull läuft durch und nennt die Zahl.
func TestRunSkipsVCSPathsFromDelta(t *testing.T) {
	srv, requested := vcsAgent(t, []string{vcsGitfile, "wp-content/x/.SVN/entries", "wp-content/plugins/a/.gitignore"}, "")
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	var res Result
	o.Report = &res

	if err := Run(o); err != nil {
		t.Fatalf("Run: %v\n%s", err, o.Out)
	}
	for _, p := range requested() {
		if isVCSPath(p) {
			t.Errorf("requested VCS path %s", p)
		}
	}
	docroot := filepath.Join(o.SitesRoot, "kunde", "public")
	if _, err := os.Stat(filepath.Join(docroot, filepath.FromSlash(vcsGitfile))); !os.IsNotExist(err) {
		t.Errorf("gitfile in docroot: %v", err)
	}
	base, err := baseline.Load(filepath.Join(o.SitesRoot, "kunde"))
	if err != nil {
		t.Fatal(err)
	}
	for p := range base.Files {
		if isVCSPath(p) {
			t.Errorf("baseline lists VCS path %s", p)
		}
	}
	if _, ok := base.Files["wp-content/plugins/a/.gitignore"]; !ok {
		t.Error(".gitignore is no VCS path and belongs in the baseline")
	}
	// a.php und .gitignore – die beiden VCS-Pfade zählen nicht mit
	if res.FilesChanged != 2 {
		t.Errorf("files changed = %d, want 2", res.FilesChanged)
	}
	if out := o.Out.(fmt.Stringer).String(); !strings.Contains(out, "2 VCS-Pfade der Quelle übersprungen") {
		t.Errorf("hint missing:\n%s", out)
	}
}

// Liefert der Agent ungefragt einen VCS-Pfad im Datei-Stream, bricht der Pull ab und schreibt nichts.
func TestRunRejectsVCSPathInFileStream(t *testing.T) {
	const bad = "wp-content/plugins/a/.git/config"
	body := "[core]\n\tfsmonitor = x\n"
	srv, _ := vcsAgent(t, nil, fmt.Sprintf("F %s\t%d\t%d\n%s\n", bad, len(body), fileMTime, body))
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))

	err := Run(o)
	if err == nil || !strings.Contains(err.Error(), ".git") {
		t.Fatalf("err = %v, want refusal of the VCS path", err)
	}
	if _, err := os.Stat(filepath.Join(o.SitesRoot, "kunde", "public", filepath.FromSlash(bad))); !os.IsNotExist(err) {
		t.Fatalf("VCS file written: %v", err)
	}
}

// Ein lokaler Gitfile bleibt, auch wenn eine alte Baseline ihn kennt und die Quelle ihn nicht mehr liefert.
func TestRunKeepsLocalVCSPathFromOldBaseline(t *testing.T) {
	srv, _ := vcsAgent(t, nil, "")
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	siteDir := filepath.Join(o.SitesRoot, "kunde")
	gitfile := filepath.Join(siteDir, "public", filepath.FromSlash(vcsGitfile))
	if err := os.MkdirAll(filepath.Dir(gitfile), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(gitfile, []byte("gitdir: ../x"), 0o644); err != nil {
		t.Fatal(err)
	}
	old := baseline.New(o.Site.URL)
	old.Files[vcsGitfile] = baseline.FileStamp{Size: 12, MTime: fileMTime}
	if err := baseline.Save(siteDir, old); err != nil {
		t.Fatal(err)
	}
	var res Result
	o.Report = &res

	if err := Run(o); err != nil {
		t.Fatalf("Run: %v\n%s", err, o.Out)
	}
	if _, err := os.Stat(gitfile); err != nil {
		t.Fatalf("local gitfile removed: %v", err)
	}
	if res.FilesDeleted != 0 {
		t.Errorf("files deleted = %d, want 0", res.FilesDeleted)
	}
}

func TestIsVCSPath(t *testing.T) {
	for p, want := range map[string]bool{
		"wp-content/plugins/a/.git/config": true, "wp-content/themes/t/.GIT": true, ".hg": true,
		"wp-content/x/.Svn/entries": true, "wp-content/plugins/a/.github/x.yml": false,
		"wp-content/plugins/a/.gitignore": false, "wp-content/plugins/git/a.php": false,
	} {
		if got := isVCSPath(p); got != want {
			t.Errorf("isVCSPath(%q) = %v, want %v", p, got, want)
		}
	}
}
