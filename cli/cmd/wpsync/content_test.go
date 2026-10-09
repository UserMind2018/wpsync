package main

import (
	"bytes"
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/content"
	"github.com/usermind/wpsync/internal/ddev"
)

func TestContentNeedsASubcommand(t *testing.T) {
	var out, errOut bytes.Buffer
	a := &app{stdout: &out, stderr: &errOut}
	for _, args := range [][]string{{}, {"import", "kunde"}, {"export"}, {"export", "a", "b"}} {
		err := a.cmdContent(args)
		if cliout.Classify(err).Exit != cliout.ExitUsage {
			t.Errorf("%v: exit %d, err %v", args, cliout.Classify(err).Exit, err)
		}
	}
	if out.Len() != 0 {
		t.Errorf("stdout = %q", out.String())
	}
}

func TestUsageNamesContentExport(t *testing.T) {
	if !strings.Contains(usage, "wpsync content export <site>") || !strings.Contains(usage, "--content") {
		t.Fatal("usage lacks content export or pull --content")
	}
}

// exportRows are the record lines the fake site answers the export with; exportOutput is the whole
// output of the script: a PHP notice in between and the closing line.
const (
	exportRows = `{"t":"posts","k":"1","h":"aa","row":{"post_title":"Hallo"},"p":true}` + "\n" +
		`{"t":"options","k":"admin_email","h":"bb","row":{"option_value":"x"},"p":false,"why":"blocked"}` + "\n"
	exportOutput = `{"t":"posts","k":"1","h":"aa","row":{"post_title":"Hallo"},"p":true}` + "\n" +
		"Notice: something of the site\n" +
		`{"t":"options","k":"admin_email","h":"bb","row":{"option_value":"x"},"p":false,"why":"blocked"}` + "\n" +
		`{"end":true,"rows":2}` + "\n"
	mapLocalURL = "https://map.dev.example" // not the --local-url of containerArgs
)

// contentState writes a fresh content state as pull --content leaves it, with local as the URL of
// the working copy.
func contentState(t *testing.T, siteDir, local string) {
	t.Helper()
	if _, err := content.WriteMap(siteDir, content.Map{CanonVersion: content.CanonVersion, Variants: []string{"plain", "esc1", "esc2"},
		Local: local, PulledAt: "2026-10-09T08:00:00Z"}); err != nil {
		t.Fatal(err)
	}
	f := content.Paths(siteDir)
	for path, data := range map[string]string{
		f.Manifest: `{"t":"posts","k":"1","h":"aa"}` + "\n", f.Baseline: `{"t":"posts","k":"1","h":"aa"}` + "\n", f.Unfaithful: "",
		f.Env:     `{"php_version":"8.3.35","table_prefix":"kd_"}` + "\n",
		f.Summary: fmt.Sprintf(`{"rows":1,"unfaithful":0,"id_max":{"posts":1},"canon_version":%d,"reloaded":false}`+"\n", content.CanonVersion),
	} {
		if err := os.WriteFile(path, []byte(data), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

// contentFiles is name and content of every file below .wpsync/content.
func contentFiles(t *testing.T, siteDir string) string {
	t.Helper()
	dir := content.Paths(siteDir).Dir
	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatal(err)
	}
	var out []string
	for _, e := range entries {
		data, err := os.ReadFile(filepath.Join(dir, e.Name()))
		if err != nil {
			t.Fatal(err)
		}
		out = append(out, e.Name()+"\x00"+string(data))
	}
	sort.Strings(out)
	return strings.Join(out, "\x01")
}

// offlineSite pairs "vorlage" with a site that must not see a single request: the export runs
// without the network.
func offlineSite(t *testing.T) {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("content export asked the site: %s %s", r.Method, r.URL)
		http.Error(w, "offline", http.StatusServiceUnavailable)
	}))
	t.Cleanup(srv.Close)
	paired(t, "vorlage", srv.URL)
}

func exportArgs(docroot string, extra ...string) []string {
	return append(append([]string{"content", "export", "vorlage", "--secret-stdin"}, extra...), containerArgs(docroot)...)
}

