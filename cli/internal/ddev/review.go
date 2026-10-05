package ddev

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"unicode"

	"gopkg.in/yaml.v3"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Notes points out what in a protected file reaches the Mac or widens a container: hooks,
// exec-host, host commands, extra mounts, privileges. Shown before the user trusts .ddev.
func Notes(siteDir, rel string) []string {
	return notesFor(rel, readContent(siteDir, rel))
}

// notesFor works on the bytes that were hashed, so the notes describe exactly what gets trusted.
// nil data means the file could not be read.
func notesFor(rel string, data []byte) []string {
	lower := strings.ToLower(rel)
	switch {
	case strings.HasPrefix(lower, "commands/host/"):
		return []string{"! Host-Kommando – läuft als dein Benutzer auf dem Mac"}
	case strings.HasPrefix(lower, "commands/"):
		return []string{"Container-Kommando – kann ddev-Befehle wie wp oder mysql überdecken"}
	case strings.HasPrefix(lower, "providers/"), strings.HasPrefix(lower, "share-providers/"):
		return []string{"Provider-Skript – läuft bei ddev pull/push/share auch auf dem Mac"}
	case lower == ".env" || strings.HasPrefix(lower, ".env."):
		return []string{"Umgebungsvariablen für DDEV und Docker Compose"}
	case strings.HasPrefix(lower, "config."):
		return configNotes(data)
	case strings.HasPrefix(lower, "docker-compose."), strings.HasPrefix(lower, generatedPrefix):
		return composeNotes(data)
	}
	return nil
}

func readContent(siteDir, rel string) []byte {
	data, err := os.ReadFile(filepath.Join(siteDir, ".ddev", filepath.FromSlash(rel)))
	if err != nil {
		return nil
	}
	return data
}

func parseYAML(data []byte) (map[string]any, bool) {
	if data == nil {
		return nil, false
	}
	var doc map[string]any
	if err := yaml.Unmarshal(data, &doc); err != nil {
		return nil, false
	}
	return doc, true
}

const unreadable = "! nicht lesbar – von Hand prüfen"

func configNotes(data []byte) []string {
	doc, ok := parseYAML(data)
	if !ok {
		return []string{unreadable}
	}
	var out []string
	hooks, isMap := doc["hooks"].(map[string]any)
	switch {
	case isMap && len(hooks) > 0:
		names := make([]string, 0, len(hooks))
		for k := range hooks {
			names = append(names, k)
		}
		sort.Strings(names)
		out = append(out, "! hooks: "+strings.Join(names, ", "))
	case doc["hooks"] != nil:
		out = append(out, "! hooks")
	}
	if hasExecHost(hooks) {
		out = append(out, "! exec-host – führt Befehle auf dem Mac aus")
	}
	for _, key := range []string{"performance_mode", "upload_dirs", "web_extra_daemons", "web_extra_exposed_ports"} {
		if _, ok := doc[key]; ok {
			out = append(out, key)
		}
	}
	return out
}

// hasExecHost looks for an exec-host task in any hook of the parsed config.
func hasExecHost(hooks map[string]any) bool {
	for _, tasks := range hooks {
		list, _ := tasks.([]any)
		for _, t := range list {
			if task, ok := t.(map[string]any); ok {
				if _, ok := task["exec-host"]; ok {
					return true
				}
			}
		}
	}
	return false
}

func composeNotes(data []byte) []string {
	doc, ok := parseYAML(data)
	if !ok {
		return []string{unreadable}
	}
	var out []string
	if inc, ok := doc["include"]; ok {
		out = append(out, fmt.Sprintf("! include: lädt weitere Compose-Dateien (%v)", inc))
	}
	services, _ := doc["services"].(map[string]any)
	names := make([]string, 0, len(services))
	for name := range services {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		svc, _ := services[name].(map[string]any)
		for _, key := range []string{"privileged", "cap_add", "pid", "network_mode", "devices", "security_opt"} {
			if _, ok := svc[key]; ok {
				out = append(out, fmt.Sprintf("! %s: %s", name, key))
			}
		}
		if ext, ok := svc["extends"].(map[string]any); ok && ext["file"] != nil {
			out = append(out, fmt.Sprintf("! %s: extends aus Datei %v", name, ext["file"]))
		}
		if env, ok := svc["env_file"]; ok {
			out = append(out, fmt.Sprintf("! %s: env_file %v", name, env))
		}
		if ctx := buildContext(svc["build"]); ctx != "" && outsideDDEV(ctx) {
			out = append(out, fmt.Sprintf("! %s: build context %s", name, ctx))
		}
		vols, _ := svc["volumes"].([]any)
		for _, v := range vols {
			mount := volumeString(v)
			mark := ""
			if riskyMount(mount) {
				mark = "! "
			}
			out = append(out, fmt.Sprintf("%s%s: Mount %s", mark, name, mount))
		}
	}
	return out
}

