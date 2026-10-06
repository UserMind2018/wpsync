package main

import (
	"context"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Spec Server-Modus §5/§9: kein Secret in stdout (ausser pair --secret-out), stderr, Dateien
// oder Argumenten – über alle Befehle des Server-Modus.
func TestSecretsNeverLeak(t *testing.T) {
	configDir, sitesDir := env(t)
	ag := newAgent(t)
	docroot, dockerLog := containerSite(t)
	ctx := context.Background()
	pullArgs := append([]string{"pull", "vorlage", "--json", "--secret-stdin", "--yes"}, containerArgs(docroot)...)
	statusArgs := []string{"status", "vorlage", "--json", "--secret-stdin", "--driver", "container", "--docroot", docroot}

	pair := run(t, ctx, "", "pair", ag.URL(), "123456", "--name", "vorlage", "--insecure", "--json", "--secret-out")
	lastResult(t, pair, "pair", 0)
	if strings.Count(pair.stdout, testSecret) != 1 || strings.Contains(pair.stderr, testSecret) {
		t.Fatalf("pair: secret must appear exactly once, on stdout")
	}

	steps := []result{
		run(t, ctx, testSecret+"\n", "scan", "vorlage", "--json", "--secret-stdin", "--preset", "vollstaendig"),
		run(t, ctx, secrets, pullArgs...),
		run(t, ctx, secrets, pullArgs...),
		run(t, ctx, testSecret+"\n", statusArgs...),
		run(t, ctx, secrets, append(pullArgs, "--bogus")...),
		run(t, ctx, "", "unpair", "vorlage", "--json"),
	}
	for i, r := range steps {
		for _, secret := range []string{testSecret, testDBPass} {
			if strings.Contains(r.stdout, secret) || strings.Contains(r.stderr, secret) {
				t.Errorf("step %d: secret in output\nstdout: %s\nstderr: %s", i, r.stdout, r.stderr)
			}
		}
	}

	calls, _ := os.ReadFile(dockerLog)
	if len(calls) == 0 {
		t.Fatal("docker was never called – the pull did not run")
	}
	for _, secret := range []string{testSecret, testDBPass} {
		if strings.Contains(string(calls), secret) {
			t.Errorf("secret in docker arguments")
		}
	}
	for _, root := range []string{configDir, sitesDir, filepath.Dir(docroot)} {
		filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
			if err != nil || d.IsDir() {
				return err
			}
			data, _ := os.ReadFile(path)
			for _, secret := range []string{testSecret, testDBPass} {
				if strings.Contains(string(data), secret) {
					t.Errorf("secret in file %s", path)
				}
			}
			return nil
		})
	}
}