// Container mode: stdout carries the record lines and nothing else, every message – also the one
// of the docker run – goes to stderr, the URL comes from map.json, PHP version and table prefix
// come from env.json (no request to the site), and nothing below .wpsync/content changes.
func TestContentExportContainerWritesOnlyRowsToStdout(t *testing.T) {
	env(t)
	offlineSite(t)
	docroot, dockerLog := containerSite(t)
	t.Setenv("FAKE_EXPORT", exportOutput)
	siteDir := filepath.Dir(docroot)
	contentState(t, siteDir, mapLocalURL)
	before := contentFiles(t, siteDir)

	r := run(t, context.Background(), secrets, exportArgs(docroot)...)
	if r.code != 0 {
		t.Fatalf("exit %d\nstderr: %s", r.code, r.stderr)
	}
	if r.stdout != exportRows {
		t.Errorf("stdout = %q", r.stdout)
	}
	for _, want := range []string{"2 Zeilen exportiert", "docker noise"} {
		if !strings.Contains(r.stderr, want) {
			t.Errorf("stderr lacks %q:\n%s", want, r.stderr)
		}
	}
	calls, _ := os.ReadFile(dockerLog)
	for _, want := range []string{"run --rm -i --init", "wordpress:cli-php8.3 wp eval-file - " + mapLocalURL + " all --skip-plugins --skip-themes"} {
		if !strings.Contains(string(calls), want) {
			t.Errorf("missing %q in the docker calls:\n%s", want, calls)
		}
	}
	if !strings.Contains(string(calls), "-e WORDPRESS_TABLE_PREFIX ") {
		t.Errorf("the run does not name the table prefix:\n%s", calls)
	}
	if strings.Contains(string(calls), "search-replace") || strings.Contains(string(calls), "mariadb") {
		t.Errorf("the export must not write to the site:\n%s", calls)
	}
	if after := contentFiles(t, siteDir); after != before {
		t.Error("the export changed .wpsync/content")
	}
	for _, secret := range []string{testSecret, testDBPass} {
		if strings.Contains(r.stdout+r.stderr+string(calls), secret) {
			t.Error("secret in output or docker arguments")
		}
	}
}

// B6: with --json the record lines stay as they are and the result object is the last line.
func TestContentExportJSONEndsWithTheResult(t *testing.T) {
	env(t)
	offlineSite(t)
	docroot, _ := containerSite(t)
	t.Setenv("FAKE_EXPORT", exportOutput)
	contentState(t, filepath.Dir(docroot), mapLocalURL)

	r := run(t, context.Background(), secrets, exportArgs(docroot, "--json")...)
	m := lastResult(t, r, "content export", 0)
	d := requireKeys(t, m["data"], "rows", "canon_version")
	if d["rows"] != float64(2) || d["canon_version"] != float64(content.CanonVersion) {
		t.Errorf("data = %v", d)
	}
	if !strings.HasPrefix(r.stdout, exportRows) || strings.Count(r.stdout, "\n") != 3 {
		t.Errorf("stdout = %q", r.stdout)
	}
	if !strings.Contains(r.stderr, "2 Zeilen exportiert") {
		t.Errorf("stderr = %q", r.stderr)
	}
}

// An export that stops halfway fails; the rows written so far are followed by the failed result.
func TestContentExportIncompleteFails(t *testing.T) {
	env(t)
	offlineSite(t)
	docroot, _ := containerSite(t)
	t.Setenv("FAKE_EXPORT", exportRows) // no closing line
	contentState(t, filepath.Dir(docroot), mapLocalURL)

	r := run(t, context.Background(), secrets, exportArgs(docroot, "--json")...)
	lines := jsonLines(t, r.stdout)
	m := lines[len(lines)-1]
	e := requireKeys(t, m["error"], "code", "message", "reason")
	if r.code == 0 || m["ok"] != false || m["command"] != "content export" || e["reason"] != "content_export_failed" {
		t.Fatalf("exit %d, result = %v", r.code, m)
	}
	r = run(t, context.Background(), secrets, exportArgs(docroot)...)
	if r.code == 0 || r.stdout != exportRows || !strings.Contains(r.stderr, "unvollständig") {
		t.Fatalf("exit %d\nstdout: %q\nstderr: %s", r.code, r.stdout, r.stderr)
	}
}

// Without a fresh content state there is nothing to normalize against: usage error with the next
// step, no docker run, nothing on stdout.
func TestContentExportNeedsAFreshContentState(t *testing.T) {
	env(t)
	offlineSite(t)
	docroot, dockerLog := containerSite(t)
	t.Setenv("FAKE_EXPORT", exportOutput)
	siteDir := filepath.Dir(docroot)
	check := func(state string) {
		t.Helper()
		r := run(t, context.Background(), secrets, exportArgs(docroot)...)
		if r.code != cliout.ExitUsage || r.stdout != "" || !strings.Contains(r.stderr, "zuerst: wpsync pull vorlage --content") {
			t.Errorf("%s: exit %d\nstdout: %q\nstderr: %s", state, r.code, r.stdout, r.stderr)
		}
		if calls, _ := os.ReadFile(dockerLog); strings.Contains(string(calls), "eval-file") {
			t.Errorf("%s: the export ran:\n%s", state, calls)
		}
	}
	check("never pulled with --content")
	if _, err := os.Stat(filepath.Join(siteDir, ".wpsync")); err == nil {
		t.Error("a refused export created .wpsync")
	}

	contentState(t, siteDir, mapLocalURL)
	if err := os.Remove(content.Paths(siteDir).Summary); err != nil {
		t.Fatal(err)
	}
	check("state dropped (B11)")

	r := run(t, context.Background(), secrets, exportArgs(docroot, "--json")...)
	m := lastResult(t, r, "content export", cliout.ExitUsage)
	if e := requireKeys(t, m["error"], "code", "message"); e["code"] != "usage" || len(jsonLines(t, r.stdout)) != 1 {
		t.Errorf("stdout = %q", r.stdout)
	}
}