func buildContext(b any) string {
	switch v := b.(type) {
	case string:
		return v
	case map[string]any:
		if ctx, ok := v["context"].(string); ok {
			return ctx
		}
		return "."
	}
	return ""
}

// outsideDDEV: a compose path that leaves .ddev (absolute, home, variable or parent).
func outsideDDEV(p string) bool {
	return strings.HasPrefix(p, "/") || strings.HasPrefix(p, "~") || strings.HasPrefix(p, "$") ||
		strings.HasPrefix(p, "..") || strings.Contains(p, "://")
}

func volumeString(v any) string {
	switch m := v.(type) {
	case string:
		return m
	case map[string]any:
		s := fmt.Sprintf("%v:%v", m["source"], m["target"])
		if ro, _ := m["read_only"].(bool); ro {
			s += ":ro"
		}
		return s
	}
	return fmt.Sprint(v)
}

// riskyMount: a writable mount of .ddev or a parent of it, the docker socket, or a host path
// outside the site.
func riskyMount(mount string) bool {
	if strings.Contains(mount, "docker.sock") {
		return true
	}
	src, rest, _ := strings.Cut(mount, ":")
	readOnly := strings.HasSuffix(rest, ":ro")
	switch {
	case src == "." || src == "./" || strings.HasPrefix(src, ".."):
		return !readOnly
	case strings.HasPrefix(src, "/"), strings.HasPrefix(src, "~"), strings.HasPrefix(src, "$"):
		return !readOnly
	case strings.Contains(rest, "ddev_config"), strings.Contains(rest, "/.ddev"):
		return !readOnly
	}
	return false
}

// Describe lists changes with their notes, reading the files from disk. For a takeover only
// remarkable files are listed, the rest is counted. Returns how many files were listed.
func Describe(w io.Writer, siteDir string, changes []Change, own map[string]string, takeover bool) int {
	content := map[string][]byte{}
	for _, c := range changes {
		if data := readContent(siteDir, c.Path); data != nil {
			content[c.Path] = data
		}
	}
	return DescribeContent(w, changes, content, own, takeover)
}

// DescribeContent is Describe on the bytes SnapshotContent hashed: what the user approves is
// what was shown.
func DescribeContent(w io.Writer, changes []Change, content map[string][]byte, own map[string]string, takeover bool) int {
	listed, quiet := 0, 0
	for _, c := range changes {
		if takeover && c.Kind == ChangeAdded && !c.Unsafe && !remarkable(c.Path, content[c.Path], own) {
			quiet++
			continue
		}
		listed++
		label := c.Kind
		if takeover {
			label = "prüfen"
		}
		fmt.Fprintf(w, "  %-9s %s\n", label+":", agentapi.Printable(c.Path))
		if c.Unsafe {
			fmt.Fprintln(w, "            ! Symlink/keine normale Datei – nicht freigebbar, erst entfernen")
			continue
		}
		if c.Kind == ChangeMissing {
			continue
		}
		for _, n := range notesFor(c.Path, content[c.Path]) {
			fmt.Fprintf(w, "            %s\n", plain(n))
		}
	}
	if takeover && listed == 0 {
		fmt.Fprintf(w, "  nichts Auffälliges (%d Dateien von DDEV und wpsync)\n", quiet)
	} else if takeover && quiet > 0 {
		fmt.Fprintf(w, "  dazu %d unauffällige Dateien von DDEV und wpsync\n", quiet)
	}
	return listed
}

// maxNoteRunes caps a note: hook names and mounts come from files the site may have written.
const maxNoteRunes = 160

// plain keeps German text readable but replaces control and format characters (escape
// sequences, bidi overrides) and cuts overlong notes.
func plain(s string) string {
	r := []rune(strings.Map(func(c rune) rune {
		if unicode.IsControl(c) || unicode.Is(unicode.Cf, c) {
			return '?'
		}
		return c
	}, s))
	if len(r) > maxNoteRunes {
		return string(r[:maxNoteRunes]) + "…"
	}
	return string(r)
}

// Remarkable reports whether rel deserves a look when an existing site is taken over: everything
// except DDEV's own generated files, an unchanged own compose file and config.yaml without hooks.
func Remarkable(siteDir, rel string, own map[string]string) bool {
	return remarkable(rel, readContent(siteDir, rel), own)
}

func remarkable(rel string, data []byte, own map[string]string) bool {
	lower := strings.ToLower(rel)
	switch {
	case strings.HasPrefix(lower, generatedPrefix):
		return false
	case strings.HasPrefix(lower, "commands/"):
		base := filepath.Base(lower)
		return base != "readme.txt" && base != ".gitattributes"
	case strings.HasPrefix(lower, "providers/"), strings.HasPrefix(lower, "share-providers/"):
		return data == nil || !strings.Contains(string(data), ddevMarker)
	case lower == "config.yaml":
		for _, n := range configNotes(data) {
			if strings.HasPrefix(n, "!") {
				return true
			}
		}
		return false
	}
	if want, ok := own[rel]; ok {
		return data == nil || string(data) != want
	}
	return true
}
