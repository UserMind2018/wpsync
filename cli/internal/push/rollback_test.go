package push

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/baseline"
)

// pushed runs a successful push and returns everything needed to undo it.
func pushed(t *testing.T) (*fakeSite, Options, string) {
	t.Helper()
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	f.routes = nil
	out.Reset()
	return f, o, siteDir
}

// AC-55
func TestRollbackRestoresTheBaseline(t *testing.T) {
	f, o, siteDir := pushed(t)
	var commits []string
	o.Commit = func(_, msg string) error { commits = append(commits, msg); return nil }

	if err := Rollback(o, ""); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "list rollback" {
		t.Errorf("routes = %s", got)
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1700000000 {
		t.Errorf("baseline not reverted: %v", base.Files["wp-content/plugins/x/main.php"])
	}
	j, _ := LoadJournal(siteDir, testID)
	if j.Applied {
		t.Error("journal still marked as applied")
	}
	if len(commits) != 1 || !strings.Contains(commits[0], "rollback "+testID) {
		t.Errorf("commits = %v", commits)
	}
	if units, _, _ := Scan(siteDir+"/public", base); len(units) != 1 {
		t.Errorf("the local edit must count as changed again: %v", units)
	}
}

// AC-64: WordPress antwortet nicht mehr – der Rückweg führt über rescue.php.
func TestRollbackFallsBackToTheRescueScript(t *testing.T) {
	f, o, _ := pushed(t)
	f.rollback = 500

	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback rescue" {
		t.Errorf("routes = %s", got)
	}
	if f.rescueKey != RescueKey("secret", testID, testSalt) {
		t.Errorf("rescue key = %q", f.rescueKey)
	}
}

func TestRollbackKeepsARefusalOfTheAgent(t *testing.T) {
	f, o, siteDir := pushed(t)
	f.rollback = 409

	err := Rollback(o, testID)
	if err == nil || !strings.Contains(err.Error(), "abgelehnt") {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback" {
		t.Errorf("a refusal must not be bypassed through rescue.php: %s", got)
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1800000000 {
		t.Error("baseline changed although nothing was rolled back")
	}
}

func TestRollbackWithoutJournalStillRollsBackOnTheSite(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)

	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if !f.rolledBack || !strings.Contains(out.String(), "wpsync pull") {
		t.Errorf("rolledBack = %v\n%s", f.rolledBack, out)
	}
	if err := Rollback(o, "kaputt"); err == nil {
		t.Error("invalid push id accepted")
	}
}

func TestPushesPrintsTheLog(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)
	if err := Pushes(o); err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{testID, "bestätigt", "plugins/x", "mac"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output misses %q:\n%s", want, out)
		}
	}
}

// AC-68
func TestConfirmPending(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)
	if err := ConfirmPending(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "confirm" || !strings.Contains(out.String(), "wpsync pull") {
		t.Errorf("routes = %s\n%s", got, out)
	}
}

func TestPushesQuotesServerStrings(t *testing.T) {
	f := newFakeSite(t)
	f.list = `{"pushes":[{"push_id":"p_\u001b[2J","device":"mac\u001b[31m","target":"live","status":"odd\u0007","units":[{"path":"plugins/\u001b]0;x","files":1,"uploaded":1}],"created":1791158400}]}`
	o, _, out := localSite(t, f)
	if err := Pushes(o); err != nil {
		t.Fatal(err)
	}
	if strings.ContainsAny(out.String(), "\x1b\x07") {
		t.Errorf("raw control characters in the push log:\n%q", out)
	}
	for _, want := range []string{`"p_\x1b[2J"`, `"mac\x1b[31m"`, `"odd\a"`, `"plugins/\x1b]0;x"`} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("push log misses %s:\n%s", want, out)
		}
	}
}

// tamper rewrites the journal of testID the way code in a local container could.
func tamper(t *testing.T, siteDir string, change func(map[string]any)) {
	t.Helper()
	p := filepath.Join(siteDir, ".wpsync", "pushes", testID+".json")
	data, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	var j map[string]any
	if err := json.Unmarshal(data, &j); err != nil {
		t.Fatal(err)
	}
	change(j)
	data, _ = json.Marshal(j)
	if err := os.WriteFile(p, data, 0o600); err != nil {
		t.Fatal(err)
	}
}

// Das Journal liegt im Site-Ordner, den Container beschreiben können: der Rollback-Schlüssel
// geht nur an rescue.php der gekoppelten Site aus der Site-Konfiguration.
func TestRollbackChecksTheRescueURLOfTheJournal(t *testing.T) {
	leaked := false
	elsewhere := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		leaked = true
		w.Write([]byte(`{"ok":true}`))
	}))
	defer elsewhere.Close()

	for _, rescue := range []string{elsewhere.URL + "/rescue.php", "https" + strings.TrimPrefix(elsewhere.URL, "http") + "/rescue.php", "ftp://x/rescue.php", ""} {
		f, o, siteDir := pushed(t)
		f.rollback = 500
		tamper(t, siteDir, func(j map[string]any) { j["rescue_url"] = rescue })

		err := Rollback(o, testID)
		if err == nil || !strings.Contains(err.Error(), "Rescue-URL") || !strings.Contains(err.Error(), "Journal") {
			t.Errorf("rescue %q: err = %v", rescue, err)
		}
		if got := strings.Join(f.routes, " "); got != "rollback" {
			t.Errorf("rescue %q: routes = %s", rescue, got)
		}
	}
	if leaked {
		t.Error("the rollback key went to a host outside the paired site")
	}
}

// Auch Salt und Push-ID des Journals fliessen in den Aufruf von rescue.php; sie müssen das
// Format des Agenten haben und zum verlangten Push passen.
func TestRollbackRefusesATamperedJournal(t *testing.T) {
	cases := map[string]func(map[string]any){
		"salt":    func(j map[string]any) { j["salt"] = "x&action=ping" },
		"no salt": func(j map[string]any) { delete(j, "salt") },
		"push id": func(j map[string]any) { j["push_id"] = "p_20261005_ffffffffffff" },
	}
	for name, change := range cases {
		f, o, siteDir := pushed(t)
		f.rollback = 500
		tamper(t, siteDir, change)

		if err := Rollback(o, testID); err == nil || !strings.Contains(err.Error(), "Journal") {
			t.Errorf("%s: err = %v", name, err)
		}
		if got := strings.Join(f.routes, " "); got != "rollback" {
			t.Errorf("%s: rescue.php was called: %s", name, got)
		}
	}
}
