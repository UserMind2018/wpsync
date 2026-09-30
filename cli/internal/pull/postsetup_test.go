package pull

import (
	"bytes"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

type fakeRunner struct {
	calls  [][]string
	output string
	err    error
}

func (f *fakeRunner) Run(args ...string) error { f.calls = append(f.calls, args); return nil }
func (f *fakeRunner) Output(args ...string) (string, error) {
	f.calls = append(f.calls, args)
	return f.output, f.err
}
func (f *fakeRunner) RunStdin(_ io.Reader, args ...string) error {
	f.calls = append(f.calls, args)
	return nil
}

func (f *fakeRunner) joined() string {
	var lines []string
	for _, c := range f.calls {
		lines = append(lines, strings.Join(c, " "))
	}
	return strings.Join(lines, "\n")
}

func TestPostSetup(t *testing.T) {
	r := &fakeRunner{}
	env := agentapi.Env{
		Home:          "https://www.kunde.de",
		SiteURL:       "https://www.kunde.de",
		ActivePlugins: []string{"wp-mail-smtp/wp_mail_smtp.php", "elementor/elementor.php", "password-protected/password-protected.php"},
	}
	if err := PostSetup(r, env, "http://kunde.ddev.site", PostSetupOptions{}, io.Discard); err != nil {
		t.Fatal(err)
	}
	got := r.joined()
	for _, want := range []string{
		"wp search-replace https://www.kunde.de http://kunde.ddev.site --all-tables-with-prefix --skip-columns=guid --report-changed-only --skip-plugins --skip-themes",
		`wp search-replace https:\/\/www.kunde.de http:\/\/kunde.ddev.site`,
		"wp config set WP_ENVIRONMENT_TYPE local --type=constant",
		"wp config set DISABLE_WP_CRON true --raw --type=constant",
		"wp plugin deactivate wp-mail-smtp password-protected --skip-plugins --skip-themes",
	} {
		if !strings.Contains(got, want) {
			t.Errorf("missing call %q in\n%s", want, got)
		}
	}
	if strings.Contains(got, "deactivate elementor") {
		t.Error("elementor must stay active")
	}
}

func TestRemoveDropIns(t *testing.T) {
	docroot := t.TempDir()
	os.MkdirAll(filepath.Join(docroot, "wp-content"), 0o755)
	for _, f := range []string{"advanced-cache.php", "object-cache.php", "db.php"} {
		os.WriteFile(filepath.Join(docroot, "wp-content", f), []byte("<?php"), 0o644)
	}
	RemoveDropIns(docroot)
	for f, want := range map[string]bool{"advanced-cache.php": false, "object-cache.php": false, "db.php": true} {
		_, err := os.Stat(filepath.Join(docroot, "wp-content", f))
		if (err == nil) != want {
			t.Errorf("%s exists = %v, want %v", f, err == nil, want)
		}
	}
}

func TestMailguardCheck(t *testing.T) {
	if err := MailguardCheck(&fakeRunner{output: "PHP Notice: x\nok"}); err != nil {
		t.Fatalf("ok output must pass: %v", err)
	}
	if err := MailguardCheck(&fakeRunner{output: "missing"}); !errors.Is(err, ErrMailguardMissing) {
		t.Fatalf("err = %v", err)
	}
	if err := MailguardCheck(&fakeRunner{err: errors.New("fatal")}); !errors.Is(err, ErrMailguardMissing) {
		t.Fatalf("err = %v", err)
	}
}

func TestPostSetupDropsExcludedActivePluginsBeforeSecondPass(t *testing.T) {
	r := &fakeRunner{}
	env := agentapi.Env{Home: "https://kunde.de", SiteURL: "https://kunde.de", TablePrefix: "wp_",
		ActivePlugins: []string{"elementor/elementor.php", "hello.php", "duplicator-pro/duplicator-pro.php"}}
	var out bytes.Buffer
	o := PostSetupOptions{ExcludedPlugins: []string{"duplicator-pro", "hello", "not-active"}}
	if err := PostSetup(r, env, "http://kunde.ddev.site", o, &out); err != nil {
		t.Fatal(err)
	}
	got := r.joined()
	drop := "wp eval $drop = ['duplicator-pro','hello'];"
	second := "wp search-replace https://kunde.de http://kunde.ddev.site wp_options --precise --report-changed-only"
	if !strings.Contains(got, drop) || !strings.Contains(got, second) {
		t.Fatalf("calls:\n%s", got)
	}
	if strings.Index(got, drop) > strings.Index(got, second) {
		t.Error("the second pass must run after the deactivation")
	}
	if !strings.Contains(out.String(), "duplicator-pro, hello") {
		t.Errorf("output = %q", out.String())
	}
}

type failingRunner struct {
	fakeRunner
	failOn string
}

func (f *failingRunner) Run(args ...string) error {
	f.calls = append(f.calls, args)
	if strings.Contains(strings.Join(args, " "), f.failOn) {
		return errors.New("exit status 1")
	}
	return nil
}

func TestSecondPassFailureIsOnlyAWarning(t *testing.T) {
	r := &failingRunner{failOn: "--precise"}
	var out bytes.Buffer
	env := agentapi.Env{Home: "https://kunde.de", TablePrefix: "wp_"}
	if err := PostSetup(r, env, "http://kunde.ddev.site", PostSetupOptions{}, &out); err != nil {
		t.Fatalf("second pass must not abort the pull: %v", err)
	}
	if !strings.Contains(out.String(), "Search-Replace mit geladenen Plugins fehlgeschlagen") {
		t.Errorf("output = %q", out.String())
	}
}
