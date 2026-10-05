package push

import (
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
	if f.rescueKey != RescueKey("secret", testID, "salt") {
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
