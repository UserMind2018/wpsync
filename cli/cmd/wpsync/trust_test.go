package main

import (
	"errors"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/ddev"
)

// runTrust calls cmdTrust without a terminal and returns its output.
func runTrust(t *testing.T, args ...string) (string, error) {
	t.Helper()
	null, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	defer null.Close()
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	stdin, stdout := os.Stdin, os.Stdout
	os.Stdin, os.Stdout = null, w
	done := make(chan string)
	go func() {
		data, _ := io.ReadAll(r)
		done <- string(data)
	}()
	cmdErr := cmdTrust(args)
	w.Close()
	os.Stdin, os.Stdout = stdin, stdout
	return <-done, cmdErr
}

var fpRe = regexp.MustCompile(`--fingerprint ([0-9a-f]+)`)

type trustEnv struct {
	site  string
	store ddev.Store
}

func newTrustEnv(t *testing.T) *trustEnv {
	t.Helper()
	root, cfg := t.TempDir(), t.TempDir()
	useDocker(t, &cmdDocker{})
	t.Setenv("WPSYNC_SITES_DIR", root)
	t.Setenv("WPSYNC_CONFIG_DIR", cfg)
	site := filepath.Join(root, "kunde")
	write(t, site, "config.yaml", "name: kunde\n")
	write(t, site, "docker-compose.e2e-source.yaml", "services:\n  web:\n    volumes:\n      - \"/Users/u/source:/var/www/source\"\n")
	store, err := ddev.NewStore(cfg, root)
	if err != nil {
		t.Fatal(err)
	}
	return &trustEnv{site: site, store: store}
}

func write(t *testing.T, site, rel, content string) {
	t.Helper()
	p := filepath.Join(site, ".ddev", filepath.FromSlash(rel))
	os.MkdirAll(filepath.Dir(p), 0o755)
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func (e *trustEnv) check(t *testing.T) error {
	t.Helper()
	p, err := ddev.OpenProject("kunde", e.site, e.store, nil)
	if err != nil {
		t.Fatal(err)
	}
	return p.Check("start", "-y")
}

func (e *trustEnv) saved(t *testing.T) bool {
	_, ok, err := e.store.Load("kunde")
	if err != nil {
		t.Fatal(err)
	}
	return ok
}

// AC-13/AC-14: ohne Terminal keine stille Freigabe, --yes gibt nicht frei, nur der Fingerprint
// des angezeigten Stands; eine spätere Änderung bricht wieder ab.
func TestTrustNeedsExplicitApproval(t *testing.T) {
	e := newTrustEnv(t)

	out, err := runTrust(t, "kunde")
	m := fpRe.FindStringSubmatch(errString(err))
	if err == nil || m == nil || e.saved(t) {
		t.Fatalf("without terminal: err = %v, saved = %v", err, e.saved(t))
	}
	if !strings.Contains(out, "docker-compose.e2e-source.yaml") || !strings.Contains(out, "Mount /Users/u/source") || !strings.Contains(out, "Fingerprint: "+m[1]) {
		t.Fatalf("takeover output:\n%s", out)
	}
	fp := m[1]

	if _, err := runTrust(t, "kunde", "--yes"); err == nil || e.saved(t) {
		t.Fatalf("--yes: err = %v, saved = %v", err, e.saved(t))
	}
	if _, err := runTrust(t, "kunde", "--fingerprint", "0000000000000000"); err == nil || e.saved(t) {
		t.Fatalf("wrong fingerprint: err = %v, saved = %v", err, e.saved(t))
	}
	if !errors.Is(e.check(t), ddev.ErrNotAdopted) {
		t.Fatal("check passes before approval")
	}

	if out, err := runTrust(t, "kunde", "--fingerprint", fp); err != nil || !strings.Contains(out, "freigegeben") {
		t.Fatalf("approval: err = %v\n%s", err, out)
	}
	if err := e.check(t); err != nil {
		t.Fatalf("check after approval: %v", err)
	}
	if out, err := runTrust(t, "kunde"); err != nil || !strings.Contains(out, "nichts freizugeben") {
		t.Fatalf("nothing to approve: err = %v\n%s", err, out)
	}

	// A later change to the approved file is a deviation again; the old fingerprint does not fit.
	write(t, e.site, "docker-compose.e2e-source.yaml", "services:\n  web:\n    volumes:\n      - \".:/var/www/html/.ddev\"\n")
	var dev *ddev.DeviationError
	if err := e.check(t); !errors.As(err, &dev) || dev.Changes[0].Path != "docker-compose.e2e-source.yaml" {
		t.Fatalf("check after change: %v", err)
	}
	if _, err := runTrust(t, "kunde", "--fingerprint", fp); err == nil {
		t.Fatal("old fingerprint approved a changed file")
	}
	out, err = runTrust(t, "kunde")
	if !strings.Contains(out, "geändert:") || !strings.Contains(out, "! web: Mount .:/var/www/html/.ddev") {
		t.Fatalf("deviation output:\n%s", out)
	}
	if err := e.check(t); err == nil {
		t.Fatal("deviation approved without fingerprint")
	}
	m = fpRe.FindStringSubmatch(errString(err))
	if m == nil {
		t.Fatalf("err = %v", err)
	}
	if _, err := runTrust(t, "kunde", "--fingerprint", m[1]); err != nil {
		t.Fatal(err)
	}
	if err := e.check(t); err != nil {
		t.Fatalf("check after second approval: %v", err)
	}
}

// AC-13 / AC-7: Symlinks an geschützten Pfaden sind nicht freigebbar.
func TestTrustRefusesSymlink(t *testing.T) {
	e := newTrustEnv(t)
	os.MkdirAll(filepath.Join(e.site, ".ddev", "commands", "host"), 0o755)
	if err := os.Symlink("/bin/sh", filepath.Join(e.site, ".ddev", "commands", "host", "x")); err != nil {
		t.Fatal(err)
	}
	out, err := runTrust(t, "kunde")
	if !errors.Is(err, ddev.ErrUnsafeEntry) || e.saved(t) {
		t.Fatalf("err = %v, saved = %v", err, e.saved(t))
	}
	if strings.Contains(out, "Fingerprint:") || !strings.Contains(out, "nicht freigebbar") {
		t.Fatalf("output:\n%s", out)
	}
}

func TestTrustRejectsBadArguments(t *testing.T) {
	newTrustEnv(t)
	for _, args := range [][]string{nil, {"../kunde"}, {"a", "b"}, {"fehlt"}} {
		if _, err := runTrust(t, args...); err == nil {
			t.Errorf("cmdTrust(%q) accepted", args)
		}
	}
}

func errString(err error) string {
	if err == nil {
		return ""
	}
	return err.Error()
}
