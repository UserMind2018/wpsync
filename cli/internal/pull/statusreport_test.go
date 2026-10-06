package pull

import (
	"io"
	"testing"

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
