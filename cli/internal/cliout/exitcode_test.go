package cliout

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"syscall"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/pull"
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
		ExitAuthFailed, ExitRateLimited, ExitLocalEnv, ExitDiskFull, ExitPostSetupFailed, ExitInterrupted} {
		if Names[code] == "" {
			t.Errorf("code %d has no name", code)
		}
	}
}
