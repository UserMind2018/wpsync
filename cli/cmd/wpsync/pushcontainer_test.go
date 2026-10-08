package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/push"
	"github.com/usermind/wpsync/internal/sites"
)

const testSalt = "00112233445566778899aabbccddeeff"

// lockedKeychain fails every access: the container mode never asks the keychain (AC-110).
type lockedKeychain struct{ t *testing.T }

func (k lockedKeychain) Get(service, _ string) (string, error) {
	k.t.Errorf("keychain read: %s", service)
	return "", errors.New("keychain locked")
}

func (k lockedKeychain) Set(service, _, _ string) error {
	k.t.Errorf("keychain write: %s", service)
	return errors.New("keychain locked")
}

func (k lockedKeychain) Delete(service, _ string) error {
	k.t.Errorf("keychain delete: %s", service)
	return errors.New("keychain locked")
}

// containerPushSite lays a pulled site in the layout of the Website Studio: <tmp>/kunde/docroot,
// baseline in <tmp>/kunde/.wpsync. plugins/x is edited, plugins/neu is new and not named,
// plugins/gone is missing locally. It returns the fake agent and the docroot.
func containerPushSite(t *testing.T) (*stagingFake, string) {
	t.Helper()
	env(t)
	f := newStagingFake(t)
	paired(t, "kunde", f.srv.URL)
	siteDir := filepath.Join(t.TempDir(), "kunde")
	docroot := filepath.Join(siteDir, "docroot")
	for _, dir := range []string{"plugins/x", "plugins/neu"} {
		if err := os.MkdirAll(filepath.Join(docroot, "wp-content", dir), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	base := baseline.New(f.srv.URL)
	base.Files["wp-content/plugins/x/x.php"] = baseline.FileStamp{Size: 5, MTime: testMTime}
	base.Files["wp-content/plugins/gone/gone.php"] = baseline.FileStamp{Size: 5, MTime: testMTime}
	base.PulledAt = time.Unix(testMTime, 0)
	if err := baseline.Save(siteDir, base); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(filepath.Join(docroot, "wp-content", "plugins", "x", "x.php"), []byte("<?php\n/* Plugin Name: X\n * Version: 1.0 */\n// edited"), 0o644)
	os.WriteFile(filepath.Join(docroot, "wp-content", "plugins", "neu", "neu.php"), []byte("<?php // new"), 0o644)
	return f, docroot
}

// cargs appends the switches of the container mode.
func cargs(docroot string, args ...string) []string {
	return append(args, "--secret-stdin", "--driver", "container", "--docroot", docroot)
}

// AC-112: falsch kombinierte Schalter sind Exit 2, ohne data und ohne Anfrage an die Site.
func TestPushContainerModeCombinations(t *testing.T) {
	f, docroot := containerPushSite(t)
	for _, args := range [][]string{
		{"push", "kunde", "code", "--driver", "container", "--docroot", docroot},
		{"push", "kunde", "code", "--secret-stdin"},
		{"push", "kunde", "code", "--secret-stdin", "--driver", "container"},
		{"push", "kunde", "code", "--secret-stdin", "--driver", "container", "--docroot", "relativ/docroot"},
		{"push", "kunde", "code", "--secret-stdin", "--driver", "container", "--docroot", "/docroot"},
		{"push", "kunde", "code", "--secret-stdin", "--driver", "container", "--docroot", filepath.Join(filepath.Dir(docroot), ".wpsync")},
		{"push", "kunde", "code", "--secret-stdin", "--driver", "podman", "--docroot", docroot},
		{"push", "kunde", "code", "--docroot", docroot},
		cargs(docroot, "push", "kunde", "code", "--container", "ws-dev-kunde"),
		cargs(docroot, "push", "kunde", "code", "--db-host", "db"),
		cargs(docroot, "push", "kunde", "code", "--local-url", "https://x.example"),
		{"pushes", "kunde", "--secret-stdin"},
		{"pushes", "kunde", "--driver", "container", "--docroot", docroot},
		cargs(docroot, "rollback", "kunde"),
		cargs(docroot, "rollback", "kunde", "--to", "staging"),
		{"rollback", "kunde", testPushID, "--secret-stdin"},
		{"rollback"},
		{"rollback", "kunde", testPushID, "extra"},
	} {
		r := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", args...)
		if r.code != cliout.ExitUsage || r.stdout != "" || !strings.Contains(r.stderr, "✗") || strings.Contains(r.stderr, testSecret) {
			t.Errorf("%v: exit %d\nstdout: %s\nstderr: %s", args, r.code, r.stdout, r.stderr)
		}
		jr := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", append(args, "--json")...)
		if m := lastResult(t, jr, args[0], cliout.ExitUsage); m["data"] != nil {
			t.Errorf("%v --json: data = %v", args, m["data"])
		}
	}
	if len(f.routes) != 0 {
		t.Errorf("a usage error reached the site: %v", f.routes)
	}
}

// AC-110, AC-119, C7: Probelauf im Container-Modus – Secret von stdin, nie die Keychain; der Plan
// nennt Fenster, übersprungene neue und lokal fehlende Einheiten.
func TestPushContainerDryRunJSON(t *testing.T) {
	f, docroot := containerPushSite(t)
	f.shut = true // a dry run needs no window
	res := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", cargs(docroot, "push", "kunde", "code", "--dry-run", "--json")...)
	m := lastResult(t, res, "push", 0)
	plan := jsonLines(t, res.stdout)[0]["data"].(map[string]any)
	if plan["window_open"] != false || fmt.Sprint(plan["skipped_new"]) != "[plugins/neu]" || fmt.Sprint(plan["missing_locally"]) != "[plugins/gone]" {
		t.Errorf("plan = %v", plan)
	}
	if d := m["data"].(map[string]any); keys(d) != "push_id status target units" || d["status"] != "dry_run" {
		t.Errorf("data = %v", d)
	}
	if f.called("push/begin") != 1 {
		t.Errorf("routes = %v", f.routes)
	}
}

// Spec Content-Push §10: --uploads gilt auch im Container-Modus. Der Fake ist ein Agent 0.5.0 –
// die Einheit geht mit, die CLI bricht mit Exit 11 und required 0.6.0 ab. Eine fehlende oder
// kaputte Liste ist Exit 2, ohne Anfrage an die Site.
func TestPushContainerUploads(t *testing.T) {
	f, docroot := containerPushSite(t)
	dir := filepath.Join(docroot, "wp-content", "uploads", "2026", "10")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(filepath.Join(dir, "neu.png"), []byte("neu"), 0o644)
	list := filepath.Join(t.TempDir(), "uploads.txt")
	os.WriteFile(list, []byte("2026/10/neu.png\n"), 0o644)
	bad := filepath.Join(t.TempDir(), "kaputt.txt")
	os.WriteFile(bad, []byte("../wp-config.php\n"), 0o644)

	for _, file := range []string{filepath.Join(t.TempDir(), "fehlt.txt"), bad} {
		res := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", cargs(docroot, "push", "kunde", "code", "plugins/x", "--uploads", file, "--dry-run", "--json")...)
		lastResult(t, res, "push", cliout.ExitUsage)
	}
	if len(f.routes) != 0 {
		t.Fatalf("a bad list reached the site: %v", f.routes)
	}

	res := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", cargs(docroot, "push", "kunde", "code", "plugins/x", "--uploads", list, "--dry-run", "--json")...)
	m := lastResult(t, res, "push", cliout.ExitAgentOutdated)
	if e, _ := m["error"].(map[string]any); e == nil || e["required"] != "0.6.0" {
		t.Errorf("error = %v", m["error"])
	}
	if !strings.Contains(f.seen.String(), `"path":"uploads"`) {
		t.Errorf("the unit uploads did not go out:\n%s", f.seen.String())
	}
}

// AC-114, S-3: der Push im Container-Modus ruft weder docker noch ddev noch WP-CLI auf.
func TestPushContainerRunsNoContainerTools(t *testing.T) {
	_, docroot := containerPushSite(t)
	bin, marker := t.TempDir(), filepath.Join(t.TempDir(), "called")
	for _, tool := range []string{"docker", "ddev", "wp"} {
		os.WriteFile(filepath.Join(bin, tool), []byte("#!/bin/sh\necho \"$0 $*\" >> "+marker+"\n"), 0o755)
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	lastResult(t, run(t, context.Background(), testSecret+"\n", cargs(docroot, "push", "kunde", "code", "--dry-run", "--json")...), "push", 0)
	if calls, err := os.ReadFile(marker); err == nil {
		t.Fatalf("container tools called:\n%s", calls)
	}
}

// AC-113, S-9: mit --secret-stdin fragt die CLI nie – eine zweite stdin-Zeile „j“ bestätigt nichts,
// ohne --yes ist es Exit 2; übertragen wird nichts.
func TestPushContainerNeverAsks(t *testing.T) {
	f, docroot := containerPushSite(t)
	for _, extra := range [][]string{nil, {"--json"}} {
		f.routes = nil
		res := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\nj\n", cargs(docroot, append([]string{"push", "kunde", "code"}, extra...)...)...)
		if res.code != cliout.ExitUsage || !strings.Contains(res.stdout+res.stderr, "--yes") {
			t.Errorf("%v: exit %d\n%s%s", extra, res.code, res.stdout, res.stderr)
		}
		if f.called("push/begin") != 1 {
			t.Errorf("%v: routes = %v", extra, f.routes)
		}
	}
}

// AC-120, C8: Exit 40 nennt admin_url und das bei pair gespeicherte Gerät.
func TestPushContainerWindowClosedNamesDeviceAndAdminPage(t *testing.T) {
	f, docroot := containerPushSite(t)
	f.shut = true
	res := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", cargs(docroot, "push", "kunde", "code", "--yes", "--json")...)
	e := lastResult(t, res, "push", cliout.ExitPushWindowClosed)["error"].(map[string]any)
	if e["admin_url"] != f.srv.URL+"/wp-admin/tools.php?page=wpsync" {
		t.Errorf("error = %v", e)
	}
	if _, ok := e["device"]; ok {
		t.Errorf("device unknown for an old pairing, must be missing: %v", e)
	}
	s, _ := sites.Load("kunde")
	s.Device = "agentic-os-dev"
	sites.Save(s)
	res = runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", cargs(docroot, "push", "kunde", "code", "--yes", "--json")...)
	if e := lastResult(t, res, "push", cliout.ExitPushWindowClosed)["error"].(map[string]any); e["device"] != "agentic-os-dev" {
		t.Errorf("error = %v", e)
	}
}

// AC-121, C2/O6: pushes im Container-Modus nennt das Journal dieses Site-Ordners und dessen
// Rescue-URL, für fremde Pushes journal:false ohne URL – nie Salt oder Schlüssel.
func TestPushesContainerNamesJournals(t *testing.T) {
	f, docroot := containerPushSite(t)
	const foreign = "p_20261005_ffffffffffff"
	f.list = `{"pushes":[{"push_id":"` + testPushID + `","device":"agentic-os-dev","target":"live","status":"confirmed","units":[{"path":"plugins/x"}],"created":1791158400},` +
		`{"push_id":"` + foreign + `","device":"mac","target":"live","status":"confirmed","units":[],"created":1791158300}]}`
	j := push.NewJournal(testPushID, f.srv.URL+"/wp-content/plugins/wpsync-agent/rescue.php", testSalt, baseline.New(f.srv.URL), []string{"plugins/x"})
	if err := push.SaveJournal(filepath.Dir(docroot), j); err != nil {
		t.Fatal(err)
	}
	res := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", cargs(docroot, "pushes", "kunde", "--json")...)
	list := lastResult(t, res, "pushes", 0)["data"].(map[string]any)["pushes"].([]any)
	own, other := list[0].(map[string]any), list[1].(map[string]any)
	if own["journal"] != true || own["rescue_url"] != j.RescueURL || own["push_id"] != testPushID || own["device"] != "agentic-os-dev" {
		t.Errorf("own = %v", own)
	}
	if _, ok := other["rescue_url"]; other["journal"] != false || ok {
		t.Errorf("foreign = %v", other)
	}
	if strings.Contains(res.stdout+res.stderr, testSalt) || strings.Contains(res.stdout+res.stderr, push.RescueKey(testSecret, testPushID, testSalt)) {
		t.Error("salt or rescue key in the output")
	}

	// Mac: unverändert, ohne journal.
	_, kc := pulledSite(t)
	res = runKC(t, context.Background(), kc, "", "pushes", "kunde", "--json")
	if p := lastResult(t, res, "pushes", 0)["data"].(map[string]any)["pushes"].([]any)[0].(map[string]any); keys(p) != "committed created device finished forced pruned push_id status target units" {
		t.Errorf("mac entry = %v", p)
	}
}

// AC-124 (ID), C14: rollback <id> im Container-Modus nimmt den Push über den Agent zurück.
func TestRollbackContainerWithID(t *testing.T) {
	f, docroot := containerPushSite(t)
	res := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", cargs(docroot, "rollback", "kunde", testPushID, "--json")...)
	if d := lastResult(t, res, "rollback", 0)["data"].(map[string]any); d["push_id"] != testPushID || d["status"] != "rolled_back" {
		t.Errorf("data = %v", d)
	}
	if f.called("push/rollback") != 1 {
		t.Errorf("routes = %v", f.routes)
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(docroot), ".wpsync", "lock")); err != nil {
		t.Errorf("lock not in the site folder: %v", err)
	}
}

// AC-111, S-1/S-2: kein Secret, kein Rollback-Schlüssel und kein Salt in stdout, stderr, JSON oder
// Dateien – über push, pushes, rollback und ihre Fehlerfälle im Container-Modus.
func TestPushContainerSecretsNeverLeak(t *testing.T) {
	f, docroot := containerPushSite(t)
	j := push.NewJournal(testPushID, f.srv.URL+"/rescue.php", testSalt, baseline.New(f.srv.URL), []string{"plugins/x"})
	if err := push.SaveJournal(filepath.Dir(docroot), j); err != nil {
		t.Fatal(err)
	}
	key := push.RescueKey(testSecret, testPushID, testSalt)
	steps := [][]string{
		cargs(docroot, "push", "kunde", "code", "--dry-run", "--json"),
		cargs(docroot, "push", "kunde", "code", "--dry-run"),
		cargs(docroot, "push", "kunde", "code", "--json"),
		cargs(docroot, "pushes", "kunde", "--json"),
		cargs(docroot, "pushes", "kunde"),
		cargs(docroot, "rollback", "kunde", testPushID, "--json"),
		cargs(docroot, "rollback", "kunde", "--json"),
		cargs(docroot, "push", "kunde", "code", "--bogus", "--json"),
	}
	f.shut = true
	steps = append(steps, cargs(docroot, "push", "kunde", "code", "--yes", "--json"))
	for i, args := range steps {
		r := runKC(t, context.Background(), lockedKeychain{t}, testSecret+"\n", args...)
		for _, secret := range []string{testSecret, key} {
			if strings.Contains(r.stdout+r.stderr, secret) {
				t.Errorf("step %d %v: secret or key in output\n%s\n%s", i, args, r.stdout, r.stderr)
			}
		}
		if strings.Contains(r.stdout, testSalt) {
			t.Errorf("step %d %v: salt on stdout", i, args)
		}
	}
	if strings.Contains(f.seen.String(), testSecret) || strings.Contains(f.seen.String(), key) {
		t.Error("secret or key sent to the agent")
	}
	filepath.WalkDir(filepath.Dir(docroot), func(path string, d os.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		data, _ := os.ReadFile(path)
		if strings.Contains(string(data), testSecret) || strings.Contains(string(data), key) {
			t.Errorf("secret or key in %s", path)
		}
		return nil
	})
}

// C8 gilt für jedes Exit 40, auch für staging create|refresh|delete|open.
func TestStagingWindowClosedNamesTheAdminPage(t *testing.T) {
	f := stagingSite(t)
	f.closed = true
	res := stg(t, "open", "kunde", "--json")
	if e := lastResult(t, res, "staging open", cliout.ExitPushWindowClosed)["error"].(map[string]any); e["admin_url"] != f.srv.URL+"/wp-admin/tools.php?page=wpsync" {
		t.Errorf("error = %v", e)
	}
}
