package cliout

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"reflect"
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
		{&push.RescueBlockedError{Plugins: []string{"better-wp-security"}, Err: fmt.Errorf("%w: HTTP 403", push.ErrRescueUnreachable)}, "rescue_unreachable"},
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

// Spec Content-Push §8, §10: die Uploads-Fälle bei Exit 1, Exit 11 für einen Agent ohne Uploads.
func TestUploadCasesHaveAReason(t *testing.T) {
	cases := []struct {
		err    error
		reason string
	}{
		{&push.UploadExistsError{Paths: []string{"2026/10/a.png"}}, "upload_exists"},
		{fmt.Errorf("%w: %w", push.ErrUploadExists, &agentapi.APIError{Status: 409, Code: "wpsync_upload_exists"}), "upload_exists"},
		{&agentapi.APIError{Status: 409, Code: "wpsync_upload_exists"}, "upload_exists"},
		{&push.UploadTypeError{Path: "2026/10/x.php"}, "upload_type_blocked"},
		{&agentapi.APIError{Status: 400, Code: "wpsync_upload_type_blocked"}, "upload_type_blocked"},
		{push.ErrUploadsThere, "nothing_to_push"},
	}
	for _, c := range cases {
		f := Classify(c.err)
		if f.Exit != ExitUnknown || f.Reason != c.reason {
			t.Errorf("Classify(%v) = %d %q, want 1 %q", c.err, f.Exit, f.Reason, c.reason)
		}
	}
	if f := Classify(fmt.Errorf("%w: %w", push.ErrAgentNoUploads, &agentapi.APIError{Status: 400, Code: "wpsync_push_unit"})); f.Exit != ExitAgentOutdated {
		t.Errorf("no uploads: %+v", f)
	}
}

// S7: bei nothing_to_push nennt das Fehlerobjekt die übersprungenen neuen Einheiten.
func TestNothingToPushNamesTheSkippedUnits(t *testing.T) {
	data, _ := json.Marshal(Classify(Hint(&push.SkippedNewError{Units: []string{"plugins/neu", "themes/neu"}}, "nichts gepusht")))
	if !strings.Contains(string(data), `"reason":"nothing_to_push","skipped_new":["plugins/neu","themes/neu"]`) {
		t.Errorf("failure = %s", data)
	}
	data, _ = json.Marshal(Classify(push.ErrNothing))
	if strings.Contains(string(data), "skipped_new") {
		t.Errorf("without skipped units the field is left out: %s", data)
	}
}

// Spec Content-Push §10: a refusal of the content of a push is exit 1 with the agent's reason,
// the rows as keys and, for upload_missing, the files – whether the agent or the CLI found it.
func TestContentRefusalsHaveReasonKeysAndPaths(t *testing.T) {
	keys := []agentapi.ContentKey{{Table: "posts", Key: "219"}, {Table: "postmeta", Key: "219\x00_x", Pattern: "email"}}
	for _, reason := range []string{"package_invalid", "baseline_outdated", "origin_mismatch", "package_too_large", "engine_unsupported",
		"blocked_row", "list_version_mismatch", "local_origin_in_package", "pseudonym_in_package", "id_outside_corridor", "id_taken", "id_has_leftovers",
		"conflict", "row_unfaithful", "dangling_reference", "upload_missing", "author_unknown", "changed_since_push", "unsafe_value",
		"write_mismatch", "package_missing", "content_failed"} {
		api := &agentapi.APIError{Status: 409, Code: "wpsync_content_" + reason, Message: "abgelehnt"}
		f := Classify(fmt.Errorf("push: %w", &push.ContentError{Reason: reason, Message: "abgelehnt", Keys: keys, Paths: []string{"2026/10/a.jpg"}, Err: api}))
		if f.Exit != ExitUnknown || f.Reason != reason || len(f.Keys) != 2 || f.Keys[1].Pattern != "email" || len(f.Paths) != 1 {
			t.Errorf("%s: %+v", reason, f)
		}
	}
	out, _ := json.Marshal(Classify(&push.ContentError{Reason: "conflict", Message: "geändert", Keys: keys}))
	if !strings.Contains(string(out), `"keys":[{"table":"posts","key":"219"},{"table":"postmeta","key":"219\u0000_x","pattern":"email"}]`) {
		t.Errorf("json = %s", out)
	}
	if f := Classify(push.ErrAgentNoContent); f.Exit != ExitAgentOutdated {
		t.Errorf("agent without the content channel: %+v", f)
	}
	if out, _ := json.Marshal(Classify(push.ErrConflict)); strings.Contains(string(out), "keys") || strings.Contains(string(out), "paths") {
		t.Errorf("other failures carry no keys: %s", out)
	}
}

