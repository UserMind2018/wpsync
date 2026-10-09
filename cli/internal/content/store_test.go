package content

import (
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
)

func write(t *testing.T, path, data string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(data), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestPaths(t *testing.T) {
	f := Paths("/sites/kunde")
	if f.Dir != "/sites/kunde/.wpsync/content" || f.Manifest != f.Dir+"/manifest.jsonl" || f.Map != f.Dir+"/map.json" ||
		f.Baseline != f.Dir+"/baseline.jsonl" || f.Unfaithful != f.Dir+"/unfaithful.jsonl" || f.Summary != f.Dir+"/summary.json" || f.Env != f.Dir+"/env.json" {
		t.Fatalf("%+v", f)
	}
}

func TestCompareFindsUnfaithfulRows(t *testing.T) {
	dir := t.TempDir()
	f := Paths(dir)
	write(t, f.Manifest, `{"head":{"canon_version":1}}`+"\n"+
		`{"t":"posts","k":"1","h":"aa"}`+"\n"+
		`{"t":"posts","k":"2","h":"bb"}`+"\n"+
		`{"t":"posts","k":"3","h":null,"why":"unnormalizable"}`+"\n"+
		`{"t":"posts","k":"4","h":"dd"}`+"\n"+
		`{"t":"postmeta","k":"1\u0000_x","h":"ee"}`+"\n"+
		`{"t":"options","k":"only_live","h":"ff"}`+"\n")
	write(t, f.Baseline, `{"t":"posts","k":"1","h":"aa","row":{}}`+"\n"+
		`{"t":"posts","k":"2","h":"XX","row":{}}`+"\n"+
		`{"t":"posts","k":"3","h":"cc","row":{}}`+"\n"+
		`{"t":"posts","k":"4","h":null,"why":"unnormalizable"}`+"\n"+
		`{"t":"postmeta","k":"1\u0000_x","h":"ee","row":{}}`+"\n"+
		`{"t":"options","k":"only_local","h":"gg","row":{}}`+"\n")
	rows, bad, err := Compare(dir)
	if err != nil {
		t.Fatal(err)
	}
	if rows != 6 || bad != 4 {
		t.Fatalf("rows=%d bad=%d", rows, bad)
	}
	data, _ := os.ReadFile(f.Unfaithful)
	want := `{"t":"posts","k":"2","why":"differs"}` + "\n" +
		`{"t":"posts","k":"3","why":"unnormalizable"}` + "\n" +
		`{"t":"posts","k":"4","why":"unnormalizable_local"}` + "\n" +
		`{"t":"options","k":"only_local","why":"local_only"}` + "\n"
	if string(data) != want {
		t.Fatalf("unfaithful:\n%s", data)
	}
}

// A manifest row without a fingerprint names its reason; unfaithful.jsonl hands it on – but only a
// plain lower-case word, the manifest comes from the site.
func TestCompareHandsOnTheReasonOfTheManifest(t *testing.T) {
	dir := t.TempDir()
	f := Paths(dir)
	write(t, f.Manifest, `{"head":{"canon_version":1}}`+"\n"+
		`{"t":"options","k":"admin_email","h":null,"why":"pseudonymized"}`+"\n"+
		`{"t":"postmeta","k":"7\u0000_billing_email","h":null,"why":"pseudonymized"}`+"\n"+
		`{"t":"posts","k":"3","h":null,"why":"unnormalizable"}`+"\n"+
		`{"t":"posts","k":"4","h":null,"why":"key_encoding"}`+"\n"+
		`{"t":"posts","k":"5","h":null}`+"\n"+
		`{"t":"posts","k":"6","h":null,"why":"Not A Reason\u001b[31m"}`+"\n"+
		`{"t":"posts","k":"7","h":null,"why":"`+strings.Repeat("a", 33)+`"}`+"\n"+
		`{"t":"posts","k":"8","h":null,"why":""}`+"\n")
	var baseline strings.Builder
	for _, key := range []string{`"options","k":"admin_email"`, `"postmeta","k":"7\u0000_billing_email"`, `"posts","k":"3"`, `"posts","k":"4"`,
		`"posts","k":"5"`, `"posts","k":"6"`, `"posts","k":"7"`, `"posts","k":"8"`} {
		baseline.WriteString(`{"t":` + key + `,"h":"aa","row":{},"p":false,"why":"option"}` + "\n")
	}
	write(t, f.Baseline, baseline.String())
	rows, bad, err := Compare(dir)
	if err != nil {
		t.Fatal(err)
	}
	if rows != 8 || bad != 8 {
		t.Fatalf("rows=%d bad=%d", rows, bad)
	}
	data, _ := os.ReadFile(f.Unfaithful)
	want := `{"t":"options","k":"admin_email","why":"pseudonymized"}` + "\n" +
		`{"t":"postmeta","k":"7\u0000_billing_email","why":"pseudonymized"}` + "\n" +
		`{"t":"posts","k":"3","why":"unnormalizable"}` + "\n" +
		`{"t":"posts","k":"4","why":"key_encoding"}` + "\n" +
		`{"t":"posts","k":"5","why":"unnormalizable"}` + "\n" +
		`{"t":"posts","k":"6","why":"unnormalizable"}` + "\n" +
		`{"t":"posts","k":"7","why":"unnormalizable"}` + "\n" +
		`{"t":"posts","k":"8","why":"unnormalizable"}` + "\n"
	if string(data) != want {
		t.Fatalf("unfaithful:\n%s", data)
	}
}

func TestMapIsWrittenWithItsID(t *testing.T) {
	dir := t.TempDir()
	m := Map{CanonVersion: 1, Variants: []string{"plain", "esc1", "esc2"}, Live: agentapi.ContentOrigins{Home: "https://kunde.de", SiteURL: "https://kunde.de"},
		Local: "https://kunde.ddev.site", PulledAt: "2026-10-09T12:00:00Z"}
	id, err := WriteMap(dir, m)
	if err != nil {
		t.Fatal(err)
	}
	got, gotID, err := ReadMap(dir)
	if err != nil || gotID != id || len(id) != 64 || got.Local != m.Local || got.Live.Home != "https://kunde.de" {
		t.Fatalf("id=%s gotID=%s map=%+v err=%v", id, gotID, got, err)
	}
	var raw map[string]any
	data, _ := os.ReadFile(Paths(dir).Map)
	if json.Unmarshal(data, &raw) != nil || raw["canon_version"] != float64(1) || raw["live"].(map[string]any)["home"] != "https://kunde.de" {
		t.Fatalf("map.json: %s", data)
	}
}

func TestFreshNeedsEveryFileAndTheCanonVersion(t *testing.T) {
	dir := t.TempDir()
	if Fresh(dir) {
		t.Fatal("empty site folder is not fresh")
	}
	f := Paths(dir)
	for _, p := range []string{f.Manifest, f.Map, f.Baseline, f.Unfaithful} {
		write(t, p, "")
	}
	if Fresh(dir) {
		t.Fatal("without summary.json not fresh")
	}
	write(t, f.Summary, `{"rows":3,"unfaithful":0,"id_max":{"posts":9},"canon_version":99}`)
	if Fresh(dir) {
		t.Fatal("another canon version is not fresh")
	}
	write(t, f.Summary, `{"rows":3,"unfaithful":0,"id_max":{"posts":9},"canon_version":1}`)
	if Fresh(dir) {
		t.Fatal("without env.json not fresh – the export could not run offline")
	}
	write(t, f.Env, `{"php_version":"8.3.35","table_prefix":"wp_"}`)
	if !Fresh(dir) {
		t.Fatal("expected fresh")
	}
	s, err := LoadSummary(dir)
	if err != nil || s.Rows != 3 || s.IDMax["posts"] != 9 {
		t.Fatalf("summary=%+v err=%v", s, err)
	}
	os.Remove(f.Baseline)
	if Fresh(dir) {
		t.Fatal("a missing file is not fresh")
	}
}

type refreshSource struct {
	head *agentapi.ContentHead
	err  error
}

func (s refreshSource) ContentManifest(_ agentapi.Scope, w io.Writer) (*agentapi.ContentHead, int, error) {
	if s.err != nil {
		return nil, 0, s.err
	}
	io.WriteString(w, `{"head":{"canon_version":1}}`+"\n"+`{"t":"posts","k":"1","h":"aa"}`+"\n"+`{"t":"posts","k":"2","h":"bb"}`+"\n")
	return s.head, 2, nil
}

func TestRefreshWritesEverything(t *testing.T) {
	dir := t.TempDir()
	head := &agentapi.ContentHead{CanonVersion: 1, Variants: []string{"plain", "esc1", "esc2"}, IDMax: map[string]int64{"posts": 1204},
		Origins: agentapi.ContentOrigins{Home: "https://kunde.de", SiteURL: "https://kunde.de"}, Pushable: true}
	r := &fakeRunner{out: `{"t":"posts","k":"1","h":"aa","row":{}}` + "\n" + `{"t":"posts","k":"2","h":"zz","row":{}}` + "\n" + `{"end":true,"rows":2}` + "\n"}
	s, err := Refresh(dir, refreshSource{head: head}, r, agentapi.Scope{}, "https://kunde.ddev.site", Env{PHPVersion: "8.3.35", TablePrefix: "kd_"}, time.Date(2026, 10, 9, 12, 0, 0, 0, time.UTC))
	if err != nil {
		t.Fatal(err)
	}
	if s.Rows != 2 || s.Unfaithful != 1 || s.IDMax["posts"] != 1204 || s.CanonVersion != 1 || !Fresh(dir) {
		t.Fatalf("summary=%+v fresh=%v", s, Fresh(dir))
	}
	m, _, err := ReadMap(dir)
	if err != nil || m.Local != "https://kunde.ddev.site" || m.PulledAt != "2026-10-09T12:00:00Z" || m.Live.Home != "https://kunde.de" {
		t.Fatalf("map=%+v err=%v", m, err)
	}
	if r.args[3] != "https://kunde.ddev.site" {
		t.Fatalf("export args: %v", r.args)
	}
	if env, err := ReadEnv(dir); err != nil || env.PHPVersion != "8.3.35" || env.TablePrefix != "kd_" {
		t.Fatalf("env=%+v err=%v", env, err)
	}
	if data, _ := os.ReadFile(Paths(dir).Env); string(data) != "{\n  \"php_version\": \"8.3.35\",\n  \"table_prefix\": \"kd_\"\n}\n" {
		t.Fatalf("env.json = %q", data)
	}
}

// summary.json is the last file of a refresh: when the export fails after env.json was written,
// the state is not fresh.
func TestRefreshWritesEnvBeforeTheSummary(t *testing.T) {
	dir := t.TempDir()
	head := &agentapi.ContentHead{CanonVersion: CanonVersion}
	r := &fakeRunner{out: `{"t":"posts","k":"1","h":"aa"}` + "\n"} // no closing line: the export fails
	if _, err := Refresh(dir, refreshSource{head: head}, r, agentapi.Scope{}, "https://x.test", Env{PHPVersion: "8.3.35", TablePrefix: "wp_"}, time.Now()); !errors.Is(err, ErrExport) {
		t.Fatalf("err=%v", err)
	}
	if _, err := ReadEnv(dir); err != nil {
		t.Fatalf("env.json is written before the baseline: %v", err)
	}
	if _, err := os.Lstat(Paths(dir).Summary); !errors.Is(err, os.ErrNotExist) || Fresh(dir) {
		t.Fatalf("summary.json: %v, fresh=%v", err, Fresh(dir))
	}
}

// env.json lies in the site folder: its values are checked like those of /delta before they
// reach docker or WP-CLI.
func TestReadEnvChecksTheValues(t *testing.T) {
	dir := t.TempDir()
	f := Paths(dir)
	if _, err := ReadEnv(dir); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("missing: %v", err)
	}
	for _, bad := range []string{
		`{"php_version":"8.3.35","table_prefix":"--exec=x"}`,
		`{"php_version":"8.3.35","table_prefix":""}`,
		`{"php_version":"8.3.35"}`,
		`{"php_version":"8.3 --rm","table_prefix":"wp_"}`,
		`{"php_version":"latest","table_prefix":"wp_"}`,
		`{"php_version":"","table_prefix":"wp_"}`,
		`{"table_prefix":"wp_"}`,
	} {
		write(t, f.Env, bad)
		if _, err := ReadEnv(dir); !errors.Is(err, ErrEnv) || errors.Is(err, agentapi.ErrInvalidEnv) {
			t.Errorf("%s: err=%v", bad, err)
		}
	}
	for _, broken := range []string{``, `not json`, `[]`, `{"php_version":8}`} {
		write(t, f.Env, broken)
		if _, err := ReadEnv(dir); err == nil || errors.Is(err, os.ErrNotExist) {
			t.Errorf("%q: err=%v", broken, err)
		}
	}
	write(t, f.Env, `{"php_version":"8.3.35-1ubuntu","table_prefix":"djTui5D_"}`)
	env, err := ReadEnv(dir)
	if err != nil || env.PHPVersion != "8.3.35-1ubuntu" || env.TablePrefix != "djTui5D_" {
		t.Fatalf("env=%+v err=%v", env, err)
	}
	if a := env.Agent(); a.PHPVersion != env.PHPVersion || a.TablePrefix != env.TablePrefix || a.Home != "" {
		t.Fatalf("agent env = %+v", a)
	}

	outside := filepath.Join(t.TempDir(), "env.json")
	write(t, outside, `{"php_version":"8.3","table_prefix":"evil_"}`)
	os.Remove(f.Env)
	if err := os.Symlink(outside, f.Env); err != nil {
		t.Fatal(err)
	}
	if _, err := ReadEnv(dir); err == nil {
		t.Fatal("expected an error for a symlinked env.json")
	}
	if Fresh(dir) {
		t.Fatal("a symlinked env.json is no fresh state")
	}
}

