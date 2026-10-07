package ddev

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

func TestDatabaseSpec(t *testing.T) {
	cases := map[string]string{
		"10.11.14-MariaDB-0ubuntu0.24.04.1": "mariadb:10.11",
		"10.6.23-MariaDB-0ubuntu0.22.04.1":  "mariadb:10.6",
		// MariaDB ≥ 10 puts 5.5.5- in front for old clients; PHP before 8.0.16 hands it on as is.
		"5.5.5-10.11.19-MariaDB-ubu2204-log": "mariadb:10.11",
		"5.5.5-10.4.34-MariaDB":              "mariadb:10.4",
		"5.5.68-MariaDB":                     "mariadb:5.5",
		"8.0.35":                             "mysql:8.0",
		"5.7.44-log":                         "mysql:5.7",
		"9.1.0":                              "mysql:8.0",
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

// Review N2: die PHP-Version stammt von der Quelle und landet in --php-version bzw. im Image-Namen.
func TestPHPMajorMinor(t *testing.T) {
	for in, want := range map[string]string{"8.3.35": "8.3", "7.4": "7.4", "8.4.0-1ubuntu": "8.4", "10.0.1": "10.0"} {
		if got, err := PHPMajorMinor(in); err != nil || got != want {
			t.Errorf("PHPMajorMinor(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
	for _, bad := range []string{"", "8", "8.3/../x.1", "8.x.1", "--privileged", "8.3@sha256:abc", " 8.3", "8.3 .1", "8.3\n.1"} {
		if got, err := PHPMajorMinor(bad); !errors.Is(err, agentapi.ErrInvalidEnv) {
			t.Errorf("PHPMajorMinor(%q) = %q, %v; want ErrInvalidEnv", bad, got, err)
		}
	}
}

func TestDriverConfigureRejectsInvalidPHPVersion(t *testing.T) {
	d := &Driver{}
	if err := d.Configure(agentapi.Env{PHPVersion: "8.3/../../x.1", TablePrefix: "wp_"}); !errors.Is(err, agentapi.ErrInvalidEnv) {
		t.Fatalf("Configure = %v, want ErrInvalidEnv", err)
	}
}
