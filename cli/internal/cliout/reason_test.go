package cliout

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/push"
)

// Spec Container-Push C11, AC-126: Exit-1-Fälle des Pushs ohne eigenen Code tragen error.reason.
func TestPushCasesHaveAReason(t *testing.T) {
	cases := []struct {
		err    error
		reason string
	}{
		{push.ErrNothing, "nothing_to_push"},
		{Hint(&push.SkippedNewError{Units: []string{"plugins/neu"}}, "nichts gepusht"), "nothing_to_push"},
		{Hint(push.ErrNotWritable, "Rechte prüfen"), "not_writable"},
		{fmt.Errorf("%w: dial tcp", push.ErrRescueUnreachable), "rescue_unreachable"},
		{fmt.Errorf("plugins/x/a.php %w", push.ErrChanged), "local_changed"},
		{fmt.Errorf("Upload abgebrochen: %w", fmt.Errorf("plugins/x/a.php %w", push.ErrChanged)), "local_changed"},
		{fmt.Errorf("public/wp-content/plugins %w", push.ErrSymlink), "local_changed"},
		{&push.TargetError{PushID: "p_x", Is: "staging", Want: "live"}, "target_mismatch"},
		{fmt.Errorf("%w: angefordert Live", push.ErrTargetMismatch), "target_mismatch"},
		{&push.UnreadableError{Path: "wp-content/plugins/x/a.php"}, "not_readable"},
	}
	for _, c := range cases {
		f := Classify(c.err)
		if f.Exit != ExitUnknown || f.Reason != c.reason {
			t.Errorf("Classify(%v) = %d %q, want 1 %q", c.err, f.Exit, f.Reason, c.reason)
		}
	}
	// Nur bei Exit 1: ein Konflikt bleibt 41 ohne reason.
	if f := Classify(push.ErrConflict); f.Reason != "" {
		t.Errorf("conflict: %+v", f)
	}
}

// P-O3, AC-130: not_readable nennt den Pfad relativ zum Docroot.
func TestNotReadableNamesThePath(t *testing.T) {
	f := Classify(&push.UnreadableError{Path: "wp-content/themes/t/cache/x.php"})
	if f.Reason != "not_readable" || f.Path != "wp-content/themes/t/cache/x.php" {
		t.Fatalf("failure = %+v", f)
	}
	if f := Classify(errors.New("irgendwas")); f.Path != "" {
		t.Fatalf("path without not_readable: %+v", f)
	}
}

// C8, AC-120: Exit 40 nennt admin_url und – wenn bekannt – device; Meldung und Code bleiben.
func TestWindowErrorNamesDeviceAndAdminPage(t *testing.T) {
	for _, inner := range []error{push.ErrWindowClosed, push.ErrRollbackWindow, &agentapi.APIError{Status: 403, Code: "wpsync_push_window", Message: "zu"}} {
		err := &WindowError{Err: Hint(inner, "Fenster zu"), Device: "agentic-os-dev", AdminURL: "https://kunde.example/wp-admin/tools.php?page=wpsync"}
		f := Classify(err)
		if f.Exit != ExitPushWindowClosed || f.Message != "Fenster zu" || f.Device != "agentic-os-dev" || f.AdminURL != "https://kunde.example/wp-admin/tools.php?page=wpsync" {
			t.Errorf("failure = %+v", f)
		}
	}
	f := Classify(&WindowError{Err: push.ErrWindowClosed, AdminURL: "https://kunde.example/wp-admin/tools.php?page=wpsync"})
	data, _ := json.Marshal(f)
	if strings.Contains(string(data), `"device"`) || !strings.Contains(string(data), `"admin_url"`) {
		t.Errorf("unknown device must be missing: %s", data)
	}
	// Nur bei Exit 40.
	if f := Classify(&WindowError{Err: push.ErrConflict, Device: "x", AdminURL: "y"}); f.Device != "" || f.AdminURL != "" {
		t.Errorf("failure = %+v", f)
	}
}

// AC-127: ohne die neuen Fälle bleibt das Fehlerobjekt feldweise wie in 0.4.0.
func TestFailureJSONUnchangedForOldCases(t *testing.T) {
	data, _ := json.Marshal(Classify(push.ErrConflict))
	if string(data) != `{"code":"push_conflict","message":"auf dem Server geändert seit dem letzten Pull"}` {
		t.Errorf("failure = %s", data)
	}
}

// Grill 2026-10-07: läuft die Zeit nach SIGTERM im /push/commit ab, ist der Stand unklar – Exit 42,
// nicht 30 („nichts getauscht“), auch wenn der Fehler des Requests context.Canceled war.
func TestUnsettledCommitAfterSIGTERMIsPending(t *testing.T) {
	err := fmt.Errorf("Zeit nach SIGTERM abgelaufen: %w (%v)", &push.PendingError{PushID: "p_x", Device: "diesem Gerät"}, context.Canceled)
	if f := Classify(err); f.Exit != ExitPushPending {
		t.Errorf("Classify = %+v, want exit 42", f)
	}
}
