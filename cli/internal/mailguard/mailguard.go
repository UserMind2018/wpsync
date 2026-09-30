// Package mailguard finds the local-mailguard mu-plugin that every pulled site mounts.
// Without it no pull runs: a production dump often brings SMTP plugins with real credentials.
package mailguard

import (
	"bytes"
	_ "embed"
	"fmt"
	"os"
	"path/filepath"
)

// FileName of the mu-plugin inside the container.
const FileName = "00-local-mailguard.php"

// Where the resolved file came from.
const (
	OriginEnv     = "WPSYNC_MAILGUARD"
	OriginBundled = "mitgeliefert"
)

// bundled is the mailguard shipped with wpsync; this file is its only source.
//
//go:embed 00-local-mailguard.php
var bundled []byte

// Resolve returns the mailguard file to mount: $WPSYNC_MAILGUARD if set, otherwise the
// bundled file written to <configDir>/mailguard/ (a bind mount needs a file on disk).
func Resolve(configDir string) (path, origin string, err error) {
	if p := os.Getenv("WPSYNC_MAILGUARD"); p != "" {
		if _, err := os.Stat(p); err != nil {
			return "", OriginEnv, fmt.Errorf("WPSYNC_MAILGUARD points to a missing file: %s", p)
		}
		return p, OriginEnv, nil
	}
	p := filepath.Join(configDir, "mailguard", FileName)
	if current, err := os.ReadFile(p); err == nil && bytes.Equal(current, bundled) {
		return p, OriginBundled, nil
	}
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		return "", OriginBundled, fmt.Errorf("create mailguard dir: %w", err)
	}
	if err := os.WriteFile(p, bundled, 0o644); err != nil {
		return "", OriginBundled, fmt.Errorf("write bundled mailguard: %w", err)
	}
	return p, OriginBundled, nil
}
