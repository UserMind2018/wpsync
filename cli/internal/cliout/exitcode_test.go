package cliout

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"syscall"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/pull"
	"github.com/usermind/wpsync/internal/push"
	"github.com/usermind/wpsync/internal/safefs"
	"github.com/usermind/wpsync/internal/sitelock"
	"github.com/usermind/wpsync/internal/staging"
)

// Spec Server-Modus §9: je Exit-Code ein Test.
func TestExitCodes(t *testing.T) {
	cases := []struct {
		name string
		err  error
		exit int
	}{
		{"ok", nil, ExitOK},
		{"unknown", errors.New("irgendwas"), ExitUnknown},
		{"usage", Usage(errors.New("flag provided but not defined: -x")), ExitUsage},
		{"usage_uploads_since_container", fmt.Errorf("%w (Uploads ab 2024)", pull.ErrUploadsWithoutProxy), ExitUsage},
		{"usage_confirmation", fmt.Errorf("%w – mit --yes bestätigen", pull.ErrNeedsConfirmation), ExitUsage},
		{"agent_unreachable", fmt.Errorf("%w: discover https://x: no such host", agentapi.ErrUnreachable), ExitAgentUnreachable},
		{"agent_outdated", &agentapi.OutdatedError{Installed: "0.2.0", Required: "0.3.0", Err: pull.ErrAgentCannotAnonymize}, ExitAgentOutdated},
		{"agent_outdated_no_route", &agentapi.APIError{Status: 404, Code: "rest_no_route"}, ExitAgentOutdated},
		{"pair_rejected", &agentapi.APIError{Status: 403, Code: "wpsync_code", Message: "Pairing-Code ungültig oder abgelaufen."}, ExitPairRejected},
		{"auth_failed", &agentapi.APIError{Status: 401, Code: "wpsync_unpaired"}, ExitAuthFailed},
		{"auth_failed_signature", &agentapi.APIError{Status: 401, Code: "wpsync_auth"}, ExitAuthFailed},
		{"rate_limited", &agentapi.APIError{Status: 429, Code: "wpsync_busy"}, ExitRateLimited},
		{"rate_limited_ban", fmt.Errorf("%w: connection reset", agentapi.ErrSuspectedBan), ExitRateLimited},
		{"local_env", localenv.Wrap("exists", errors.New("container ws-dev-x fehlt")), ExitLocalEnv},
		{"local_env_pull_running", localenv.Wrap("lock", pull.ErrPullRunning), ExitLocalEnv},
		// Nach-Review N-b: unzulässige Werte der Quelle sind einheitlich unknown, auch aus Configure.
		{"invalid_env_unknown", fmt.Errorf("%w: php_version 8.3/x", agentapi.ErrInvalidEnv), ExitUnknown},
		{"invalid_env_from_configure_unknown", localenv.Wrap("configure", fmt.Errorf("%w: php_version 8.3/x", agentapi.ErrInvalidEnv)), ExitUnknown},
		{"invalid_table_name_unknown", fmt.Errorf("%w: refusing ../x", pull.ErrInvalidTableName), ExitUnknown},
		{"usage_reserved_docroot", fmt.Errorf("%w: .wpsync", pull.ErrInvalidDocroot), ExitUsage},
		{"disk_full", &fs.PathError{Op: "write", Path: "/x", Err: syscall.ENOSPC}, ExitDiskFull},
		{"disk_full_wins_over_local_env", localenv.Wrap("db import", &fs.PathError{Op: "write", Path: "/x", Err: syscall.ENOSPC}), ExitDiskFull},
		{"postsetup_failed", &pull.PostSetupError{Err: pull.ErrMailguardMissing}, ExitPostSetupFailed},
		{"interrupted", fmt.Errorf("%w (request: context canceled)", pull.ErrInterrupted), ExitInterrupted},
		{"interrupted_context", fmt.Errorf("request /x: %w", context.Canceled), ExitInterrupted},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := Classify(c.err)
			if f.Exit != c.exit || f.Code != Names[c.exit] {
				t.Fatalf("Classify(%v) = %d %s, want %d %s", c.err, f.Exit, f.Code, c.exit, Names[c.exit])
			}
		})
	}
}

