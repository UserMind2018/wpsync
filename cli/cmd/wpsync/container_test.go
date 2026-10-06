package main

import (
	"bufio"
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/localgit"
)

// Spec §9: Pull gegen einen Container, Post-Setup, Folge-Pull – mit Zeilen-Ereignissen.
func TestPullJSONContainerFirstAndFollowUp(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "vorlage", ag.URL())
	docroot, dockerLog := containerSite(t)
	args := append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)

	r := run(t, context.Background(), secrets, args...)
	m := lastResult(t, r, "pull", 0)
	d := requireKeys(t, m["data"], "first_pull", "files_changed", "tables_loaded", "local_url", "agent_version", "requests", "duration_ms")
	if d["first_pull"] != true || d["local_url"] != "https://vorlage.dev.example" {
		t.Errorf("data = %v", d)
	}
	var phases []string
	for _, l := range jsonLines(t, r.stdout) {
		if l["event"] == "phase" {
			requireKeys(t, l, "name", "done", "total")
			phases = append(phases, l["name"].(string))
		}
	}
	if got := strings.Join(phases, ","); got != "delta,setup,files,files,db_download,db_import,postsetup,mailguard" {
		t.Errorf("phases = %s", got)
	}
	if !strings.Contains(r.stderr, "local-mailguard aktiv") {
		t.Errorf("human messages belong on stderr:\n%s", r.stderr)
	}
	if _, err := os.Stat(filepath.Join(docroot, "wp-content/plugins/a/a.php")); err != nil {
		t.Errorf("pulled file missing: %v", err)
	}
	if guard, _ := os.ReadFile(filepath.Join(docroot, "wp-content/mu-plugins/00-local-mailguard.php")); string(guard) != guardMount {
		t.Errorf("the caller's mailguard must stay untouched, got %q", guard)
	}
	if _, err := os.Stat(localgit.TreeGitDir(filepath.Dir(docroot))); err != nil {
		t.Errorf("snapshot repo next to the docroot missing: %v", err)
	}
	if _, err := os.Lstat(filepath.Join(filepath.Dir(docroot), ".git")); err == nil {
		t.Error("no .git in the site folder")
	}
	calls, _ := os.ReadFile(dockerLog)
	if strings.Contains(string(calls), "core download") {
		t.Error("the container mode must not download the core")
	}
	for _, want := range []string{"mariadb --skip-ssl --binary-mode --local-infile=0 -h wp-mariadb", "wp search-replace " + ag.URL() + " https://vorlage.dev.example"} {
		if !strings.Contains(string(calls), want) {
			t.Errorf("missing docker call %q", want)
		}
	}

	r = run(t, context.Background(), secrets, args...)
	m = lastResult(t, r, "pull", 0)
	if d := m["data"].(map[string]any); d["first_pull"] != false || d["files_changed"] != float64(0) || d["tables_loaded"] != float64(0) {
		t.Errorf("follow-up data = %v", d)
	}
}

// Fehlt der Core im Docroot, endet der Erst-Pull mit local_env, ohne Download.
func TestPullContainerWithoutCoreIsLocalEnv(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "vorlage", ag.URL())
	docroot, dockerLog := containerSite(t)
	os.RemoveAll(filepath.Join(docroot, "wp-includes"))
	r := run(t, context.Background(), secrets, append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)...)
	m := lastResult(t, r, "pull", 20)
	if e := m["error"].(map[string]any); e["code"] != "local_env" || !strings.Contains(e["message"].(string), "kein WordPress-Core") {
		t.Fatalf("error = %v", e)
	}
	if calls, _ := os.ReadFile(dockerLog); strings.Contains(string(calls), "core download") {
		t.Fatal("no core download in the container mode")
	}
}

