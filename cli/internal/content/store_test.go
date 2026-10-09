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
		f.Baseline != f.Dir+"/baseline.jsonl" || f.Unfaithful != f.Dir+"/unfaithful.jsonl" || f.Summary != f.Dir+"/summary.json" {
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
	s, err := Refresh(dir, refreshSource{head: head}, r, agentapi.Scope{}, "https://kunde.ddev.site", time.Date(2026, 10, 9, 12, 0, 0, 0, time.UTC))
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
}

func TestRefreshLeavesNothingFreshAfterAnError(t *testing.T) {
	dir := t.TempDir()
	f := Paths(dir)
	for _, p := range []string{f.Manifest, f.Map, f.Baseline, f.Unfaithful} {
		write(t, p, "")
	}
	write(t, f.Summary, `{"rows":3,"unfaithful":0,"id_max":{},"canon_version":1}`)
	boom := errors.New("boom")
	if _, err := Refresh(dir, refreshSource{err: boom}, &fakeRunner{}, agentapi.Scope{}, "https://x.test", time.Now()); !errors.Is(err, boom) {
		t.Fatalf("err=%v", err)
	}
	if Fresh(dir) {
		t.Fatal("a failed refresh must not leave the old state fresh")
	}
}

func TestRefreshRefusesAnotherCanonVersion(t *testing.T) {
	head := &agentapi.ContentHead{CanonVersion: 2}
	_, err := Refresh(t.TempDir(), refreshSource{head: head}, &fakeRunner{}, agentapi.Scope{}, "https://x.test", time.Now())
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
	if _, err := Refresh(dir, refreshSource{head: head}, r, agentapi.Scope{}, "https://x.test", time.Now()); err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile(Paths(dir).Summary)
	if err != nil || !strings.Contains(string(data), `"reloaded": false`) {
		t.Fatalf("summary.json=%s err=%v", data, err)
	}
	if entries, _ := os.ReadDir(Paths(dir).Dir); len(entries) != 5 {
		t.Fatalf("expected the five files and no temp file, got %v", entries)
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
	for _, p := range []string{f.Manifest, f.Map, f.Baseline, f.Unfaithful} {
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
	for _, p := range []string{f.Manifest, f.Map, f.Baseline, f.Unfaithful} {
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