func TestRefreshLeavesNothingFreshAfterAnError(t *testing.T) {
	dir := t.TempDir()
	f := Paths(dir)
	for _, p := range []string{f.Manifest, f.Map, f.Baseline, f.Unfaithful} {
		write(t, p, "")
	}
	write(t, f.Summary, `{"rows":3,"unfaithful":0,"id_max":{},"canon_version":1}`)
	boom := errors.New("boom")
	if _, err := Refresh(dir, refreshSource{err: boom}, &fakeRunner{}, agentapi.Scope{}, "https://x.test", Env{PHPVersion: "8.3.35", TablePrefix: "wp_"}, time.Now()); !errors.Is(err, boom) {
		t.Fatalf("err=%v", err)
	}
	if Fresh(dir) {
		t.Fatal("a failed refresh must not leave the old state fresh")
	}
}

func TestRefreshRefusesAnotherCanonVersion(t *testing.T) {
	head := &agentapi.ContentHead{CanonVersion: 2}
	_, err := Refresh(t.TempDir(), refreshSource{head: head}, &fakeRunner{}, agentapi.Scope{}, "https://x.test", Env{PHPVersion: "8.3.35", TablePrefix: "wp_"}, time.Now())
	if !errors.Is(err, ErrCanonVersion) {
		t.Fatalf("err=%v", err)
	}
}

