package push

import (
	"errors"
	"fmt"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localgit"
)

// AC-116, C3: scheitert der Schnappschuss nach einem bestätigten Push, endet push mit Erfolg und
// der Warnung snapshot_failed – Baseline und Journal sind geschrieben. Auch ohne git im PATH.
func TestFailedSnapshotIsAWarningAfterAConfirmedPush(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	o, studio := containerLayout(t, o, siteDir)
	o.Commit = nil
	t.Setenv("PATH", "")
	var res Result
	o.Report = &res
	if err := Run(o); err != nil {
		t.Fatalf("a confirmed push with a failed snapshot must succeed: %v\n%s", err, out)
	}
	if res.Status != "confirmed" || len(res.Warnings) != 1 || res.Warnings[0] != WarningSnapshotFailed {
		t.Errorf("result = %+v", res)
	}
	if !strings.Contains(out.String(), "Schnappschuss im lokalen Git fehlgeschlagen") {
		t.Errorf("output:\n%s", out)
	}
	base, _ := baseline.Load(studio)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1800000000 {
		t.Errorf("baseline not written: %v", base.Files)
	}
	if j, err := LoadJournal(studio, testID); err != nil || !j.Applied {
		t.Errorf("journal = %+v, %v", j, err)
	}
}

// C3 gilt auch für rollback und auf dem Mac.
func TestFailedSnapshotIsAWarningAfterARollback(t *testing.T) {
	_, o, siteDir := pushed(t)
	o.Commit = func(string, string) error { return errors.New("git kaputt") }
	var res Result
	o.Report = &res
	if err := Rollback(o, testID); err != nil {
		t.Fatalf("rollback with a failed snapshot must succeed: %v", err)
	}
	if res.Status != "rolled_back" || len(res.Warnings) != 1 || res.Warnings[0] != WarningSnapshotFailed {
		t.Errorf("result = %+v", res)
	}
	if j, _ := LoadJournal(siteDir, testID); j.Applied {
		t.Error("journal still applied")
	}
}

// Q5 (Grill 2026-10-07): ein Schnappschuss mit fehlenden Dateien ist gespeichert – Warnung
// snapshot_incomplete statt snapshot_failed, ohne die Meldung „fehlgeschlagen“. Push und Rollback.
func TestIncompleteSnapshotIsAWarning(t *testing.T) {
	incomplete := func(string, string) error { return fmt.Errorf("%w: 1 missing", localgit.ErrIncomplete) }
	t.Run("push", func(t *testing.T) {
		f := newFakeSite(t)
		o, _, out := localSite(t, f)
		o.Commit = incomplete
		var res Result
		o.Report = &res
		if err := Run(o); err != nil {
			t.Fatalf("%v\n%s", err, out)
		}
		if len(res.Warnings) != 1 || res.Warnings[0] != WarningSnapshotIncomplete {
			t.Errorf("result = %+v", res)
		}
		if strings.Contains(out.String(), "fehlgeschlagen") {
			t.Errorf("output:\n%s", out)
		}
	})
	t.Run("rollback", func(t *testing.T) {
		_, o, _ := pushed(t)
		o.Commit = incomplete
		var res Result
		o.Report = &res
		if err := Rollback(o, testID); err != nil {
			t.Fatal(err)
		}
		if len(res.Warnings) != 1 || res.Warnings[0] != WarningSnapshotIncomplete {
			t.Errorf("result = %+v", res)
		}
	})
}

// Ohne Fehler kein Feld: das JSON von 0.4.0 bleibt gleich (AC-127).
func TestNoWarningsWithoutAFailure(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)
	var res Result
	o.Report = &res
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if res.Warnings != nil {
		t.Errorf("warnings = %v", res.Warnings)
	}
}
