package scan

import (
	"testing"

	"github.com/usermind/wpsync/internal/profile"
)

// --uploads-since alle: alle Upload-Jahre ziehen – Pflicht im Container-Modus ohne Uploads-Proxy.
func TestAdjustAllUploadsClearsSince(t *testing.T) {
	p := &profile.Profile{Uploads: profile.Uploads{Since: "2024", Proxy: true}}
	Adjust{AllUploads: true}.apply(p)
	if p.Uploads.Since != "" {
		t.Fatalf("since = %q", p.Uploads.Since)
	}
}
