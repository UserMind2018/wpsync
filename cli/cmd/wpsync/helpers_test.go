package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/keychain"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

const (
	testSecret   = "S3CR3T-pairing-0123456789abcdef"
	testDBPass   = "DBPASS-only-for-tests-42"
	testFileBody = "<?php // a"
	testMTime    = 1700000000
	testTableSQL = "INSERT INTO wp_options VALUES (1);"
)

// agent is a fake wpsync agent; AgentVersion and Anon steer the version checks.
type agent struct {
	AgentVersion string
	Anon         string
	// RejectPair answers /pair like an expired code.
	RejectPair bool
	// OnFiles runs when /files is requested; the request then waits until it is cancelled
	// (SIGTERM test). nil answers normally.
	OnFiles func()
	// Staging is env.staging as JSON; empty: the site has no staging copy.
	Staging string
	srv     *httptest.Server
}

func newAgent(t *testing.T) *agent {
	t.Helper()
	a := &agent{AgentVersion: "0.3.1", Anon: "1.abcd1234"}
	a.srv = httptest.NewServer(http.HandlerFunc(a.serve))
	t.Cleanup(a.srv.Close)
	return a
}

func (a *agent) URL() string { return a.srv.URL }

func (a *agent) serve(w http.ResponseWriter, r *http.Request) {
	staging := "null"
	if a.Staging != "" {
		staging = a.Staging
	}
	env := fmt.Sprintf(`{"php_version":"8.3.35","wp_version":"6.8.1","db_server":"10.11.14-MariaDB","table_prefix":"wp_","home":%q,"siteurl":%q,"agent_version":%q,"anon":%q,"staging":%s}`,
		a.srv.URL, a.srv.URL, a.AgentVersion, a.Anon, staging)
	switch r.URL.Query().Get("rest_route") {
	case "/wpsync/v1":
		w.Write([]byte(`{"namespace":"wpsync/v1"}`))
	case "/wpsync/v1/pair":
		if a.RejectPair {
			w.WriteHeader(http.StatusForbidden)
			w.Write([]byte(`{"code":"wpsync_code","message":"Pairing-Code ungültig oder abgelaufen."}`))
			return
		}
		fmt.Fprintf(w, `{"key_id":"0123456789abcdef","secret":%q,"home":%q,"agent_version":%q}`, testSecret, a.srv.URL, a.AgentVersion)
	case "/wpsync/v1/infosheet":
		fmt.Fprintf(w, `{"sheet":{"env":%s,"tables":[{"name":"wp_options","class":"config","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`, env)
	case "/wpsync/v1/delta":
		fmt.Fprintf(w, `{"env":%s,"tables":[{"name":"wp_options","checksum":"c1","rows":1,"bytes":40}],
"files":[{"path":"wp-content/plugins/a/a.php","size":%d,"mtime":%d}],"skipped":[],"next":null}`, env, len(testFileBody), testMTime)
	case "/wpsync/v1/files":
		if a.OnFiles != nil {
			io.Copy(io.Discard, r.Body)
			a.OnFiles()
			select {
			case <-r.Context().Done():
			case <-time.After(10 * time.Second):
			}
			return
		}
		fmt.Fprintf(w, "F wp-content/plugins/a/a.php\t%d\t%d\n%s\nE\n", len(testFileBody), testMTime, testFileBody)
	case "/wpsync/v1/db-bundle":
		fmt.Fprintf(w, "T wp_options\t1\t%d\n%s\nE\n", len(testTableSQL), testTableSQL)
	default:
		http.Error(w, `{"code":"rest_no_route","message":"no route"}`, http.StatusNotFound)
	}
}

// env isolates config and sites directories.
func env(t *testing.T) (configDir, sitesDir string) {
	t.Helper()
	configDir, sitesDir = t.TempDir(), t.TempDir()
	t.Setenv("WPSYNC_CONFIG_DIR", configDir)
	t.Setenv("WPSYNC_SITES_DIR", sitesDir)
	t.Setenv("WPSYNC_MAILGUARD", "")
	return configDir, sitesDir
}

// paired stores a site with a full profile, as pair + scan would.
func paired(t *testing.T, name, url string) {
	t.Helper()
	prof := &profile.Profile{Preset: profile.PresetFull, Uploads: profile.Uploads{Proxy: true},
		Seen: profile.Seen{Tables: []string{"wp_options"}}}
	if err := sites.Save(&sites.Site{Name: name, URL: url, KeyID: "0123456789abcdef", RPS: 1000, Profile: prof}); err != nil {
		t.Fatal(err)
	}
}

type result struct {
	code           int
	stdout, stderr string
	opened         []string // links handed to the browser
}

// run executes wpsync in-process with stdin and an empty in-memory keychain.
func run(t *testing.T, ctx context.Context, stdin string, args ...string) result {
	t.Helper()
	return runKC(t, ctx, keychain.NewMemory(), stdin, args...)
}

