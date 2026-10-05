package agentapi

import (
	"errors"
	"reflect"
	"strings"
	"testing"
)

// SEC-132 AC-1: Präfix-Regel.
func TestValidTablePrefix(t *testing.T) {
	ok := []string{"wp_", "wp_2_", "e2e_", "djTui5D_", "nyts_", "a", "WP_", strings.Repeat("a", 64)}
	bad := []string{
		"", strings.Repeat("a", 65), "--path=/x", "--exec=phpinfo();//", "-x", "wp-", "wp_$", "wp.",
		"wp_ ", "wp_\n", "wp_'", "*", "wp_ä", "wp_\x00", "wp_\x1b[2J",
	}
	for _, p := range ok {
		if !ValidTablePrefix(p) {
			t.Errorf("ValidTablePrefix(%q) = false, want true", p)
		}
	}
	for _, p := range bad {
		if ValidTablePrefix(p) {
			t.Errorf("ValidTablePrefix(%q) = true, want false", p)
		}
	}
}

// SEC-132 AC-2: URL-Regel für home und siteurl.
func TestValidSiteURL(t *testing.T) {
	long := "https://kunde.example/"
	ok := []string{
		"https://www.kunde.example", "http://kunde.example", "https://kunde.example/blog", "https://kunde.example:8443",
		"https://xn--mller-kva.example", "https://müller.example", "HTTPS://Kunde.example",
		long + strings.Repeat("a", 2048-len(long)),
	}
	bad := []string{
		"", "--exec=phpinfo();", "-x", "kunde.example", "//kunde.example", "ftp://kunde.example", "javascript:alert(1)",
		"https://", "https://:80", " https://kunde.example", "https://kunde.example/a b", "https://kunde.example\n--exec=x",
		"https://kunde.example/\x1b[2J", "https://kunde.example/\x00", "https://kunde.example/\x7f",
		long + strings.Repeat("a", 2049-len(long)),
	}
	for _, s := range ok {
		if !ValidSiteURL(s) {
			t.Errorf("ValidSiteURL(%q) = false, want true", s)
		}
	}
	for _, s := range bad {
		if ValidSiteURL(s) {
			t.Errorf("ValidSiteURL(%.60q) = true, want false", s)
		}
	}
}

// SEC-132 AC-4/AC-5 auf Regel-Ebene: alle betroffenen Felder in fester Reihenfolge, leere siteurl
// erlaubt, leeres home nicht.
func TestEnvInvalidArgs(t *testing.T) {
	cases := map[string]struct {
		env  Env
		want []EnvField
	}{
		"valid":             {Env{TablePrefix: "wp_", Home: "https://kunde.example", SiteURL: "https://kunde.example/wp"}, nil},
		"empty siteurl":     {Env{TablePrefix: "wp_", Home: "https://kunde.example"}, nil},
		"empty home":        {Env{TablePrefix: "wp_", SiteURL: "https://kunde.example"}, []EnvField{{"home", ""}}},
		"empty prefix":      {Env{Home: "https://kunde.example"}, []EnvField{{"table_prefix", ""}}},
		"bad siteurl":       {Env{TablePrefix: "wp_", Home: "https://kunde.example", SiteURL: "--exec=x"}, []EnvField{{"siteurl", "--exec=x"}}},
		"all three invalid": {Env{TablePrefix: "--path=/x", Home: "--exec=x", SiteURL: "-x"}, []EnvField{{"table_prefix", "--path=/x"}, {"home", "--exec=x"}, {"siteurl", "-x"}}},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			if got := tc.env.InvalidArgs(); !reflect.DeepEqual(got, tc.want) {
				t.Errorf("InvalidArgs = %v, want %v", got, tc.want)
			}
			err := tc.env.CheckArgs()
			if (err == nil) != (tc.want == nil) {
				t.Fatalf("CheckArgs = %v", err)
			}
			if err != nil && !errors.Is(err, ErrInvalidEnv) {
				t.Errorf("CheckArgs = %v, want ErrInvalidEnv", err)
			}
		})
	}
}

// SEC-132 AC-4: CheckArgs gibt Werte nur entschärft wieder.
func TestEnvCheckArgsEscapesValues(t *testing.T) {
	err := Env{TablePrefix: "wp_\x1b[2J", Home: "https://müller.example/ x"}.CheckArgs()
	if err == nil {
		t.Fatal("CheckArgs = nil")
	}
	msg := err.Error()
	for _, b := range []byte(msg) {
		if b < 0x20 || b >= 0x7f {
			t.Fatalf("byte %#x in %q", b, msg)
		}
	}
	for _, want := range []string{"table_prefix", `wp_\x1b[2J`, "home", `m\` + `u00fcller`} {
		if !strings.Contains(msg, want) {
			t.Errorf("message misses %q: %s", want, msg)
		}
	}
}
