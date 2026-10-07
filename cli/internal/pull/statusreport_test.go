package pull

import (
	"encoding/json"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/sites"
)

// status --json: vor dem ersten Pull leere Listen statt null, pulled=false.
func TestStatusReportBeforeFirstPull(t *testing.T) {
	res, err := StatusReport(Options{Site: sites.Site{Name: "kunde", URL: "https://kunde.de", Profile: &profile.Profile{}}, SitesRoot: t.TempDir(), Out: io.Discard})
	if err != nil {
		t.Fatal(err)
	}
	if res.Pulled || res.LastPull != nil || res.Source != "https://kunde.de" || res.FilesChanged == nil || res.FilesDeleted == nil || res.Tables == nil {
		t.Fatalf("report = %+v", res)
	}
}

// B3: pull --dry-run meldet "dry_run" und pulled=false, auch wenn die Site schon einmal gezogen wurde.
func TestMarkDryRun(t *testing.T) {
	now := time.Now()
	res := &StatusResult{Pulled: true, LastPull: &now}
	res.MarkDryRun()
	if res.Pulled || res.Status != "dry_run" || res.LastPull == nil {
		t.Fatalf("res = %+v", res)
	}
	b, _ := json.Marshal(&StatusResult{})
	if strings.Contains(string(b), `"status"`) {
		t.Fatalf("status without dry run must not appear: %s", b)
	}
}