// runKC is run with a given keychain.
func runKC(t *testing.T, ctx context.Context, kc keychain.Store, stdin string, args ...string) result {
	t.Helper()
	var out, errOut bytes.Buffer
	var opened []string
	a := &app{ctx: ctx, stdin: strings.NewReader(stdin), stdout: &out, stderr: &errOut, kc: kc}
	a.browse = func(u string) error { opened = append(opened, u); return nil } // never a real browser
	code := a.main(args)
	return result{code: code, stdout: out.String(), stderr: errOut.String(), opened: opened}
}

// jsonLines decodes every stdout line; each must be a JSON object.
func jsonLines(t *testing.T, stdout string) []map[string]any {
	t.Helper()
	var out []map[string]any
	for _, l := range strings.Split(strings.TrimSpace(stdout), "\n") {
		var m map[string]any
		if err := json.Unmarshal([]byte(l), &m); err != nil {
			t.Fatalf("stdout line is not JSON: %q", l)
		}
		out = append(out, m)
	}
	return out
}

// lastResult checks the result envelope and returns it.
func lastResult(t *testing.T, r result, command string, exit int) map[string]any {
	t.Helper()
	lines := jsonLines(t, r.stdout)
	m := lines[len(lines)-1]
	if m["event"] != "result" || m["command"] != command || m["exit_code"] != float64(exit) || r.code != exit {
		t.Fatalf("result = %v (exit %d), want command %s exit %d\nstderr: %s", m, r.code, command, exit, r.stderr)
	}
	if (exit == 0) != (m["ok"] == true) {
		t.Fatalf("ok = %v for exit %d", m["ok"], exit)
	}
	return m
}

// requireKeys checks that obj has all keys.
func requireKeys(t *testing.T, obj any, keys ...string) map[string]any {
	t.Helper()
	m, ok := obj.(map[string]any)
	if !ok {
		t.Fatalf("not an object: %v", obj)
	}
	for _, k := range keys {
		if _, ok := m[k]; !ok {
			t.Errorf("missing key %q in %v", k, m)
		}
	}
	return m
}

func saveWithoutProfile(name, url string) error {
	return sites.Save(&sites.Site{Name: name, URL: url, KeyID: "0123456789abcdef", RPS: 1000})
}

// fakeDocker logs argv per call; the mailguard check answers ok, docker inspect "true".
const fakeDocker = `#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
case "$1" in
  inspect) echo true; exit 0;;
  version) echo 29.4.0; exit 0;;
esac
case "$*" in
  *local_mailguard_collect*) echo "${FAKE_MAILGUARD:-ok}";;
esac
[ "$2" = "--rm" ] && [ "$3" = "-i" ] && cat > /dev/null
exit 0
`

// guardMount stands for the mailguard the caller mounts read-only into mu-plugins.
const guardMount = "<?php // read-only mount of the caller"

// containerSite prepares fake docker and a docroot as the caller leaves it before the first pull:
// core downloaded, wp-config.php from the WordPress image, mailguard mounted. It returns the
// docroot and the docker call log.
func containerSite(t *testing.T) (docroot, dockerLog string) {
	t.Helper()
	bin := t.TempDir()
	if err := os.WriteFile(filepath.Join(bin, "docker"), []byte(fakeDocker), 0o755); err != nil {
		t.Fatal(err)
	}
	dockerLog = filepath.Join(bin, "calls")
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv("FAKE_DOCKER_LOG", dockerLog)
	t.Setenv("FAKE_MAILGUARD", "")
	docroot = filepath.Join(t.TempDir(), "vorlage", "docroot")
	os.MkdirAll(filepath.Join(docroot, "wp-includes"), 0o755)
	os.MkdirAll(filepath.Join(docroot, "wp-content", "mu-plugins"), 0o755)
	os.WriteFile(filepath.Join(docroot, "wp-includes", "version.php"), []byte("<?php $wp_version = '6.8.1';"), 0o644)
	os.WriteFile(filepath.Join(docroot, "wp-config.php"), []byte("<?php // from the wordpress image"), 0o644)
	os.WriteFile(filepath.Join(docroot, "wp-content", "mu-plugins", "00-local-mailguard.php"), []byte(guardMount), 0o444)
	return docroot, dockerLog
}

// containerArgs are the pull flags of the container mode.
func containerArgs(docroot string) []string {
	return []string{"--driver", "container", "--container", "ws-dev-vorlage", "--docroot", docroot,
		"--db-host", "wp-mariadb", "--db-name", "ws_dev_vorlage", "--db-user", "ws_dev_vorlage",
		"--local-url", "https://vorlage.dev.example"}
}

// secrets is the stdin of the container mode: pairing secret, DB password.
const secrets = testSecret + "\n" + testDBPass + "\n"
