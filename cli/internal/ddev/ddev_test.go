package ddev

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestDatabaseSpec(t *testing.T) {
	cases := map[string]string{
		"10.11.14-MariaDB-0ubuntu0.24.04.1": "mariadb:10.11",
		"10.6.23-MariaDB-0ubuntu0.22.04.1":  "mariadb:10.6",
		"8.0.35":                            "mysql:8.0",
		"5.7.44-log":                        "mysql:5.7",
		"9.1.0":                             "mysql:8.0",
	}
	for in, want := range cases {
		if got := DatabaseSpec(in); got != want {
			t.Errorf("DatabaseSpec(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestClaimWPConfigRemovesMarkerOnce(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "public"), 0o755)
	p := filepath.Join(dir, "public", "wp-config.php")
	os.WriteFile(p, []byte("<?php\n/**\n * #ddev-generated: Automatically generated WordPress settings file.\n */\n"), 0o644)

	if err := ClaimWPConfig(dir); err != nil {
		t.Fatal(err)
	}
	data, _ := os.ReadFile(p)
	if strings.Contains(string(data), "#ddev-generated") || !strings.Contains(string(data), "wpsync-managed") {
		t.Fatalf("wp-config not claimed:\n%s", data)
	}
}

func TestOwnFilesMailguardMount(t *testing.T) {
	src := filepath.Join(t.TempDir(), "00-local-mailguard.php")
	os.WriteFile(src, []byte("<?php"), 0o644)

	files, err := OwnFiles(src)
	if err != nil {
		t.Fatal(err)
	}
	data := files[MailguardComposeFile]
	if !strings.Contains(data, src+":/var/www/html/public/wp-content/mu-plugins/00-local-mailguard.php:ro") {
		t.Fatalf("compose = %s", data)
	}
	if _, err := OwnFiles("/does/not/exist.php"); err == nil {
		t.Fatal("missing mailguard source must be an error")
	}
}

func TestParseDescribe(t *testing.T) {
	url, err := parseDescribe([]byte(`{"level":"info","raw":{"httpurl":"http://x.ddev.site:8480","status":"running"}}`))
	if err != nil || url != "http://x.ddev.site:8480" {
		t.Fatalf("url = %q, err = %v", url, err)
	}
}
