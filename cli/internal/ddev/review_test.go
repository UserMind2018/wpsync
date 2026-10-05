package ddev

import (
	"bytes"
	"strings"
	"testing"
)

// AC-13: vor der Freigabe werden hooks, exec-host, Host-Kommandos und zusätzliche Mounts
// hervorgehoben.
func TestDescribeHighlightsRisks(t *testing.T) {
	site := t.TempDir()
	writeDDEV(t, site, "config.local.yaml", "hooks:\n  post-start:\n    - exec-host: id -un > /tmp/x\n  pre-stop:\n    - exec: true\nperformance_mode: mutagen\n")
	writeDDEV(t, site, "commands/host/x", "#!/bin/sh\nid\n")
	writeDDEV(t, site, "commands/web/wp", "#!/bin/sh\n")
	writeDDEV(t, site, "docker-compose.addon.yaml", `services:
  addon:
    privileged: true
    cap_add: [SYS_ADMIN]
    volumes:
      - ".:/cfg"
      - "/var/run/docker.sock:/var/run/docker.sock"
      - "../:/site"
      - "./data:/data"
      - type: bind
        source: /Users/u
        target: /home
  web:
    volumes:
      - "..:/var/www/html:ro"
`)
	writeDDEV(t, site, "docker-compose.broken.yaml", "services: [\n")
	writeDDEV(t, site, ".env", "X=1\n")
	writeDDEV(t, site, "providers/x.yaml", "x")
	changes := []Change{
		{Path: "commands/host/x", Kind: ChangeAdded},
		{Path: "commands/web/wp", Kind: ChangeAdded},
		{Path: "config.local.yaml", Kind: ChangeAdded},
		{Path: "docker-compose.addon.yaml", Kind: ChangeChanged},
		{Path: "docker-compose.broken.yaml", Kind: ChangeAdded},
		{Path: ".env", Kind: ChangeAdded},
		{Path: "providers/x.yaml", Kind: ChangeAdded},
		{Path: "commands/host/link", Kind: ChangeAdded, Unsafe: true},
	}
	var out bytes.Buffer
	if n := Describe(&out, site, changes, nil, false); n != len(changes) {
		t.Fatalf("listed %d", n)
	}
	got := out.String()
	for _, want := range []string{
		"! Host-Kommando",
		"Container-Kommando",
		"! hooks: post-start, pre-stop",
		"! exec-host",
		"performance_mode",
		"! addon: privileged",
		"! addon: cap_add",
		"! addon: Mount .:/cfg",
		"! addon: Mount /var/run/docker.sock:/var/run/docker.sock",
		"! addon: Mount ../:/site",
		"! addon: Mount /Users/u:/home",
		"addon: Mount ./data:/data",
		"web: Mount ..:/var/www/html:ro",
		"! nicht lesbar",
		"Umgebungsvariablen",
		"Provider-Skript",
		"nicht freigebbar",
	} {
		if !strings.Contains(got, want) {
			t.Errorf("output lacks %q:\n%s", want, got)
		}
	}
	for _, notRisky := range []string{"! addon: Mount ./data", "! web: Mount ..:/var/www/html:ro"} {
		if strings.Contains(got, notRisky) {
			t.Errorf("output flags %q:\n%s", notRisky, got)
		}
	}
}

// AC-14: Übernahme zeigt hooks in config.yaml, jede config.*.yaml, fremde Compose-Dateien,
// commands ausser README.txt/.gitattributes und .env*; DDEV-Generiertes und wpsyncs eigene
// Dateien nicht.
func TestDescribeTakeover(t *testing.T) {
	src := mailguardFile(t)
	own, err := OwnFiles(src)
	if err != nil {
		t.Fatal(err)
	}
	site := t.TempDir()
	writeDDEV(t, site, "config.yaml", "name: kunde\nhooks:\n  post-start:\n    - exec-host: open .\n")
	writeDDEV(t, site, "config.local.yaml", "php_version: \"8.3\"\n")
	writeDDEV(t, site, MailguardComposeFile, own[MailguardComposeFile])
	writeDDEV(t, site, "docker-compose.schema-graph.yaml", "services:\n  web:\n    volumes:\n      - \"/Users/u/repo:/var/www/html/public/wp-content/plugins/x\"\n")
	writeDDEV(t, site, ".ddev-docker-compose-full.yaml", "services: {}\n")
	writeDDEV(t, site, "commands/web/README.txt", "#ddev-generated")
	writeDDEV(t, site, "commands/.gitattributes", "#ddev-generated")
	writeDDEV(t, site, "commands/host/launch", "#ddev-generated\n")
	writeDDEV(t, site, ".env.web", "X=1")
	writeDDEV(t, site, "providers/example.yaml", "#ddev-generated\n")
	tree, err := Snapshot(site)
	if err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	Describe(&out, site, Diff(Tree{}, tree), own, true)
	got := out.String()
	for _, want := range []string{"config.yaml", "! hooks: post-start", "config.local.yaml", "docker-compose.schema-graph.yaml",
		"! web: Mount /Users/u/repo", "commands/host/launch", ".env.web", "unauffällige Dateien"} {
		if !strings.Contains(got, want) {
			t.Errorf("takeover lacks %q:\n%s", want, got)
		}
	}
	for _, hidden := range []string{MailguardComposeFile, ".ddev-docker-compose-full.yaml", "README.txt", ".gitattributes", "providers/example.yaml"} {
		if strings.Contains(got, hidden) {
			t.Errorf("takeover lists %q:\n%s", hidden, got)
		}
	}

	// Nothing remarkable: one line.
	clean := t.TempDir()
	writeDDEV(t, clean, "config.yaml", "name: kunde\n")
	writeDDEV(t, clean, MailguardComposeFile, own[MailguardComposeFile])
	writeDDEV(t, clean, HardeningComposeFile, own[HardeningComposeFile])
	writeDDEV(t, clean, "commands/web/README.txt", "#ddev-generated")
	tree, _ = Snapshot(clean)
	out.Reset()
	if n := Describe(&out, clean, Diff(Tree{}, tree), own, true); n != 0 || strings.Count(out.String(), "\n") != 1 || !strings.Contains(out.String(), "nichts Auffälliges") {
		t.Fatalf("clean takeover (%d):\n%s", n, out.String())
	}

	// A modified own file is remarkable.
	writeDDEV(t, clean, MailguardComposeFile, "services: {}\n")
	tree, _ = Snapshot(clean)
	out.Reset()
	Describe(&out, clean, Diff(Tree{}, tree), own, true)
	if !strings.Contains(out.String(), MailguardComposeFile) {
		t.Fatalf("modified own file not listed:\n%s", out.String())
	}
}

// Hook-Namen und Mounts stammen aus Dateien der Site: keine Steuerzeichen roh ausgeben.
func TestDescribeEscapesNotes(t *testing.T) {
	site := t.TempDir()
	writeDDEV(t, site, "config.x.yaml", "hooks:\n  \"post-start\\e[2J\\u202e\":\n    - exec: true\n")
	var out bytes.Buffer
	Describe(&out, site, []Change{{Path: "config.x.yaml", Kind: ChangeAdded}}, nil, false)
	if strings.ContainsAny(out.String(), "\x1b\u202e") || !strings.Contains(out.String(), "! hooks: post-start?[2J?") {
		t.Fatalf("output = %q", out.String())
	}
}
