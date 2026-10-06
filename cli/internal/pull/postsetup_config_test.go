package pull

import (
	"errors"
	"io"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

// configRunner answers `wp config get` from a map and fails `wp config set` like a read-only
// wp-config.php (Website Studio mounts it read-only and defines the constants itself).
type configRunner struct {
	fakeRunner
	values   map[string]string // constant → JSON value; missing → "not defined"
	readOnly bool
}

func (c *configRunner) Run(args ...string) error {
	c.calls = append(c.calls, args)
	if c.readOnly && len(args) > 2 && args[0] == "wp" && args[1] == "config" && args[2] == "set" {
		return errors.New("docker run: exit status 1")
	}
	return nil
}

func (c *configRunner) Output(args ...string) (string, error) {
	c.calls = append(c.calls, args)
	if len(args) > 3 && args[0] == "wp" && args[1] == "config" && args[2] == "get" {
		if v, ok := c.values[args[3]]; ok {
			return v + "\n", nil
		}
		return "", errors.New("The constant '" + args[3] + "' is not defined")
	}
	return "", nil
}

func postSetupEnv() agentapi.Env {
	return agentapi.Env{TablePrefix: "wp_", Home: "https://www.kunde.de", SiteURL: "https://www.kunde.de"}
}

// Read-only wp-config.php mit den richtigen Konstanten: kein `wp config set`, kein Fehler.
func TestPostSetupSkipsConfigSetWhenConstantsAlreadyMatch(t *testing.T) {
	r := &configRunner{values: map[string]string{"WP_ENVIRONMENT_TYPE": `"local"`, "DISABLE_WP_CRON": "true"}, readOnly: true}
	var out strings.Builder
	if err := PostSetup(r, postSetupEnv(), "http://kunde.local", PostSetupOptions{}, &out); err != nil {
		t.Fatalf("PostSetup: %v", err)
	}
	if got := r.joined(); strings.Contains(got, "wp config set") {
		t.Fatalf("config set must not run when the values match:\n%s", got)
	}
	if !strings.Contains(out.String(), "lokale Konstanten schon gesetzt") {
		t.Errorf("hint missing: %q", out.String())
	}
}

// Abweichender Wert und schreibgeschützte Datei: weiterhin ein klarer Fehler (Exit 22).
func TestPostSetupFailsWhenConstantDiffersAndConfigIsReadOnly(t *testing.T) {
	r := &configRunner{values: map[string]string{"WP_ENVIRONMENT_TYPE": `"production"`, "DISABLE_WP_CRON": "true"}, readOnly: true}
	err := PostSetup(r, postSetupEnv(), "http://kunde.local", PostSetupOptions{}, io.Discard)
	if err == nil || !strings.Contains(err.Error(), "WP_ENVIRONMENT_TYPE") {
		t.Fatalf("err = %v", err)
	}
}

// Fehlende oder abweichende Konstante bei beschreibbarer Datei: nur sie wird gesetzt.
func TestPostSetupSetsOnlyMissingOrDifferentConstants(t *testing.T) {
	r := &configRunner{values: map[string]string{"WP_ENVIRONMENT_TYPE": `"local"`}}
	if err := PostSetup(r, postSetupEnv(), "http://kunde.local", PostSetupOptions{}, io.Discard); err != nil {
		t.Fatal(err)
	}
	got := r.joined()
	if strings.Contains(got, "wp config set WP_ENVIRONMENT_TYPE") {
		t.Errorf("matching constant set again:\n%s", got)
	}
	if !strings.Contains(got, "wp config set DISABLE_WP_CRON true --raw --type=constant") {
		t.Errorf("missing constant not set:\n%s", got)
	}
}

// DISABLE_WP_CRON als 1 oder "1" gilt ebenfalls als gesetzt; false nicht.
func TestConstantMatches(t *testing.T) {
	for _, c := range []struct {
		got, want string
		ok        bool
	}{
		{`"local"`, `"local"`, true}, {`"local"` + "\n", `"local"`, true}, {`"production"`, `"local"`, false},
		{"true", "true", true}, {"1", "true", true}, {`"1"`, "true", true}, {"false", "true", false}, {"", "true", false},
	} {
		if got := constantMatches(c.got, c.want); got != c.ok {
			t.Errorf("constantMatches(%q, %q) = %v", c.got, c.want, got)
		}
	}
}
