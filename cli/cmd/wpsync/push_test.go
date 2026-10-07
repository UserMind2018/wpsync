package main

import (
	"fmt"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/push"
	"github.com/usermind/wpsync/internal/sites"
	"github.com/usermind/wpsync/internal/staging"
)

// F7 gilt auch für push, pushes und rollback: der Hinweis ersetzt nur den Text, der Exit-Code
// folgt dem ursprünglichen Fehler.
func TestPushErrorKeepsExitCode(t *testing.T) {
	site := &sites.Site{Name: "kunde", URL: "https://kunde.example"}
	cases := []struct {
		name string
		err  error
		exit int
		text string
	}{
		{"agent_too_old", push.ErrAgentTooOld, cliout.ExitAgentOutdated, "kann noch nicht pushen – Agent 0.4.0 installieren"},
		{"no_route", &agentapi.APIError{Status: 404, Code: "rest_no_route"}, cliout.ExitAgentOutdated, "kann noch nicht pushen"},
		{"version_change", push.ErrVersionChange, cliout.ExitUsage, "--yes --allow-version-change"},
		{"no_baseline", push.ErrNoBaseline, cliout.ExitUsage, "zuerst wpsync pull kunde"},
		{"unpaired", &agentapi.APIError{Status: 401, Code: "wpsync_unpaired"}, cliout.ExitAuthFailed, "neu koppeln"},
		{"unreachable", fmt.Errorf("%w: request /wpsync/v1/push-begin: dial tcp", agentapi.ErrUnreachable), cliout.ExitAgentUnreachable, "nicht erreichbar"},
		{"window_closed", push.ErrWindowClosed, cliout.ExitPushWindowClosed, "Push-Fenster ist geschlossen"},
		{"rollback_window", push.ErrRollbackWindow, cliout.ExitPushWindowClosed, "Push-Fenster"},
		{"conflict", push.ErrConflict, cliout.ExitPushConflict, "zuerst wpsync pull kunde"},
		{"pending", &push.PendingError{PushID: "p_x", Device: "mac"}, cliout.ExitPushPending, "wpsync rollback kunde"},
		{"rolled_back", &push.RolledBackError{PushID: "p_x", Reasons: []string{"HTTP 500"}}, cliout.ExitPushRolledBack, "wieder auf dem alten Stand"},
		{"busy", &agentapi.APIError{Status: 423, Code: "wpsync_push_locked", Message: "Auf dieser Site läuft bereits ein Push."}, cliout.ExitBusy, "läuft bereits ein Push"},
		{"needs_yes", push.ErrNeedsYes, cliout.ExitUsage, "mit --yes bestätigen"},
		{"target", fmt.Errorf("%w (%q)", push.ErrTarget, "stagin"), cliout.ExitUsage, "erlaubt sind live und staging"},
		{"no_staging", fmt.Errorf("%w: %w", push.ErrAgentNoStaging, &agentapi.APIError{Status: 400, Code: "wpsync_push_target"}), cliout.ExitAgentOutdated, "kennt noch kein Staging – Agent 0.5.0 installieren"},
		{"staging_missing", fmt.Errorf("%w: %w", staging.ErrMissing, &agentapi.APIError{Status: 409, Code: "wpsync_staging_missing"}), cliout.ExitStagingMissing, "wpsync staging create kunde"},
		{"staging_locked", fmt.Errorf("%w: %w", staging.ErrLocked, &agentapi.APIError{Status: 409, Code: "wpsync_staging_locked"}), cliout.ExitStagingLocked, "wpsync staging open kunde"},
		{"staging_busy", fmt.Errorf("%w: %w", staging.ErrBusy, &agentapi.APIError{Status: 423, Code: "wpsync_staging_busy"}), cliout.ExitBusy, "wpsync staging status kunde"},
		{"staging_state", &agentapi.APIError{Status: 409, Code: "wpsync_staging_state", Message: "Die Staging-Kopie ist im Status failed."}, cliout.ExitUnknown, "im Status failed"},
		{"other_target", &push.TargetError{PushID: "p_20261005_0123456789ab", Is: "staging", Want: "live"}, cliout.ExitUnknown, "ging nach Staging, nicht nach Live"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			err := pushError(c.err, site)
			if got := cliout.Classify(err).Exit; got != c.exit || !strings.Contains(err.Error(), c.text) {
				t.Fatalf("exit = %d, message = %q, want %d with %q", got, err, c.exit, c.text)
			}
		})
	}
}
