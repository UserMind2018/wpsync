package content

import (
	"bytes"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"testing"
)

// The agent and the local site must compute with the same PHP (Spec Content-Push C1).
func TestPHPMatchesAgent(t *testing.T) {
	for _, name := range agentFiles {
		want, err := os.ReadFile(filepath.Join("..", "..", "..", "agent", "src", name))
		if err != nil {
			t.Fatal(err)
		}
		got, err := phpFS.ReadFile("php/" + name)
		if err != nil {
			t.Fatal(err)
		}
		if !bytes.Equal(got, want) {
			t.Errorf("php/%s differs from agent/src/%s – run: cp agent/src/%s cli/internal/content/php/", name, name, name)
		}
	}
}

func TestScriptIsOnePHPFile(t *testing.T) {
	s := Script("export.php")
	if !bytes.HasPrefix(s, []byte("<?php\n")) || bytes.Count(s, []byte("<?php")) != 1 {
		t.Fatalf("script must open PHP exactly once, at its start")
	}
	for _, want := range []string{"final class SerializedWalker", "final class ContentOrigin", "final class Canon", "final class ContentReader", "wpsync content export"} {
		if !bytes.Contains(s, []byte(want)) {
			t.Errorf("script lacks %q", want)
		}
	}
	if bytes.Index(s, []byte("final class SerializedWalker")) > bytes.Index(s, []byte("final class ContentOrigin")) {
		t.Error("SerializedWalker must come before ContentOrigin")
	}
	// wp eval-file evals the code: a namespace statement has to be the first one.
	if !bytes.HasPrefix(bytes.TrimSpace(bytes.TrimPrefix(s, []byte("<?php"))), []byte("namespace WpSync;")) {
		t.Error("the first statement must be the namespace")
	}
}

// TestWriteScript writes the export script to $WPSYNC_WRITE_SCRIPT – to try it by hand:
//
//	ddev wp eval-file - <local url> all --skip-plugins --skip-themes < script.php
func TestWriteScript(t *testing.T) {
	path := os.Getenv("WPSYNC_WRITE_SCRIPT")
	if path == "" {
		t.Skip("WPSYNC_WRITE_SCRIPT not set")
	}
	if err := os.WriteFile(path, Script("export.php"), 0o600); err != nil {
		t.Fatal(err)
	}
}

// CanonVersion (Go) and Canon::VERSION (embedded PHP) are the same number, always.
func TestCanonVersionMatchesPHP(t *testing.T) {
	data, err := phpFS.ReadFile("php/Canon.php")
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile(`public const VERSION\s*=\s*(\d+)\s*;`).FindSubmatch(data)
	if m == nil {
		t.Fatal("php/Canon.php has no `public const VERSION = <n>;`")
	}
	if n, err := strconv.Atoi(string(m[1])); err != nil || n != CanonVersion {
		t.Fatalf("Canon::VERSION = %s, CanonVersion = %d", m[1], CanonVersion)
	}
}