func TestOutdatedNamesBothVersions(t *testing.T) {
	f := Classify(fmt.Errorf("pull: %w", &agentapi.OutdatedError{Installed: "0.2.0", Required: "0.3.0"}))
	if f.Installed != "0.2.0" || f.Required != "0.3.0" {
		t.Fatalf("failure = %+v", f)
	}
}

func TestHintKeepsTheExitCode(t *testing.T) {
	err := Hint(&agentapi.APIError{Status: 401, Code: "wpsync_unpaired"}, "Kopplung wurde auf der Site widerrufen")
	f := Classify(err)
	if f.Exit != ExitAuthFailed || f.Message != "Kopplung wurde auf der Site widerrufen" {
		t.Fatalf("failure = %+v", f)
	}
}

func TestEveryCodeHasAName(t *testing.T) {
	for _, code := range []int{ExitOK, ExitUnknown, ExitUsage, ExitAgentUnreachable, ExitAgentOutdated, ExitPairRejected,
		ExitAuthFailed, ExitRateLimited, ExitLocalEnv, ExitDiskFull, ExitPostSetupFailed, ExitInterrupted,
		ExitPushWindowClosed, ExitPushConflict, ExitPushPending, ExitPushRolledBack, ExitBusy,
		ExitStagingMissing, ExitStagingExists, ExitStagingUnsupported, ExitStagingLocked} {
		if Names[code] == "" {
			t.Errorf("code %d has no name", code)
		}
	}
}

type enospcReader struct{}

func (enospcReader) Read([]byte) (int, error) {
	return 0, &fs.PathError{Op: "write", Path: "/x", Err: syscall.ENOSPC}
}