// Ist der vom Aufrufer gemountete Riegel nicht aktiv, endet der Pull mit postsetup_failed.
func TestPullContainerWithoutActiveMailguardIsPostSetupFailed(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "vorlage", ag.URL())
	docroot, _ := containerSite(t)
	t.Setenv("FAKE_MAILGUARD", "missing")
	r := run(t, context.Background(), secrets, append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)...)
	m := lastResult(t, r, "pull", 22)
	if e := m["error"].(map[string]any); e["code"] != "postsetup_failed" {
		t.Fatalf("error = %v", e)
	}
}

func TestPullContainerNeedsSecretStdin(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "vorlage", ag.URL())
	docroot, _ := containerSite(t)
	kcArgs := append([]string{"pull", "vorlage", "--json", "--yes"}, containerArgs(docroot)...)
	r := run(t, context.Background(), "", kcArgs...)
	lastResult(t, r, "pull", 2)
}

func TestPullContainerRefusesUploadsSince(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "vorlage", ag.URL())
	r := run(t, context.Background(), testSecret+"\n", "scan", "vorlage", "--json", "--secret-stdin", "--preset", "nur-content", "--uploads-since", "2024")
	lastResult(t, r, "scan", 0)
	docroot, _ := containerSite(t)
	r = run(t, context.Background(), secrets, append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)...)
	lastResult(t, r, "pull", 2)

	r = run(t, context.Background(), testSecret+"\n", "scan", "vorlage", "--json", "--secret-stdin", "--preset", "nur-content", "--uploads-since", "alle")
	lastResult(t, r, "scan", 0)
	r = run(t, context.Background(), secrets, append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)...)
	lastResult(t, r, "pull", 0)
}

// Spec §10: Abbruch während des Pulls → Exit 30, der nächste Pull setzt fort.
func TestPullJSONContainerInterruptedAndResumed(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "vorlage", ag.URL())
	docroot, _ := containerSite(t)
	args := append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)

	ctx, cancel := context.WithCancel(context.Background())
	ag.OnFiles = cancel
	r := run(t, ctx, secrets, args...)
	m := lastResult(t, r, "pull", 30)
	if e := m["error"].(map[string]any); e["code"] != "interrupted" {
		t.Fatalf("error = %v", e)
	}

	ag.OnFiles = nil
	r = run(t, context.Background(), secrets, args...)
	lastResult(t, r, "pull", 0)
}

func TestListAndStopContainers(t *testing.T) {
	env(t)
	_, dockerLog := containerSite(t)
	r := run(t, context.Background(), "", "stop", "--all", "--driver", "container")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.stderr)
	}
	calls, _ := os.ReadFile(dockerLog)
	if !strings.Contains(string(calls), "ps -a --filter label=um.website-studio=1") {
		t.Fatalf("calls = %s", calls)
	}
}

// Echtes SIGTERM an das Binary: Exit 30 und ein Ergebnis-Objekt mit interrupted.
func TestSIGTERMEndsPullWithExit30(t *testing.T) {
	if testing.Short() {
		t.Skip("builds the binary")
	}
	env(t)
	ag := newAgent(t)
	paired(t, "vorlage", ag.URL())
	docroot, _ := containerSite(t)
	bin := filepath.Join(t.TempDir(), "wpsync")
	if out, err := exec.Command("go", "build", "-o", bin, ".").CombinedOutput(); err != nil {
		t.Fatalf("build: %v\n%s", err, out)
	}
	ag.OnFiles = func() {}
	cmd := exec.Command(bin, append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)...)
	cmd.Stdin = strings.NewReader(secrets)
	stdout, _ := cmd.StdoutPipe()
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	sc := bufio.NewScanner(stdout)
	var last string
	for sc.Scan() {
		last = sc.Text()
		if strings.Contains(last, `"name":"files","done":0`) {
			time.Sleep(200 * time.Millisecond)
			cmd.Process.Signal(syscall.SIGTERM)
		}
	}
	err := cmd.Wait()
	exitErr, ok := err.(*exec.ExitError)
	if !ok || exitErr.ExitCode() != 30 {
		t.Fatalf("exit = %v", err)
	}
	if !strings.Contains(last, `"code":"interrupted"`) {
		t.Fatalf("last line = %s", last)
	}
}