func TestStoreNeverWritesThroughASymlink(t *testing.T) {
	dir := t.TempDir()
	outside := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, ".wpsync"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(dir, ".wpsync", "content")); err != nil {
		t.Fatal(err)
	}
	if _, err := WriteMap(dir, Map{CanonVersion: 1}); err == nil {
		t.Fatal("expected an error for a symlinked content folder")
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("wrote outside: %v", entries)
	}
	if !strings.Contains(Paths(dir).Dir, ".wpsync") {
		t.Fatal("paths")
	}
}

// content.reloaded belongs to the pull's output only (Plan B10): it is always written, and an
// older summary.json without the field reads as false.
func TestSummaryCarriesReloaded(t *testing.T) {
	data, err := json.Marshal(Summary{Rows: 1, Reloaded: true})
	if err != nil || !strings.Contains(string(data), `"reloaded":true`) {
		t.Fatalf("json=%s err=%v", data, err)
	}
	dir := t.TempDir()
	write(t, Paths(dir).Summary, `{"rows":3,"unfaithful":0,"id_max":{"posts":9},"canon_version":1}`)
	s, err := LoadSummary(dir)
	if err != nil || s.Reloaded || s.Rows != 3 {
		t.Fatalf("summary=%+v err=%v", s, err)
	}
}

