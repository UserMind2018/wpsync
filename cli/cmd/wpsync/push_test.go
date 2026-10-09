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
		{"no_uploads", fmt.Errorf("%w: %w", push.ErrAgentNoUploads, &agentapi.APIError{Status: 400, Code: "wpsync_push_unit"}), cliout.ExitAgentOutdated, "kennt noch keine Uploads – Agent 0.6.0 installieren"},
		{"upload_exists", &push.UploadExistsError{Paths: []string{"2026/10/a.png"}}, cliout.ExitUnknown, "ersetzt nie eine Datei unter uploads"},
		{"upload_type", &push.UploadTypeError{Path: "2026/10/x.php"}, cliout.ExitUnknown, "gehen nie als Upload auf die Site"},
		{"upload_type_agent", fmt.Errorf("%w: %w", push.ErrUploadTypeBlocked, &agentapi.APIError{Status: 400, Code: "wpsync_upload_type_blocked", Message: "Inhalt passt nicht zum Dateityp: \"2026/10/a.png\""}), cliout.ExitUnknown, "Inhalt passt nicht zum Dateityp"},
		{"upload_missing", fmt.Errorf("wp-content/uploads/2026/10/a.png %w", push.ErrUploadMissing), cliout.ExitUsage, "relativ zu wp-content/uploads/"},
		{"uploads_there", push.ErrUploadsThere, cliout.ExitUnknown, "liegen schon auf der Site"},
		{"upload_layout", &agentapi.APIError{Status: 409, Code: "wpsync_upload_layout", Message: "wp-content/uploads ist ein symbolischer Link"}, cliout.ExitUnknown, "symbolischer Link"},
		{"no_content", fmt.Errorf("%w: %w", push.ErrAgentNoContent, &agentapi.APIError{Status: 404, Code: "rest_no_route"}), cliout.ExitAgentOutdated, "kann noch keine Inhalte pushen – Agent 0.7.0 installieren"},
		{"content_conflict", &push.ContentError{Reason: "conflict", Message: "Auf dem Ziel seit dem Pull geändert"}, cliout.ExitUnknown, "wpsync pull kunde --content"},
		{"content_blocked", &push.ContentError{Reason: "blocked_row", Message: "1 Zeile(n) stehen auf der Sperrliste"}, cliout.ExitUnknown, "nichts wurde übertragen"},
		{"content_author", &push.ContentError{Reason: "author_unknown", Message: "Neue Beiträge brauchen einen Autor"}, cliout.ExitUnknown, "wp-admin/tools.php?page=wpsync"},
		{"content_changed", fmt.Errorf("ROLLBACK NICHT MÖGLICH: %w", &push.ContentError{Reason: "changed_since_push", Message: "Seit dem Push geändert"}), cliout.ExitUnknown, "auch Code und Uploads nicht"},
		{"content_unrestored", &push.ContentError{Reason: "content_failed", Message: "die Zeile posts 219 liess sich nicht zurücksetzen", Keys: []agentapi.ContentKey{{Table: "posts", Key: "219"}}}, cliout.ExitUnknown, "von Hand prüfen"},
		{"content_failed", &push.ContentError{Reason: "content_failed", Message: "Die Datenbank hat einen Schreibzugriff abgelehnt"}, cliout.ExitUnknown, "nichts wurde übertragen"},
		{"content_image", fmt.Errorf("ROLLBACK NICHT MÖGLICH: %w", &push.ContentError{Reason: "before_image_invalid", Message: "Das Vorher-Abbild lässt sich nicht öffnen"}), cliout.ExitUnknown, "WPSYNC_KEY oder die Salts"},
		{"content_stage", &agentapi.APIError{Status: 409, Code: "wpsync_content_offset", Message: "Stück passt nicht an das Paket."}, cliout.ExitUnknown, "Stück passt nicht"},
		{"content_left", &push.RolledBackError{PushID: "p_20261005_0123456789ab", Reasons: []string{"HTTP 500"}, Warnings: []string{push.WarningContentNotRolledBack}}, cliout.ExitPushRolledBack, "wpsync rollback kunde p_20261005_0123456789ab"},
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
