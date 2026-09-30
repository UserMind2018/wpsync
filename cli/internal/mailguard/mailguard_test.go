package mailguard

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestResolveEnvOverride(t *testing.T) {
	p := filepath.Join(t.TempDir(), "guard.php")
	if err := os.WriteFile(p, []byte("<?php"), 0o644); err != nil {
		t.Fatal(err)
	}
	t.Setenv("WPSYNC_MAILGUARD", p)
	got, origin, err := Resolve(t.TempDir())
	if err != nil || got != p || origin != OriginEnv {
		t.Fatalf("Resolve = %q, %q, %v", got, origin, err)
	}
}

func TestResolveEnvOverrideMissingFails(t *testing.T) {
	t.Setenv("WPSYNC_MAILGUARD", filepath.Join(t.TempDir(), "missing.php"))
	if _, _, err := Resolve(t.TempDir()); err == nil {
		t.Fatal("missing WPSYNC_MAILGUARD file must fail, not fall back silently")
	}
}

func TestResolveWritesBundledCopy(t *testing.T) {
	t.Setenv("WPSYNC_MAILGUARD", "")
	cfg := t.TempDir()
	got, origin, err := Resolve(cfg)
	if err != nil || origin != OriginBundled || got != filepath.Join(cfg, "mailguard", FileName) {
		t.Fatalf("Resolve = %q, %q, %v", got, origin, err)
	}
	data, err := os.ReadFile(got)
	if err != nil || string(data) != string(bundled) {
		t.Fatalf("bundled copy not written: %v", err)
	}
	// An outdated copy is replaced with the bundled version.
	if err := os.WriteFile(got, []byte("<?php // old"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, _, err := Resolve(cfg); err != nil {
		t.Fatal(err)
	}
	if data, _ := os.ReadFile(got); string(data) != string(bundled) {
		t.Fatal("outdated copy not replaced")
	}
}

func TestBundledCopyHasMailFilter(t *testing.T) {
	if len(bundled) == 0 || !strings.Contains(string(bundled), "local_mailguard_collect") {
		t.Fatal("bundled mailguard lacks the wp_mail filter the pull checks for")
	}
}