func TestRefreshStoresReloadedFalse(t *testing.T) {
	dir := t.TempDir()
	head := &agentapi.ContentHead{CanonVersion: CanonVersion}
	r := &fakeRunner{out: `{"end":true,"rows":0}` + "\n"}
	if _, err := Refresh(dir, refreshSource{head: head}, r, agentapi.Scope{}, "https://x.test", Env{PHPVersion: "8.3.35", TablePrefix: "wp_"}, time.Now()); err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile(Paths(dir).Summary)
	if err != nil || !strings.Contains(string(data), `"reloaded": false`) {
		t.Fatalf("summary.json=%s err=%v", data, err)
	}
	if entries, _ := os.ReadDir(Paths(dir).Dir); len(entries) != 6 {
		t.Fatalf("expected the six files and no temp file, got %v", entries)
	}
}

func TestStoreNeverReadsThroughASymlink(t *testing.T) {
	dir := t.TempDir()
	outside := filepath.Join(t.TempDir(), "map.json")
	write(t, outside, `{"canon_version":1,"local":"https://evil.test"}`)
	f := Paths(dir)
	if err := os.MkdirAll(f.Dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, f.Map); err != nil {
		t.Fatal(err)
	}
	if _, _, err := ReadMap(dir); err == nil {
		t.Fatal("expected an error for a symlinked map.json")
	}
	// Writing replaces the link itself, never its target.
	if _, err := WriteMap(dir, Map{CanonVersion: 1, Local: "https://kunde.ddev.site"}); err != nil {
		t.Fatal(err)
	}
	if data, _ := os.ReadFile(outside); !strings.Contains(string(data), "evil.test") {
		t.Fatalf("wrote through the symlink: %s", data)
	}
	if m, _, err := ReadMap(dir); err != nil || m.Local != "https://kunde.ddev.site" {
		t.Fatalf("map=%+v err=%v", m, err)
	}
}

