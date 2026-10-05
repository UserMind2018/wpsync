package ddev

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writeDDEV(t *testing.T, siteDir, rel, content string) {
	t.Helper()
	p := filepath.Join(siteDir, ".ddev", filepath.FromSlash(rel))
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

// baseSite is a pulled site: DDEV's config, wpsync's own files, generated compose files and paths
// that only act inside the containers.
func baseSite(t *testing.T) string {
	t.Helper()
	site := t.TempDir()
	writeDDEV(t, site, "config.yaml", "name: kunde\ntype: wordpress\ndocroot: public\n")
	writeDDEV(t, site, MailguardComposeFile, MailguardCompose("/cfg/00-local-mailguard.php"))
	writeDDEV(t, site, HardeningComposeFile, HardeningCompose())
	writeDDEV(t, site, ".ddev-docker-compose-base.yaml", "services: {}\n")
	writeDDEV(t, site, ".ddev-docker-compose-full.yaml", "services: {}\n")
	writeDDEV(t, site, "commands/web/README.txt", "#ddev-generated\n")
	writeDDEV(t, site, "commands/.gitattributes", "#ddev-generated\n")
	writeDDEV(t, site, "nginx/wpsync-uploads-proxy.conf", "location / {}\n")
	writeDDEV(t, site, "db_snapshots/s1.zst", "dump")
	return site
}

func trustedProject(t *testing.T, site string) *Project {
	t.Helper()
	store := Store{Dir: filepath.Join(t.TempDir(), "ddev-state")}
	p, err := OpenProject("kunde", site, store, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := p.Accept(); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestProtectedPaths(t *testing.T) {
	protected := []string{
		"config.yaml", "Config.Yaml", "CONFIG.YAML", "config.local.yaml", "config.x.yml", "Config.Audit.YAML",
		"docker-compose.mailguard.yaml", "docker-compose.x.yml", "Docker-Compose.X.Yaml",
		"commands/host/x", "commands/web/wp", "commands/db/mysql", "commands/README.txt",
		".env", ".env.local", ".ENV.web", "providers/x.yaml", "share-providers/cf.sh",
		".ddev-docker-compose-full.yaml", ".ddev-docker-compose-base.yaml",
	}
	for _, p := range protected {
		if !Protected(p) {
			t.Errorf("Protected(%q) = false", p)
		}
	}
	free := []string{
		"nginx/x.conf", "nginx_full/nginx-site.conf", "php/x.ini", "mysql/x.cnf", "web-build/Dockerfile",
		"web-entrypoint.d/x.sh", "homeadditions/.bashrc", "db_snapshots/s.zst", "traefik/config/kunde.yaml",
		"apache/apache-site.conf", "xhprof/x.php", ".webimageBuild/Dockerfile", "addon-metadata/x/manifest.yaml",
		"web-build/config.yaml", "nginx/docker-compose.x.yaml", "config.yaml.bak", "docker-compose.txt",
	}
	for _, p := range free {
		if Protected(p) {
			t.Errorf("Protected(%q) = true", p)
		}
	}
}

// AC-7: jede Abweichungsklasse bricht einzeln ab – vor jedem Aufruf, auch vor start.
func TestCheckDetectsEveryDeviationClass(t *testing.T) {
	cases := []struct {
		name   string
		change func(t *testing.T, site string)
		path   string
		kind   string
		unsafe bool
	}{
		{"neue config.x.yaml", func(t *testing.T, s string) {
			writeDDEV(t, s, "config.audit.yaml", "hooks:\n  post-start:\n    - exec-host: id -un > /tmp/x\n")
		}, "config.audit.yaml", ChangeAdded, false},
		{"config.local.yaml", func(t *testing.T, s string) { writeDDEV(t, s, "config.local.yaml", "php_version: \"8.3\"\n") }, "config.local.yaml", ChangeAdded, false},
		{"config.yaml mit hooks", func(t *testing.T, s string) {
			writeDDEV(t, s, "config.yaml", "name: kunde\nhooks:\n  pre-stop:\n    - exec-host: touch /tmp/x\n")
		}, "config.yaml", ChangeChanged, false},
		{"Grossschreibung", func(t *testing.T, s string) { writeDDEV(t, s, "CONFIG.EVIL.YAML", "hooks: {}\n") }, "CONFIG.EVIL.YAML", ChangeAdded, false},
		{".yml statt .yaml (config)", func(t *testing.T, s string) { writeDDEV(t, s, "config.x.yml", "hooks: {}\n") }, "config.x.yml", ChangeAdded, false},
		{"neue docker-compose", func(t *testing.T, s string) {
			writeDDEV(t, s, "docker-compose.evil.yaml", "services:\n  web:\n    volumes:\n      - \".:/var/www/html/.ddev\"\n")
		}, "docker-compose.evil.yaml", ChangeAdded, false},
		{".yml statt .yaml (compose)", func(t *testing.T, s string) { writeDDEV(t, s, "docker-compose.evil.yml", "services: {}\n") }, "docker-compose.evil.yml", ChangeAdded, false},
		{"geänderte Mailguard-Compose", func(t *testing.T, s string) {
			writeDDEV(t, s, MailguardComposeFile, "services: {}\n")
		}, MailguardComposeFile, ChangeChanged, false},
		{"gelöschte Härtung", func(t *testing.T, s string) {
			os.Remove(filepath.Join(s, ".ddev", HardeningComposeFile))
		}, HardeningComposeFile, ChangeMissing, false},
		{"commands/host", func(t *testing.T, s string) { writeDDEV(t, s, "commands/host/x", "#!/bin/sh\nid\n") }, "commands/host/x", ChangeAdded, false},
		{"commands/web", func(t *testing.T, s string) { writeDDEV(t, s, "commands/web/wp", "#!/bin/sh\n") }, "commands/web/wp", ChangeAdded, false},
		{"commands/db", func(t *testing.T, s string) { writeDDEV(t, s, "commands/db/mysql", "#!/bin/sh\n") }, "commands/db/mysql", ChangeAdded, false},
		{"geänderte Datei unter commands", func(t *testing.T, s string) { writeDDEV(t, s, "commands/web/README.txt", "x") }, "commands/web/README.txt", ChangeChanged, false},
		{".env", func(t *testing.T, s string) { writeDDEV(t, s, ".env", "COMPOSE_FILE=/x\n") }, ".env", ChangeAdded, false},
		{".env.web", func(t *testing.T, s string) { writeDDEV(t, s, ".env.web", "X=1\n") }, ".env.web", ChangeAdded, false},
		{"providers", func(t *testing.T, s string) { writeDDEV(t, s, "providers/x.yaml", "auth_command: id\n") }, "providers/x.yaml", ChangeAdded, false},
		{"share-providers", func(t *testing.T, s string) { writeDDEV(t, s, "share-providers/x.sh", "id\n") }, "share-providers/x.sh", ChangeAdded, false},
		{"Symlink unter commands/host", func(t *testing.T, s string) {
			os.MkdirAll(filepath.Join(s, ".ddev", "commands", "host"), 0o755)
			if err := os.Symlink("/bin/sh", filepath.Join(s, ".ddev", "commands", "host", "x")); err != nil {
				t.Fatal(err)
			}
		}, "commands/host/x", ChangeAdded, true},
		{"config.yaml durch Symlink ersetzt", func(t *testing.T, s string) {
			p := filepath.Join(s, ".ddev", "config.yaml")
			os.Remove(p)
			if err := os.Symlink("/etc/hosts", p); err != nil {
				t.Fatal(err)
			}
		}, "config.yaml", ChangeChanged, true},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			site := baseSite(t)
			p := trustedProject(t, site)
			c.change(t, site)
			for _, call := range [][]string{{"describe", "-j"}, {"wp", "eval", "x"}, {"mysql"}, {"stop"}, {"start", "-y"}, {"restart"}} {
				err := p.Check(call...)
				var dev *DeviationError
				if !errors.As(err, &dev) {
					t.Fatalf("Check(%v) = %v, want DeviationError", call, err)
				}
				if len(dev.Changes) != 1 || dev.Changes[0].Path != c.path || dev.Changes[0].Kind != c.kind || dev.Changes[0].Unsafe != c.unsafe {
					t.Fatalf("Check(%v) changes = %+v, want %s %s unsafe=%v", call, dev.Changes, c.kind, c.path, c.unsafe)
				}
			}
		})
	}
}

// D2: DDEVs generierte Compose-Dateien zählen vor wp/mysql/describe/stop, nicht vor start/restart.
func TestGeneratedComposeIgnoredOnlyBeforeStart(t *testing.T) {
	site := baseSite(t)
	p := trustedProject(t, site)
	writeDDEV(t, site, ".ddev-docker-compose-full.yaml", "services:\n  web:\n    volumes: [\"/:/host\"]\n")
	for _, call := range [][]string{{"wp", "eval", "x"}, {"mysql"}, {"describe", "-j"}, {"stop"}, {"config"}} {
		var dev *DeviationError
		if err := p.Check(call...); !errors.As(err, &dev) || dev.Changes[0].Path != ".ddev-docker-compose-full.yaml" {
			t.Fatalf("Check(%v) = %v, want deviation of the generated compose file", call, err)
		}
	}
	for _, call := range [][]string{{"start", "-y"}, {"restart"}} {
		if err := p.Check(call...); err != nil {
			t.Fatalf("Check(%v) = %v", call, err)
		}
	}
	// A symlink is never ignored, not even before start.
	gen := filepath.Join(site, ".ddev", ".ddev-docker-compose-base.yaml")
	os.Remove(gen)
	if err := os.Symlink("/etc/hosts", gen); err != nil {
		t.Fatal(err)
	}
	var dev *DeviationError
	if err := p.Check("start", "-y"); !errors.As(err, &dev) {
		t.Fatalf("Check(start) with symlinked generated file = %v", err)
	}
}

// AC-9 (Unit-Teil): Pfade, die nur im Container wirken, DDEV-Generiertes in nicht geschützten
// Ordnern, db_snapshots und der Uploads-Proxy lösen nichts aus.
func TestCheckIgnoresUnprotectedPaths(t *testing.T) {
	site := baseSite(t)
	p := trustedProject(t, site)
	writeDDEV(t, site, "nginx/wpsync-uploads-proxy.conf", "changed")
	os.Remove(filepath.Join(site, ".ddev", "nginx/wpsync-uploads-proxy.conf"))
	writeDDEV(t, site, "nginx_full/wpsync-uploads-zone.conf", "proxy_cache_path /x;")
	writeDDEV(t, site, "nginx_full/nginx-site.conf", "#ddev-generated")
	writeDDEV(t, site, "apache/apache-site.conf", "#ddev-generated")
	writeDDEV(t, site, "traefik/config/kunde.yaml", "#ddev-generated")
	writeDDEV(t, site, ".webimageBuild/Dockerfile", "FROM x")
	writeDDEV(t, site, ".dbimageBuild/Dockerfile", "FROM x")
	writeDDEV(t, site, ".homeadditions/.bashrc", "x")
	writeDDEV(t, site, "xhprof/xhprof_prepend.php", "<?php")
	writeDDEV(t, site, "db_snapshots/s2.zst", "dump2")
	writeDDEV(t, site, "db_snapshots/config.evil.yaml", "hooks: {}")
	writeDDEV(t, site, "php/x.ini", "x=1")
	for _, call := range [][]string{{"start", "-y"}, {"wp", "eval", "x"}, {"stop"}} {
		if err := p.Check(call...); err != nil {
			t.Fatalf("Check(%v) = %v", call, err)
		}
	}
}

func TestSnapshotSkipsDBSnapshotsAndRefusesSymlinkedDDEV(t *testing.T) {
	site := baseSite(t)
	tree, err := Snapshot(site)
	if err != nil {
		t.Fatal(err)
	}
	for p := range tree {
		if strings.HasPrefix(p, "db_snapshots") || strings.HasPrefix(p, "nginx") {
			t.Errorf("snapshot contains %s", p)
		}
	}
	if _, ok := tree["config.yaml"]; !ok {
		t.Errorf("config.yaml missing: %v", tree)
	}

	empty, err := Snapshot(t.TempDir())
	if err != nil || len(empty) != 0 {
		t.Fatalf("Snapshot without .ddev = %v, %v", empty, err)
	}

	linked := t.TempDir()
	if err := os.Symlink(filepath.Join(site, ".ddev"), filepath.Join(linked, ".ddev")); err != nil {
		t.Fatal(err)
	}
	if _, err := Snapshot(linked); !errors.Is(err, ErrDDEVSymlink) {
		t.Fatalf("Snapshot(.ddev symlink) = %v, want ErrDDEVSymlink", err)
	}
	p, err := OpenProject("kunde", linked, Store{Dir: t.TempDir()}, nil)
	if err != nil {
		t.Fatal(err)
	}
	p.StartFresh()
	if err := p.Check("stop"); !errors.Is(err, ErrDDEVSymlink) {
		t.Fatalf("Check with .ddev symlink = %v", err)
	}
}

// AC-8: deutsche Meldung, nennt Site, jede Datei mit Art, nichts ausgeführt, nächster Schritt;
// Steuerzeichen nicht roh.
func TestDeviationErrorMessage(t *testing.T) {
	err := &DeviationError{Site: "kunde", Changes: []Change{
		{Path: "commands/host/\x1b[2Jevil\x1b]0;x\x07", Kind: ChangeAdded},
		{Path: "config.yaml", Kind: ChangeChanged},
		{Path: HardeningComposeFile, Kind: ChangeMissing},
		{Path: "commands/host/link", Kind: ChangeAdded, Unsafe: true},
	}}
	msg := err.Error()
	for _, want := range []string{
		"kunde", "es wurde kein ddev-Befehl ausgeführt",
		"neu:", "geändert:", "fehlt:", `commands/host/\x1b[2Jevil\x1b]0;x\a`, "config.yaml", HardeningComposeFile,
		"nicht freigebbar", "entfernen", "wpsync trust kunde",
	} {
		if !strings.Contains(msg, want) {
			t.Errorf("message lacks %q:\n%s", want, msg)
		}
	}
	for _, bad := range []string{"\x1b", "\x07"} {
		if strings.Contains(msg, bad) {
			t.Errorf("message contains raw %q:\n%q", bad, msg)
		}
	}

	fresh := (&DeviationError{Site: "kunde", Fresh: true, Changes: []Change{{Path: "commands/host/x", Kind: ChangeAdded}}}).Error()
	if !strings.Contains(fresh, "Vor dem ersten Pull von kunde") || !strings.Contains(fresh, "kein ddev-Befehl ausgeführt") || strings.Contains(fresh, "wpsync trust") {
		t.Errorf("fresh message:\n%s", fresh)
	}
}

// AC-8 an echter Datei: ein Dateiname mit Escape-Sequenz kommt entschärft in der Meldung an.
func TestDeviationErrorFromFileWithEscapeInName(t *testing.T) {
	site := baseSite(t)
	p := trustedProject(t, site)
	name := "docker-compose.\x1b[31mevil.yaml"
	if err := os.WriteFile(filepath.Join(site, ".ddev", name), []byte("services: {}\n"), 0o644); err != nil {
		t.Skipf("file system refuses name: %v", err)
	}
	err := p.Check("start", "-y")
	if err == nil || strings.Contains(err.Error(), "\x1b") || !strings.Contains(err.Error(), `\x1b[31mevil`) {
		t.Fatalf("err = %q", err)
	}
}

func TestDiffAndFingerprint(t *testing.T) {
	want := Tree{"a": {Kind: KindFile, SHA256: "1"}, "b": {Kind: KindFile, SHA256: "2"}}
	got := Tree{"a": {Kind: KindFile, SHA256: "1"}, "b": {Kind: KindFile, SHA256: "3"}, "c": {Kind: KindFile, SHA256: "4"}}
	changes := Diff(want, got)
	if len(changes) != 2 || changes[0].Path != "b" || changes[0].Kind != ChangeChanged || changes[1].Path != "c" || changes[1].Kind != ChangeAdded {
		t.Fatalf("Diff = %+v", changes)
	}
	if len(Diff(want, want)) != 0 {
		t.Fatal("equal trees must not differ")
	}
	fp := Fingerprint(changes, got)
	if fp != Fingerprint(Diff(want, got), got) {
		t.Fatal("fingerprint not stable")
	}
	got2 := Tree{"a": {Kind: KindFile, SHA256: "1"}, "b": {Kind: KindFile, SHA256: "3"}, "c": {Kind: KindFile, SHA256: "5"}}
	if fp == Fingerprint(Diff(want, got2), got2) {
		t.Fatal("fingerprint must change with the content")
	}
}

// AC-12: Ablage ausserhalb des Sites-Ordners, Datei 0600, Ordner 0700.
func TestStoreLocationAndMode(t *testing.T) {
	sitesRoot, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	for _, cfg := range []string{sitesRoot, filepath.Join(sitesRoot, "kunde"), filepath.Join(sitesRoot, "kunde", ".wpsync")} {
		if _, err := NewStore(cfg, sitesRoot); err == nil {
			t.Errorf("NewStore(%s) inside sites root accepted", cfg)
		}
	}

	cfg := t.TempDir()
	store, err := NewStore(cfg, sitesRoot)
	if err != nil {
		t.Fatal(err)
	}
	if within(filepath.Join(sitesRoot, "kunde"), store.Path("kunde")) || within(sitesRoot, store.Path("kunde")) {
		t.Fatalf("state %s inside sites root", store.Path("kunde"))
	}
	if store.Path("../../evil") != filepath.Join(store.Dir, "evil.json") {
		t.Fatalf("Path escapes the store: %s", store.Path("../../evil"))
	}
	tree := Tree{"config.yaml": {Kind: KindFile, SHA256: "ab"}}
	if err := store.Save("kunde", tree); err != nil {
		t.Fatal(err)
	}
	fi, err := os.Stat(store.Path("kunde"))
	if err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("state file mode = %v, %v", fi.Mode().Perm(), err)
	}
	di, err := os.Stat(store.Dir)
	if err != nil || di.Mode().Perm() != 0o700 {
		t.Fatalf("state dir mode = %v, %v", di.Mode().Perm(), err)
	}
	loaded, ok, err := store.Load("kunde")
	if err != nil || !ok || loaded["config.yaml"] != tree["config.yaml"] {
		t.Fatalf("Load = %v, %v, %v", loaded, ok, err)
	}
	if _, ok, err := store.Load("andere"); ok || err != nil {
		t.Fatalf("Load(unknown) = %v, %v", ok, err)
	}
	os.WriteFile(store.Path("kaputt"), []byte(`{"version":99,"trees":{}}`), 0o600)
	if _, _, err := store.Load("kaputt"); err == nil {
		t.Fatal("unknown format accepted")
	}
}

// AC-12: auch über einen Symlink im Pfad (macOS: /var → /private/var, /tmp → /private/tmp) darf
// der Stand nicht im Sites-Ordner landen – auch beim ersten Mal, wenn ddev-state noch fehlt.
func TestStoreRefusesSitesRootThroughSymlink(t *testing.T) {
	real, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(t.TempDir(), "sites")
	if err := os.Symlink(real, link); err != nil {
		t.Fatal(err)
	}
	cases := map[string][2]string{
		"config über Symlink, Sites real": {filepath.Join(link, "kunde"), real},
		"config real, Sites über Symlink": {filepath.Join(real, "kunde"), link},
		"config = Sites über Symlink":     {link, real},
	}
	for name, c := range cases {
		if _, err := NewStore(c[0], c[1]); err == nil {
			t.Errorf("%s: NewStore(%s, %s) accepted", name, c[0], c[1])
		}
	}
}
