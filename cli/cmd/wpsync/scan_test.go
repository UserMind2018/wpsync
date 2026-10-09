package main

import (
	"context"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

const wordfenceTables = `[{"name":"wp_options","class":"config","essential":true},
{"name":"wp_wffilemods","bytes":400000000,"class":"log","plugin":"wordfence"}]`

func scanAgent(t *testing.T) {
	t.Helper()
	env(t)
	ag := newAgent(t)
	ag.Tables = wordfenceTables
	paired(t, "kunde", ag.URL())
}

func storedProfile(t *testing.T) *profile.Profile {
	t.Helper()
	site, err := sites.Load("kunde")
	if err != nil {
		t.Fatal(err)
	}
	return site.Profile
}

func scanJSON(t *testing.T, args ...string) result {
	t.Helper()
	return run(t, context.Background(), testSecret+"\n", append([]string{"scan", "kunde", "--json", "--secret-stdin"}, args...)...)
}

// W1: --table landet als Override im gespeicherten Profil; ohne Auffälligkeit keine Warnung.
func TestScanTableOverrideIsStored(t *testing.T) {
	scanAgent(t)
	r := scanJSON(t, "--preset", "vollstaendig", "--table", "wp_wffilemods=skip", "--table", "wp_wffilemods=skip")
	d := requireKeys(t, lastResult(t, r, "scan", 0)["data"], "profile")
	if _, has := d["warnings"]; has {
		t.Errorf("warnings without reason: %v", d["warnings"])
	}
	if _, has := d["unknown_tables"]; has {
		t.Errorf("unknown_tables without reason: %v", d["unknown_tables"])
	}
	tables := requireKeys(t, requireKeys(t, d["profile"], "tables")["tables"], "overrides")
	if want := map[string]any{"wp_wffilemods": "skip"}; !reflect.DeepEqual(tables["overrides"], want) {
		t.Errorf("data.profile.tables.overrides = %v", tables["overrides"])
	}
	if got, want := storedProfile(t).Tables.Overrides, map[string]string{"wp_wffilemods": "skip"}; !reflect.DeepEqual(got, want) {
		t.Errorf("stored overrides = %v", got)
	}
}

// W1: jede Ablehnung ist ein usage-Fehler (Exit 2), das gespeicherte Profil bleibt, wie es war.
func TestScanTableOverrideRejected(t *testing.T) {
	cases := []struct {
		name string
		args []string
		want string
	}{
		{"without preset", []string{"--table", "wp_wffilemods=skip"}, "nur zusammen mit --preset"},
		{"no equals sign", []string{"--preset", "vollstaendig", "--table", "wp_wffilemods"}, "<tabelle>=structure|skip"},
		{"empty name", []string{"--preset", "vollstaendig", "--table", "=skip"}, "<tabelle>=structure|skip"},
		{"mode full", []string{"--preset", "vollstaendig", "--table", "wp_wffilemods=full"}, "structure oder skip"},
		{"empty mode", []string{"--preset", "vollstaendig", "--table", "wp_wffilemods="}, "structure oder skip"},
		{"two modes", []string{"--preset", "vollstaendig", "--table", "wp_wffilemods=skip", "--table", "wp_wffilemods=structure"}, "verschiedenen Modi"},
		{"essential", []string{"--preset", "vollstaendig", "--table", "wp_options=structure"}, "Kern-Tabelle"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			scanAgent(t)
			r := scanJSON(t, c.args...)
			e := requireKeys(t, lastResult(t, r, "scan", 2)["error"], "code", "message")
			if msg, _ := e["message"].(string); e["code"] != "usage" || !strings.Contains(msg, c.want) {
				t.Errorf("error = %v, want %q", e, c.want)
			}
			if p := storedProfile(t); p.Preset != profile.PresetFull || len(p.Tables.Overrides) != 0 {
				t.Errorf("profile was stored: %+v", p)
			}
		})
	}
}

// W1: eine Tabelle, die der Scan nicht nennt, wird gespeichert und gemeldet – kein Fehler.
func TestScanTableOverrideUnknownTableWarns(t *testing.T) {
	scanAgent(t)
	r := scanJSON(t, "--preset", "vollstaendig", "--table", "wp_tippfehler=skip", "--table", "wp_wffilemods=structure")
	d := requireKeys(t, lastResult(t, r, "scan", 0)["data"], "warnings", "unknown_tables")
	if !reflect.DeepEqual(d["warnings"], []any{"table_unknown"}) || !reflect.DeepEqual(d["unknown_tables"], []any{"wp_tippfehler"}) {
		t.Errorf("warnings = %v, unknown_tables = %v", d["warnings"], d["unknown_tables"])
	}
	if !strings.Contains(r.stderr, "wp_tippfehler") || !strings.Contains(r.stderr, "nicht im Infosheet") {
		t.Errorf("stderr lacks the hint:\n%s", r.stderr)
	}
	want := map[string]string{"wp_tippfehler": "skip", "wp_wffilemods": "structure"}
	if got := storedProfile(t).Tables.Overrides; !reflect.DeepEqual(got, want) {
		t.Errorf("stored overrides = %v", got)
	}

	// Ohne --json: der Hinweis steht auf stderr, stdout bleibt die gewohnte Ausgabe.
	r = run(t, context.Background(), testSecret+"\n", "scan", "kunde", "--secret-stdin", "--preset", "vollstaendig", "--table", "wp_tippfehler=skip")
	if r.code != 0 || !strings.Contains(r.stderr, "wp_tippfehler") || strings.Contains(r.stdout, "nicht im Infosheet") {
		t.Errorf("exit %d\nstdout: %s\nstderr: %s", r.code, r.stdout, r.stderr)
	}
}