// freshState writes a complete content state of this CLI's canonical form.
func freshState(t *testing.T, dir string) Files {
	t.Helper()
	f := Paths(dir)
	for _, p := range []string{f.Manifest, f.Map, f.Baseline, f.Unfaithful, f.Env} {
		write(t, p, "x")
	}
	write(t, f.Summary, `{"rows":3,"unfaithful":0,"id_max":{"posts":9},"canon_version":1}`)
	if !Fresh(dir) {
		t.Fatal("expected a fresh state")
	}
	return f
}

// Plan B11: Invalidate drops the state by removing summary.json alone, can be repeated and needs
// neither the folder nor the site folder.
func TestInvalidateDropsTheState(t *testing.T) {
	dir := t.TempDir()
	f := freshState(t, dir)
	for i := 0; i < 2; i++ {
		if err := Invalidate(dir); err != nil {
			t.Fatalf("call %d: %v", i+1, err)
		}
		if Fresh(dir) {
			t.Fatalf("call %d: still fresh", i+1)
		}
	}
	if _, err := os.Lstat(f.Summary); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("summary.json: %v", err)
	}
	for _, p := range []string{f.Manifest, f.Map, f.Baseline, f.Unfaithful, f.Env} {
		if data, err := os.ReadFile(p); err != nil || string(data) != "x" {
			t.Errorf("%s: %q, %v", filepath.Base(p), data, err)
		}
	}

	empty := t.TempDir()
	if err := Invalidate(empty); err != nil {
		t.Fatalf("site folder without content state: %v", err)
	}
	if entries, _ := os.ReadDir(empty); len(entries) != 0 {
		t.Fatalf("Invalidate created %v", entries)
	}
	if err := Invalidate(filepath.Join(empty, "missing")); err != nil {
		t.Fatalf("missing site folder: %v", err)
	}
}

