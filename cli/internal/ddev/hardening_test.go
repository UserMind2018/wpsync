package ddev

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"gopkg.in/yaml.v3"
)

// AC-1, AC-2, AC-3: die Härtung macht .ddev in web und db read-only, lässt nur db_snapshots
// schreibbar; der Mailguard-Mount bleibt daneben ro bestehen.
func TestHardeningComposeContent(t *testing.T) {
	var doc struct {
		Services map[string]struct {
			Volumes []string `yaml:"volumes"`
		} `yaml:"services"`
	}
	if err := yaml.Unmarshal([]byte(HardeningCompose()), &doc); err != nil {
		t.Fatal(err)
	}
	want := map[string][]string{
		"web": {".:/var/www/html/.ddev:ro"},
		"db":  {".:/mnt/ddev_config:ro", "./db_snapshots:/mnt/ddev_config/db_snapshots"},
	}
	if len(doc.Services) != len(want) {
		t.Fatalf("services = %v", doc.Services)
	}
	for svc, vols := range want {
		if strings.Join(doc.Services[svc].Volumes, "|") != strings.Join(vols, "|") {
			t.Errorf("%s volumes = %v, want %v", svc, doc.Services[svc].Volumes, vols)
		}
	}

	src := mailguardFile(t)
	files, err := OwnFiles(src)
	if err != nil {
		t.Fatal(err)
	}
	if files[HardeningComposeFile] != HardeningCompose() {
		t.Error("OwnFiles lacks the hardening")
	}
	var mg struct {
		Services map[string]struct {
			Volumes []string `yaml:"volumes"`
		} `yaml:"services"`
	}
	if err := yaml.Unmarshal([]byte(files[MailguardComposeFile]), &mg); err != nil {
		t.Fatal(err)
	}
	if v := mg.Services["web"].Volumes; len(v) != 1 || v[0] != src+":/var/www/html/public/wp-content/mu-plugins/00-local-mailguard.php:ro" {
		t.Errorf("mailguard volumes = %v", v)
	}
	// Both are protected paths, so a change to either is a deviation.
	for name := range files {
		if !Protected(name) {
			t.Errorf("%s not protected", name)
		}
	}
	// Mounts of both files together make web hardened and keep the mailguard ro.
	site := t.TempDir()
	web := append(hardenedWeb(site), Mount{Source: src, Destination: "/var/www/html/public/wp-content/mu-plugins/00-local-mailguard.php"})
	if !Hardened(Container{Service: "web", Mounts: web}, site) {
		t.Error("web with mailguard mount not hardened")
	}
	if _, err := os.Stat(filepath.Join(site, ".ddev")); err == nil {
		t.Error("unexpected .ddev")
	}
}