// Spec Content-Push P3 §9: --require-rescue-db – Exit 1, reason rescue_db_unavailable, detail der Grund des Agents.
func TestRequireRescueDBNamesReasonAndDetail(t *testing.T) {
	for _, reason := range []string{"no_crypto", "driver", "no_image_key", "probe_failed", "write_failed", "agent_outdated"} {
		f := Classify(fmt.Errorf("Push p_x nicht getauscht: %w", &push.RescueDBError{Reason: reason}))
		if f.Exit != ExitUnknown || f.Reason != "rescue_db_unavailable" || f.Detail != reason {
			t.Errorf("%s: failure = %+v", reason, f)
		}
	}
	// No other failure carries a detail.
	if f := Classify(push.ErrNothing); f.Detail != "" {
		t.Errorf("detail = %q", f.Detail)
	}
}

// Spec Content-Push P4 §4.5: eine Ablehnung des Plugin-Zustands ist Exit 1 mit reason und den Einheiten –
// ein falscher Schalter Exit 2, ein Agent unter 0.9.0 Exit 11.
func TestPluginRefusalsHaveReasonAndUnits(t *testing.T) {
	refused := []agentapi.PluginRefusal{{Unit: "plugins/kunde", Why: "requires_php", Needs: "8.2", Has: "8.0"}}
	for _, reason := range []string{"plugins_invalid", "plugins_requirements", "plugins_not_allowed", "plugins_unsupported", "plugins_failed"} {
		f := Classify(fmt.Errorf("Push nicht begonnen: %w", &push.PluginsError{Reason: reason, Message: "abgelehnt", Plugins: refused,
			Err: &agentapi.APIError{Status: 409, Code: "wpsync_" + reason}}))
		if f.Exit != ExitUnknown || f.Reason != reason || !reflect.DeepEqual(f.Plugins, refused) || f.Detail != "" {
			t.Errorf("%s: failure = %+v", reason, f)
		}
	}
	f := Classify(&push.PluginsError{Reason: "rescue_db_unavailable", Detail: "probe_failed"})
	if f.Exit != ExitUnknown || f.Reason != "rescue_db_unavailable" || f.Detail != "probe_failed" || f.Plugins != nil {
		t.Errorf("failure = %+v", f)
	}
	f = Classify(&push.RescueDBError{Reason: "no_image_key", Mandatory: true})
	if f.Exit != ExitUnknown || f.Reason != "rescue_db_unavailable" || f.Detail != "no_image_key" {
		t.Errorf("failure = %+v", f)
	}
	if f := Classify(fmt.Errorf("%w: --activate \"themes/x\"", push.ErrPluginSwitch)); f.Exit != ExitUsage || f.Reason != "" {
		t.Errorf("failure = %+v", f)
	}
	if f := Classify(fmt.Errorf("%w: --activate plugins/l – %w", push.ErrPluginSwitch, push.ErrSymlink)); f.Exit != ExitUsage {
		t.Errorf("failure = %+v", f)
	}
	if f := Classify(push.ErrAgentNoPlugins); f.Exit != ExitAgentOutdated {
		t.Errorf("failure = %+v", f)
	}
	// Kein anderer Fehler trägt plugins.
	if f := Classify(push.ErrNothing); f.Plugins != nil {
		t.Errorf("plugins = %v", f.Plugins)
	}
	raw, _ := json.Marshal(Classify(&push.PluginsError{Reason: "plugins_requirements", Plugins: refused}))
	if !strings.Contains(string(raw), `"plugins":[{"unit":"plugins/kunde","why":"requires_php","needs":"8.2","has":"8.0"}]`) {
		t.Errorf("json = %s", raw)
	}
}

// Eine Ablehnung der Inhalte reicht durch, was der Agent über keys und paths hinaus nennt: total
// (wie viele Schlüssel es sind – keys trägt höchstens 200), state_bytes und tables.
func TestContentRefusalsCarryTotalStateBytesAndTables(t *testing.T) {
	keys := []agentapi.ContentKey{{Table: "posts", Key: "219"}}
	f := Classify(fmt.Errorf("push: %w", &push.ContentError{Reason: "conflict", Message: "geändert", Keys: keys, Total: 731}))
	if f.Total != 731 || f.StateBytes != 0 || f.Tables != nil {
		t.Errorf("failure = %+v", f)
	}
	raw, _ := json.Marshal(f)
	if !strings.Contains(string(raw), `"total":731`) || strings.Contains(string(raw), "state_bytes") || strings.Contains(string(raw), "tables") {
		t.Errorf("json = %s", raw)
	}
	raw, _ = json.Marshal(Classify(&push.ContentError{Reason: "package_too_large", StateBytes: 73400320}))
	if !strings.Contains(string(raw), `"state_bytes":73400320`) || strings.Contains(string(raw), `"total"`) {
		t.Errorf("json = %s", raw)
	}
	raw, _ = json.Marshal(Classify(&push.ContentError{Reason: "engine_unsupported", Tables: []string{"options", "postmeta"}}))
	if !strings.Contains(string(raw), `"tables":["options","postmeta"]`) {
		t.Errorf("json = %s", raw)
	}
	// Kein anderer Fehler trägt die Felder.
	raw, _ = json.Marshal(Classify(push.ErrNothing))
	for _, field := range []string{"total", "state_bytes", "tables"} {
		if strings.Contains(string(raw), field) {
			t.Errorf("json = %s", raw)
		}
	}
}
