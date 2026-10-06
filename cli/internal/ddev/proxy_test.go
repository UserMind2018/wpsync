package ddev

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestUploadsProxyConfig(t *testing.T) {
	zone, server, err := UploadsProxyConfig("https://www.kunde.de", "wpsync/0.2.0")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(zone, "limit_req_zone $server_name zone=wpsync_uploads:1m rate=2r/s;") {
		t.Errorf("zone = %s", zone)
	}
	for _, want := range []string{
		"location ^~ /wp-content/uploads/ {",
		"try_files $uri @wpsync_source;",
		"limit_req zone=wpsync_uploads burst=500;",
		`set $wpsync_source "https://www.kunde.de";`,
		`proxy_set_header Host "www.kunde.de";`,
		`proxy_set_header User-Agent "wpsync/0.2.0 uploads-proxy";`,
		"proxy_store /var/www/html/public$wpsync_path;",
		"proxy_temp_path /var/www/html/.wpsync/proxy-tmp;",
	} {
		if !strings.Contains(server, want) {
			t.Errorf("missing %q in\n%s", want, server)
		}
	}
	if strings.Contains(server, "rewrite") {
		t.Error("no rewrite without a base path")
	}

	_, sub, err := UploadsProxyConfig("https://kunde.de/blog/", "wpsync/0.2.0")
	if err != nil || !strings.Contains(sub, "rewrite ^ /blog$wpsync_path break;") {
		t.Errorf("base path: err = %v\n%s", err, sub)
	}

	for _, bad := range []string{"ftp://kunde.de", `https://kunde.de/";evil`, "kein-url"} {
		if _, _, err := UploadsProxyConfig(bad, "wpsync/0.2.0"); err == nil {
			t.Errorf("accepted %q", bad)
		}
	}
}

func TestWriteUploadsProxy(t *testing.T) {
	dir := t.TempDir()
	zone := filepath.Join(dir, ".ddev", "nginx_full", proxyZoneFile)
	server := filepath.Join(dir, ".ddev", "nginx", proxyFile)

	changed, err := WriteUploadsProxy(dir, "https://kunde.de", "wpsync/0.2.0", true)
	if err != nil || !changed {
		t.Fatalf("first write: changed = %v, err = %v", changed, err)
	}
	for _, p := range []string{zone, server, filepath.Join(dir, ".wpsync", "proxy-tmp")} {
		if _, err := os.Stat(p); err != nil {
			t.Errorf("%s missing", p)
		}
	}
	if changed, _ := WriteUploadsProxy(dir, "https://kunde.de", "wpsync/0.2.0", true); changed {
		t.Error("unchanged config reported as changed (would restart DDEV on every pull)")
	}
	if changed, _ := WriteUploadsProxy(dir, "https://kunde.de", "wpsync/0.2.0", false); !changed {
		t.Error("disabling must report a change")
	}
	if _, err := os.Stat(server); !os.IsNotExist(err) {
		t.Error("disabled proxy config still present")
	}
	if changed, _ := WriteUploadsProxy(dir, "https://kunde.de", "wpsync/0.2.0", false); changed {
		t.Error("nothing to remove, but reported a change")
	}
}

// SEC-113: .wpsync/ liegt im DDEV-Mount; ein Symlink dort lenkt proxy-tmp nicht nach außen.
func TestWriteUploadsProxyRefusesSymlinkedStateDir(t *testing.T) {
	dir, outside := t.TempDir(), t.TempDir()
	os.Symlink(outside, filepath.Join(dir, ".wpsync"))
	if _, err := WriteUploadsProxy(dir, "https://kunde.de", "wpsync/0.2.0", true); err == nil {
		t.Fatal("symlinked .wpsync must be refused")
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("created outside: %v", entries)
	}
}