// Review N1: der Fehler eines Datei-Schreibvorgangs im Pull (safefs.WriteFile, so wie
// pull.DownloadFiles ihn weiterreicht) wird als disk_full klassifiziert.
func TestFileWriteENOSPCIsDiskFull(t *testing.T) {
	root, err := os.OpenRoot(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	werr := safefs.WriteFile(root, "wp-content/a.txt", enospcReader{}, 10, time.Time{}, 0o644)
	err = fmt.Errorf("bundle with 1 files (first: wp-content/a.txt): %w", fmt.Errorf("wp-content/a.txt: %w", werr))
	if f := Classify(err); f.Exit != ExitDiskFull {
		t.Fatalf("Classify(%v) = %d, want %d", err, f.Exit, ExitDiskFull)
	}
}

// Das OS erkennt den belegten Site-Lock an error.reason, nicht am Meldungstext.
func TestSiteLockedHasAReason(t *testing.T) {
	f := Classify(localenv.Wrap("lock", pull.ErrPullRunning))
	if f.Exit != ExitLocalEnv || f.Reason != "site_locked" {
		t.Fatalf("failure = %+v", f)
	}
	if f := Classify(localenv.Wrap("container", errors.New("boom"))); f.Reason != "" {
		t.Fatalf("other local_env errors carry no reason: %+v", f)
	}
}

// Spec 2b 6.4, AC-104: je neuer Exit-Code ein Test.
func TestExitCodesPushAndStaging(t *testing.T) {
	api := func(status int, code string) error { return &agentapi.APIError{Status: status, Code: code} }
	cases := []struct {
		name string
		err  error
		exit int
	}{
		{"push_window_closed", push.ErrWindowClosed, ExitPushWindowClosed},
		{"push_window_rollback", fmt.Errorf("Push x: %w", push.ErrRollbackWindow), ExitPushWindowClosed},
		{"push_window_api", api(403, "wpsync_push_window"), ExitPushWindowClosed},
		{"push_conflict", Hint(push.ErrConflict, "erst pullen"), ExitPushConflict},
		{"push_conflict_api", api(409, "wpsync_push_conflict"), ExitPushConflict},
		{"push_pending", &push.PendingError{PushID: "p_x", Device: "mac"}, ExitPushPending},
		{"push_pending_api", api(409, "wpsync_push_pending"), ExitPushPending},
		{"push_pending_staging", api(409, "wpsync_staging_pending"), ExitPushPending},
		{"push_rolled_back", &push.RolledBackError{PushID: "p_x"}, ExitPushRolledBack},
		{"busy_push", api(423, "wpsync_push_locked"), ExitBusy},
		{"busy_staging_api", api(423, "wpsync_staging_busy"), ExitBusy},
		{"busy_staging", fmt.Errorf("%w: %w", staging.ErrBusy, api(423, "wpsync_staging_busy")), ExitBusy},
		{"busy_staging_resume", staging.ErrBusy, ExitBusy},
		{"disk_full_507", api(507, "wpsync_staging_space"), ExitDiskFull},
		{"disk_full_507_push", api(507, "wpsync_push_space"), ExitDiskFull},
		{"disk_full_job", fmt.Errorf("Datenbank voll: %w", syscall.ENOSPC), ExitDiskFull},
		{"staging_missing", fmt.Errorf("%w: %w", staging.ErrMissing, api(409, "wpsync_staging_missing")), ExitStagingMissing},
		{"staging_missing_api", api(409, "wpsync_staging_missing"), ExitStagingMissing},
		{"staging_exists", staging.ErrExists, ExitStagingExists},
		{"staging_exists_api", api(409, "wpsync_staging_exists"), ExitStagingExists},
		{"staging_unsupported", fmt.Errorf("%w: nginx", staging.ErrUnsupported), ExitStagingUnsupported},
		{"staging_unsupported_api", api(422, "wpsync_staging_unsupported"), ExitStagingUnsupported},
		{"staging_locked", staging.ErrLocked, ExitStagingLocked},
		{"staging_locked_api", api(409, "wpsync_staging_locked"), ExitStagingLocked},
		{"usage_push_needs_yes", push.ErrNeedsYes, ExitUsage},
		{"usage_staging_needs_yes", staging.ErrNeedsYes, ExitUsage},
		{"usage_push_target", fmt.Errorf("%w (prod)", push.ErrTarget), ExitUsage},
		{"agent_outdated_no_staging", push.ErrAgentNoStaging, ExitAgentOutdated},
		{"push_target_mismatch_unknown", &push.TargetError{PushID: "p_x", Is: "staging", Want: "live"}, ExitUnknown},
		{"push_staging_missing", fmt.Errorf("%w: %w", staging.ErrMissing, api(409, "wpsync_staging_missing")), ExitStagingMissing},
		// Ohne eigenen Code: unknown, wie ein abgelehnter Pull und unzulässige Werte der Quelle.
		{"staging_failed_unknown", fmt.Errorf("%w: boom", staging.ErrFailed), ExitUnknown},
		{"staging_failed_api_unknown", api(500, "wpsync_staging_failed"), ExitUnknown},
		{"staging_state_unknown", api(409, "wpsync_staging_state"), ExitUnknown},
		{"staging_copy_unknown", api(403, "wpsync_staging_copy"), ExitUnknown},
		{"staging_op_unknown", api(400, "wpsync_staging_op"), ExitUnknown},
		{"scope_unknown", api(400, "wpsync_scope"), ExitUnknown},
		{"foreign_url_unknown", fmt.Errorf("%w (Login-Link)", agentapi.ErrForeignURL), ExitUnknown},
		{"staging_aborted_unknown", staging.ErrAborted, ExitUnknown},
		{"push_aborted_unknown", push.ErrAborted, ExitUnknown},
		{"staging_no_profile_unknown", staging.ErrNoProfile, ExitUnknown},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := Classify(c.err)
			if f.Exit != c.exit || f.Code != Names[c.exit] || f.Code == "" {
				t.Errorf("Classify(%v) = %d %q, want %d %q", c.err, f.Exit, f.Code, c.exit, Names[c.exit])
			}
		})
	}
}

