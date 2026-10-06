package sites

import (
	"os"
	"reflect"
	"testing"

	"github.com/usermind/wpsync/internal/profile"
)

func TestNameFromURL(t *testing.T) {
	cases := map[string]string{
		"https://www.example.com":                 "example-com",
		"https://staging.kunde.example.org/":      "staging-kunde-example-org",
		"http://wpsync-e2e-source.ddev.site:8480": "wpsync-e2e-source-ddev-site",
	}
	for in, want := range cases {
		got, err := NameFromURL(in)
		if err != nil || got != want {
			t.Errorf("NameFromURL(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
}

func TestValidName(t *testing.T) {
	if !ValidName("kunde-1") || ValidName("Kunde") || ValidName("-x") || ValidName("a/b") {
		t.Fatal("ValidName rules broken")
	}
}

func TestSaveLoadListDelete(t *testing.T) {
	t.Setenv("WPSYNC_CONFIG_DIR", t.TempDir())
	s := &Site{Name: "kunde", URL: "https://kunde.de", KeyID: "0123456789abcdef", RPS: 1}
	if err := Save(s); err != nil {
		t.Fatal(err)
	}
	got, err := Load("kunde")
	if err != nil || !reflect.DeepEqual(got, s) {
		t.Fatalf("Load = %+v, %v", got, err)
	}
	info, _ := os.Stat(mustPath(t, "kunde"))
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("perm = %v, want 0600", info.Mode().Perm())
	}
	list, _ := List()
	if len(list) != 1 || list[0].Name != "kunde" {
		t.Fatalf("List = %+v", list)
	}
	if err := Delete("kunde"); err != nil {
		t.Fatal(err)
	}
	if _, err := Load("kunde"); err == nil {
		t.Fatal("expected error after delete")
	}
}

func TestProfileRoundTrip(t *testing.T) {
	t.Setenv("WPSYNC_CONFIG_DIR", t.TempDir())
	s := &Site{Name: "kunde", URL: "https://kunde.de", KeyID: "0123456789abcdef", RPS: 1, Profile: &profile.Profile{
		Preset:    profile.PresetNoTransactions,
		Tables:    profile.Tables{Overrides: map[string]string{"wp_comments": profile.ModeFull}},
		PostTypes: profile.Choice{Exclude: []string{"page"}},
		Uploads:   profile.Uploads{Since: "2025", Proxy: true},
		Seen:      profile.Seen{Tables: []string{"wp_comments", "wp_posts"}},
	}}
	if err := Save(s); err != nil {
		t.Fatal(err)
	}
	got, err := Load("kunde")
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(got.Profile, s.Profile) {
		t.Fatalf("profile = %+v, want %+v", got.Profile, s.Profile)
	}

	s.Profile = nil
	Save(s)
	if got, _ := Load("kunde"); got.Profile != nil {
		t.Fatal("site without profile must load without one")
	}
}

func mustPath(t *testing.T, name string) string {
	t.Helper()
	p, err := path(name)
	if err != nil {
		t.Fatal(err)
	}
	return p
}

// P15: zusätzliche Seiten für den Health-Check eines Pushs.
func TestHealthURLsRoundTrip(t *testing.T) {
	t.Setenv("WPSYNC_CONFIG_DIR", t.TempDir())
	in := &Site{Name: "kunde", URL: "https://kunde.de", KeyID: "0123456789abcdef", RPS: 1, HealthURLs: []string{"https://kunde.de/kasse/"}}
	if err := Save(in); err != nil {
		t.Fatal(err)
	}
	got, err := Load("kunde")
	if err != nil || len(got.HealthURLs) != 1 || got.HealthURLs[0] != "https://kunde.de/kasse/" {
		t.Fatalf("got %+v, %v", got, err)
	}
}
