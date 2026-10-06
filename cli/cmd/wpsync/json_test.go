package main

import (
	"context"
	"testing"
)

// Spec Server-Modus §9: Schema-Test für jedes Ergebnis.
func TestVersionJSON(t *testing.T) {
	r := run(t, context.Background(), "", "version", "--json")
	m := lastResult(t, r, "version", 0)
	requireKeys(t, m["data"], "version", "min_agent_version")
	if r.stderr == "" {
		t.Error("human line belongs on stderr")
	}
}

func TestUsageErrorIsJSON(t *testing.T) {
	env(t)
	r := run(t, context.Background(), "", "pull", "--json", "--bogus")
	m := lastResult(t, r, "pull", 2)
	e := requireKeys(t, m["error"], "code", "message")
	if e["code"] != "usage" {
		t.Fatalf("error = %v", e)
	}
}

func TestScanJSON(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "kunde", ag.URL())
	r := run(t, context.Background(), testSecret+"\n", "scan", "kunde", "--json", "--secret-stdin", "--preset", "ohne-transaktionen")
	m := lastResult(t, r, "scan", 0)
	d := requireKeys(t, m["data"], "site", "agent_version", "required_agent_version", "agent_ok", "infosheet", "profile", "requests")
	if d["agent_version"] != "0.3.1" || d["agent_ok"] != true {
		t.Errorf("data = %v", d)
	}
	requireKeys(t, d["profile"], "preset", "uploads")
}

// Ohne Terminal und mit --json nie eine Rückfrage: fehlt das Preset, ist das ein usage-Fehler.
func TestScanJSONWithoutPresetIsUsage(t *testing.T) {
	env(t)
	ag := newAgent(t)
	if err := saveWithoutProfile("kunde", ag.URL()); err != nil {
		t.Fatal(err)
	}
	r := run(t, context.Background(), testSecret+"\n", "scan", "kunde", "--json", "--secret-stdin")
	lastResult(t, r, "scan", 2)
}

func TestStatusJSONBeforeAndAfterPull(t *testing.T) {
	env(t)
	ag := newAgent(t)
	paired(t, "kunde", ag.URL())
	r := run(t, context.Background(), testSecret+"\n", "status", "kunde", "--json", "--secret-stdin")
	m := lastResult(t, r, "status", 0)
	d := requireKeys(t, m["data"], "pulled", "source", "files_changed", "files_deleted", "tables_changed", "requests")
	if d["pulled"] != false {
		t.Errorf("data = %v", d)
	}
}

// Spec §10: veralteter Agent → Exit 11 mit beiden Versionen im JSON.
func TestPullJSONAgentOutdated(t *testing.T) {
	env(t)
	ag := newAgent(t)
	ag.AgentVersion, ag.Anon = "0.2.0", ""
	paired(t, "kunde", ag.URL())
	r := run(t, context.Background(), testSecret+"\n", "pull", "kunde", "--json", "--secret-stdin", "--yes")
	m := lastResult(t, r, "pull", 11)
	e := requireKeys(t, m["error"], "code", "message", "installed", "required")
	if e["code"] != "agent_outdated" || e["installed"] != "0.2.0" || e["required"] != "0.3.0" {
		t.Fatalf("error = %v", e)
	}
}

func TestUnpairJSON(t *testing.T) {
	env(t)
	paired(t, "kunde", "https://kunde.example")
	r := run(t, context.Background(), "", "unpair", "kunde", "--json")
	m := lastResult(t, r, "unpair", 0)
	requireKeys(t, m["data"], "site", "url")
}

func TestDoctorJSONHasChecks(t *testing.T) {
	env(t)
	r := run(t, context.Background(), "", "doctor", "--json")
	lines := jsonLines(t, r.stdout)
	m := lines[len(lines)-1]
	if r.code != 0 && r.code != 20 {
		t.Fatalf("doctor exit = %d", r.code)
	}
	d := requireKeys(t, m["data"], "checks")
	for _, c := range d["checks"].([]any) {
		requireKeys(t, c, "name", "ok", "detail")
	}
}

// Spec §10: doctor --server grün, wenn Docker erreichbar und WPSYNC_CONFIG_DIR beschreibbar ist.
func TestDoctorServerJSON(t *testing.T) {
	env(t)
	containerSite(t) // docker auf PATH, antwortet
	r := run(t, context.Background(), "", "doctor", "--server", "--json")
	m := lastResult(t, r, "doctor", 0)
	d := requireKeys(t, m["data"], "checks")
	if n := len(d["checks"].([]any)); n != 4 {
		t.Fatalf("checks = %d, want 4", n)
	}
}