// env.json lies in the site folder and is checked like the values of /delta: with a value that
// must not reach docker or WP-CLI, or with a file that cannot be read, nothing runs.
func TestContentExportRefusesAnUnusableEnv(t *testing.T) {
	env(t)
	offlineSite(t)
	docroot, dockerLog := containerSite(t)
	t.Setenv("FAKE_EXPORT", exportOutput)
	siteDir := filepath.Dir(docroot)
	contentState(t, siteDir, mapLocalURL)
	envFile := content.Paths(siteDir).Env
	for _, bad := range []string{
		`{"php_version":"8.3.35","table_prefix":"--exec=x"}`,
		`{"php_version":"8.3 --privileged","table_prefix":"wp_"}`,
		`{"table_prefix":"wp_"}`,
		`not json`,
	} {
		if err := os.WriteFile(envFile, []byte(bad), 0o644); err != nil {
			t.Fatal(err)
		}
		r := run(t, context.Background(), secrets, exportArgs(docroot)...)
		if r.code != cliout.ExitLocalEnv || r.stdout != "" {
			t.Errorf("%s: exit %d, stdout %q", bad, r.code, r.stdout)
		}
		for _, want := range []string{"env.json", "wpsync pull vorlage --content --full"} {
			if !strings.Contains(r.stderr, want) {
				t.Errorf("%s: stderr lacks %q:\n%s", bad, want, r.stderr)
			}
		}
	}
	// Without the file the state is not fresh at all.
	if err := os.Remove(envFile); err != nil {
		t.Fatal(err)
	}
	r := run(t, context.Background(), secrets, exportArgs(docroot)...)
	if r.code != cliout.ExitUsage || r.stdout != "" || !strings.Contains(r.stderr, "zuerst: wpsync pull vorlage --content") {
		t.Errorf("missing env.json: exit %d\nstdout: %q\nstderr: %s", r.code, r.stdout, r.stderr)
	}
	if calls, _ := os.ReadFile(dockerLog); len(calls) != 0 {
		t.Errorf("docker ran:\n%s", calls)
	}
}

// fakeDDEV logs argv per call; `ddev wp eval-file -` prints $FAKE_EXPORT on stdout and a line on
// stderr, `ddev describe` would name another URL than map.json.
const fakeDDEV = `#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_DDEV_LOG"
case "$*" in
  *eval-file*) cat > /dev/null; echo "ddev noise" >&2; printf '%s' "$FAKE_EXPORT";;
  describe*) echo '{"raw":{"httpurl":"http://from-describe.ddev.site"}}';;
esac
exit 0
`

// Mac mode: the DDEV driver writes its messages to stderr although the command runs without
// --json, the URL comes from map.json and DDEV is neither started nor asked for its URL. The
// keychain is not touched.
func TestContentExportDDEVKeepsStdoutForRows(t *testing.T) {
	_, root := env(t)
	useDocker(t, &cmdDocker{})
	bin := t.TempDir()
	if err := os.WriteFile(filepath.Join(bin, "ddev"), []byte(fakeDDEV), 0o755); err != nil {
		t.Fatal(err)
	}
	ddevLog := filepath.Join(bin, "calls")
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv("FAKE_DDEV_LOG", ddevLog)
	t.Setenv("FAKE_EXPORT", exportOutput)
	paired(t, "kunde", "https://kunde.example")
	siteDir := filepath.Join(root, "kunde")
	write(t, siteDir, "config.yaml", "name: kunde\n")
	store, err := ddevStore(root)
	if err != nil {
		t.Fatal(err)
	}
	project, err := ddev.OpenProject("kunde", siteDir, store, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := project.Accept(); err != nil {
		t.Fatal(err)
	}
	contentState(t, siteDir, "http://kunde.ddev.site")
	before := contentFiles(t, siteDir)

	// An empty keychain: the export needs no secret, it asks nobody.
	r := run(t, context.Background(), "", "content", "export", "kunde")
	if r.code != 0 {
		t.Fatalf("exit %d\nstderr: %s", r.code, r.stderr)
	}
	if r.stdout != exportRows {
		t.Errorf("stdout = %q", r.stdout)
	}
	for _, want := range []string{"2 Zeilen exportiert", "ddev noise"} {
		if !strings.Contains(r.stderr, want) {
			t.Errorf("stderr lacks %q:\n%s", want, r.stderr)
		}
	}
	calls, _ := os.ReadFile(ddevLog)
	if got := strings.TrimSpace(string(calls)); got != "wp eval-file - http://kunde.ddev.site all --skip-plugins --skip-themes" {
		t.Errorf("ddev calls:\n%s", got)
	}
	if after := contentFiles(t, siteDir); after != before {
		t.Error("the export changed .wpsync/content")
	}
}

// out() and interactive() of a command whose stdout carries data: messages on stderr, no question.
func TestDataStdoutMovesMessagesToStderr(t *testing.T) {
	var out, errOut bytes.Buffer
	a := &app{stdout: &out, stderr: &errOut}
	if a.out() != a.stdout {
		t.Fatal("a plain command writes its messages to stdout")
	}
	a.dataStdout = true
	if a.out() != a.stderr || a.interactive() {
		t.Fatal("with data on stdout messages belong on stderr and nothing asks")
	}
}
