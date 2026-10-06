package main

import (
	"context"
	"fmt"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/cliout"
	"github.com/usermind/wpsync/internal/push"
	"github.com/usermind/wpsync/internal/sites"
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

// Push bleibt ein Mac-Befehl: kein --json und kein Container-Treiber (Exit 2 usage, Text auf stderr).
func TestPushHasNoServerMode(t *testing.T) {
	env(t)
	for _, args := range [][]string{
		{"push", "kunde", "code", "--json"},
		{"push", "kunde", "code", "--driver", "container"},
		{"pushes", "kunde", "--secret-stdin"},
		{"rollback"},
	} {
		r := run(t, context.Background(), "", args...)
		if r.code != cliout.ExitUsage || r.stdout != "" || !strings.Contains(r.stderr, "✗") {
			t.Errorf("%v: exit %d\nstdout: %s\nstderr: %s", args, r.code, r.stdout, r.stderr)
		}
	}
}