// A symlinked content folder is no state (Fresh says no) and nothing behind it is removed; a
// symlink in place of summary.json is removed itself.
func TestInvalidateNeverFollowsASymlink(t *testing.T) {
	outside := t.TempDir()
	kept := freshState(t, outside).Summary

	dir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, ".wpsync"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(Paths(outside).Dir, Paths(dir).Dir); err != nil {
		t.Fatal(err)
	}
	if err := Invalidate(dir); err != nil || Fresh(dir) {
		t.Fatalf("symlinked folder: err=%v fresh=%v", err, Fresh(dir))
	}
	if _, err := os.Stat(kept); err != nil {
		t.Fatalf("removed through the symlinked folder: %v", err)
	}

	dir = t.TempDir()
	f := freshState(t, dir)
	if err := os.Remove(f.Summary); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(kept, f.Summary); err != nil {
		t.Fatal(err)
	}
	if err := Invalidate(dir); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Lstat(f.Summary); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the symlink stays: %v", err)
	}
	if _, err := os.Stat(kept); err != nil {
		t.Fatalf("removed the target of the symlink: %v", err)
	}
}

// map.json lies in the site folder; its local URL becomes an argument of `wp eval-file`. Anything
// but a plain http(s) URL is refused before a runner sees it, and named without control characters.
func TestReadMapRefusesALocalURLThatIsNoURL(t *testing.T) {
	for _, local := range []string{"--exec=system('id');", "-https://kunde.ddev.site", "", "kunde.ddev.site", "ftp://kunde.ddev.site",
		"https://kunde.ddev.site --exec=x", " https://kunde.ddev.site", "https://kunde.ddev.site\n", "https://\x1b[31mkunde.ddev.site", "https://"} {
		dir := t.TempDir()
		data, _ := json.Marshal(Map{CanonVersion: 1, Local: local})
		write(t, Paths(dir).Map, string(data))
		_, _, err := ReadMap(dir)
		if !errors.Is(err, ErrMap) {
			t.Errorf("%q: err = %v", local, err)
			continue
		}
		if strings.ContainsAny(err.Error(), "\x1b\n") || !strings.Contains(err.Error(), "map.json") {
			t.Errorf("%q: message %q", local, err.Error())
		}
	}
}

func TestLinesRefusesAnOverlongLine(t *testing.T) {
	defer func(old int) { maxRecordLine = old }(maxRecordLine)
	maxRecordLine = 64
	dir := t.TempDir()
	f := Paths(dir)
	write(t, f.Manifest, `{"head":{}}`+"\n"+`{"t":"posts","k":"1","h":"aa"}`+"\n")
	write(t, f.Baseline, `{"t":"posts","k":"1","h":"aa","row":{}}`+"\n")
	if _, _, err := Compare(dir); err != nil {
		t.Fatalf("lines within the limit: %v", err)
	}
	long := `{"t":"posts","k":"1","h":"aa","row":{"post_content":"` + strings.Repeat("A", 64) + `"}}` + "\n"
	write(t, f.Baseline, long)
	if _, _, err := Compare(dir); !errors.Is(err, agentapi.ErrLineTooLong) || !strings.Contains(err.Error(), "baseline.jsonl") {
		t.Fatalf("overlong baseline line: %v", err)
	}
	write(t, f.Baseline, `{"t":"posts","k":"1","h":"aa","row":{}}`+"\n")
	write(t, f.Manifest, `{"head":{"x":"`+strings.Repeat("A", 64)+`"}}`+"\n")
	if _, _, err := Compare(dir); !errors.Is(err, agentapi.ErrLineTooLong) || !strings.Contains(err.Error(), "manifest.jsonl") {
		t.Fatalf("overlong manifest line: %v", err)
	}
}