// Vertrag mit dem OS: Nummern und Namen sind fest. Eine neue Nummer ist eine Vertragsänderung.
func TestExitCodeContract(t *testing.T) {
	want := map[int]string{
		0: "ok", 1: "unknown", 2: "usage",
		10: "agent_unreachable", 11: "agent_outdated", 12: "pair_rejected", 13: "auth_failed", 14: "rate_limited",
		20: "local_env", 21: "disk_full", 22: "postsetup_failed", 30: "interrupted",
		40: "push_window_closed", 41: "push_conflict", 42: "push_pending", 43: "push_rolled_back", 44: "busy",
		50: "staging_missing", 51: "staging_exists", 52: "staging_unsupported", 53: "staging_locked",
	}
	if len(Names) != len(want) {
		t.Errorf("Names has %d codes, want %d", len(Names), len(want))
	}
	for code, name := range want {
		if Names[code] != name {
			t.Errorf("Names[%d] = %q, want %q", code, Names[code], name)
		}
	}
}

// Die lokale Site-Sperre (pull, scan, push, rollback) bleibt local_env mit reason site_locked.
// busy (44) entsteht nur aus HTTP 423 des Agents oder staging.ErrBusy.
func TestLocalSiteLockIsNeverBusy(t *testing.T) {
	for name, err := range map[string]error{
		"pull":      localenv.Wrap("lock", pull.ErrPullRunning),
		"push":      localenv.Wrap("lock", sitelock.ErrBusy),
		"wrapped":   fmt.Errorf("push: %w", localenv.Wrap("lock", sitelock.ErrBusy)),
		"unwrapped": sitelock.ErrBusy,
	} {
		f := Classify(err)
		if f.Exit == ExitBusy || f.Code == "busy" || f.Reason != "site_locked" {
			t.Errorf("%s: failure = %+v", name, f)
		}
		if name != "unwrapped" && (f.Exit != ExitLocalEnv || f.Code != "local_env") {
			t.Errorf("%s: failure = %+v, want local_env", name, f)
		}
	}
	for name, err := range map[string]error{
		"agent_423":   &agentapi.APIError{Status: 423, Code: "wpsync_push_locked"},
		"staging_job": fmt.Errorf("%w: %w", staging.ErrBusy, &agentapi.APIError{Status: 423, Code: "wpsync_staging_busy"}),
	} {
		if f := Classify(err); f.Exit != ExitBusy || f.Reason != "" {
			t.Errorf("%s: failure = %+v, want busy without reason", name, f)
		}
	}
}

// Fälle ohne eigenen Exit-Code bleiben unknown und sind an error.reason zu erkennen.
func TestUnknownStagingCasesHaveAReason(t *testing.T) {
	cases := []struct {
		err    error
		reason string
	}{
		{fmt.Errorf("%w: boom", staging.ErrFailed), "staging_failed"},
		{&agentapi.APIError{Status: 500, Code: "wpsync_staging_failed"}, "staging_failed"},
		{&agentapi.APIError{Status: 409, Code: "wpsync_staging_state"}, "staging_state"},
		{&agentapi.APIError{Status: 403, Code: "wpsync_staging_copy"}, "staging_copy"},
		{fmt.Errorf("%w: https://x.example", agentapi.ErrForeignURL), "foreign_url"},
		{errors.New("irgendwas"), ""},
		{&agentapi.APIError{Status: 409, Code: "wpsync_staging_missing"}, ""},
	}
	for _, c := range cases {
		if f := Classify(c.err); f.Reason != c.reason {
			t.Errorf("Classify(%v).Reason = %q, want %q", c.err, f.Reason, c.reason)
		}
	}
}
