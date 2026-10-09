package content

import (
	"bytes"
	"os"
	"path/filepath"
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
