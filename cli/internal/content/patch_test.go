package content

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/safefs"
)

func b64(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }

// patchSite writes a manifest and a baseline with three rows and returns the site folder.
func patchSite(t *testing.T) string {
	t.Helper()
	siteDir := t.TempDir()
	dir := filepath.Join(siteDir, ".wpsync", "content")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	manifest := `{"head":{"canon_version":1}}
{"t":"posts","k":"219","h":"h219"}
{"t":"posts","k":"220","h":"h220"}
{"t":"postmeta","k":"219\u0000_thumbnail_id","h":"hthumb"}
{"t":"options","k":"blogname","h":"hblog"}
`
	baseline := `{"t":"posts","k":"219","h":"h219","row":{"post_title":"` + b64("Alt") + `","post_status":"` + b64("publish") + `"},"p":true}
{"t":"posts","k":"220","h":"h220","row":{"post_title":"` + b64("Entwurf") + `","post_status":"` + b64("draft") + `"},"p":true}
{"t":"postmeta","k":"219\u0000_thumbnail_id","h":"hthumb","row":{"values":["` + b64("300") + `"]},"p":true}
{"t":"options","k":"blogname","h":"hblog","row":{"option_value":"` + b64("Kunde") + `"},"p":true}
`
	for name, text := range map[string]string{manifestName: manifest, baselineName: baseline} {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(text), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return siteDir
}

func read(t *testing.T, siteDir, name string) string {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(siteDir, ".wpsync", "content", name))
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

// lineOf returns the decoded line of a key in a JSON-Lines text, nil without one.
func lineOf(t *testing.T, text, table, key string) map[string]any {
	t.Helper()
	for _, line := range strings.Split(strings.TrimSpace(text), "\n") {
		var m map[string]any
		if json.Unmarshal([]byte(line), &m) == nil && m["t"] == table && m["k"] == key {
			return m
		}
	}
	return nil
}

func str(s string) *string { return &s }

func TestPatchBringsManifestAndBaselineToThePushedState(t *testing.T) {
	siteDir := patchSite(t)
	manifestBefore, baselineBefore := read(t, siteDir, manifestName), read(t, siteDir, baselineName)
	newRow := json.RawMessage(`{"post_title":"` + b64("Neu") + `","post_status":"` + b64("publish") + `"}`)
	changes := map[Key]Change{
		{"posts", "219"}:                           {H: str("n219"), Row: newRow},                   // update
		{"posts", "220"}:                           {H: str("n220"), Trash: true},                   // trash
		{"postmeta", "219\x00_thumbnail_id"}:       {H: nil, Row: json.RawMessage(`{"values":[]}`)}, // pair deleted
		{"posts", "1000001"}:                       {H: str("n1000001"), Row: newRow},               // insert
		{"postmeta", "220\x00_wp_trash_meta_time"}: {H: str("ntrash")},                              // written by the agent
	}
	undo, err := Patch(siteDir, changes)
	if err != nil {
		t.Fatal(err)
	}
	manifest, baseline := read(t, siteDir, manifestName), read(t, siteDir, baselineName)
	if !strings.HasPrefix(manifest, `{"head":{"canon_version":1}}`+"\n") {
		t.Errorf("the head line must stay first:\n%s", manifest)
	}
	for key, want := range map[string]string{"219": "n219", "220": "n220", "1000001": "n1000001"} {
		if got := lineOf(t, manifest, "posts", key); got == nil || got["h"] != want {
			t.Errorf("manifest posts %s = %v", key, got)
		}
	}
	if lineOf(t, manifest, "postmeta", "219\x00_thumbnail_id") != nil || lineOf(t, baseline, "postmeta", "219\x00_thumbnail_id") != nil {
		t.Error("a deleted pair has no line any more")
	}
	if got := lineOf(t, manifest, "postmeta", "220\x00_wp_trash_meta_time"); got == nil || got["h"] != "ntrash" {
		t.Errorf("manifest trash meta = %v", got)
	}
	if lineOf(t, baseline, "postmeta", "220\x00_wp_trash_meta_time") != nil {
		t.Error("a key without a row never reaches the baseline")
	}
	if got := lineOf(t, manifest, "options", "blogname"); got["h"] != "hblog" {
		t.Errorf("an untouched key changed: %v", got)
	}
	up := lineOf(t, baseline, "posts", "219")
	if up["h"] != "n219" || up["row"].(map[string]any)["post_title"] != b64("Neu") || up["p"] != true {
		t.Errorf("baseline posts 219 = %v", up)
	}
	trashed := lineOf(t, baseline, "posts", "220")["row"].(map[string]any)
	if trashed["post_status"] != b64("trash") || trashed["post_title"] != b64("Entwurf") {
		t.Errorf("baseline posts 220 = %v", trashed)
	}
	if ins := lineOf(t, baseline, "posts", "1000001"); ins == nil || ins["h"] != "n1000001" || ins["p"] != true {
		t.Errorf("baseline posts 1000001 = %v", ins)
	}
	if strings.Count(manifest, "\n") != 6 || strings.Count(baseline, "\n") != 4 {
		t.Errorf("lines: manifest %d, baseline %d", strings.Count(manifest, "\n"), strings.Count(baseline, "\n"))
	}

	// The undo survives its way through the journal and puts every line back.
	packed, err := json.Marshal(undo)
	if err != nil {
		t.Fatal(err)
	}
	var back Undo
	if err := json.Unmarshal(packed, &back); err != nil {
		t.Fatal(err)
	}
	if err := Unpatch(siteDir, &back); err != nil {
		t.Fatal(err)
	}
	same := func(a, b string) bool {
		x, y := strings.Split(strings.TrimSpace(a), "\n"), strings.Split(strings.TrimSpace(b), "\n")
		seen := map[string]int{}
		for _, l := range x {
			seen[l]++
		}
		for _, l := range y {
			seen[l]--
		}
		for _, n := range seen {
			if n != 0 {
				return false
			}
		}
		return len(x) == len(y)
	}
	if !same(manifestBefore, read(t, siteDir, manifestName)) || !same(baselineBefore, read(t, siteDir, baselineName)) {
		t.Errorf("after the undo:\n%s\n%s", read(t, siteDir, manifestName), read(t, siteDir, baselineName))
	}
}

func TestPatchWithoutContentStateFails(t *testing.T) {
	if _, err := Patch(t.TempDir(), map[Key]Change{{"posts", "1"}: {H: str("x")}}); err == nil {
		t.Fatal("patched a site folder without a content state")
	}
}

// Like every file below .wpsync/content: a symlink in place of the file is replaced, never followed.
func TestPatchNeverWritesThroughASymlink(t *testing.T) {
	siteDir := patchSite(t)
	outside := filepath.Join(t.TempDir(), "outside.txt")
	if err := os.WriteFile(outside, []byte("bleibt"), 0o644); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(siteDir, ".wpsync", "content", manifestName+safefs.TmpSuffix)
	os.Remove(link)
	if err := os.Symlink(outside, link); err != nil {
		t.Skip(err)
	}
	if _, err := Patch(siteDir, map[Key]Change{{"posts", "219"}: {H: str("n219")}}); err != nil {
		t.Fatal(err)
	}
	if data, _ := os.ReadFile(outside); string(data) != "bleibt" {
		t.Errorf("wrote through the symlink: %q", data)
	}
}

// Patch and Unpatch read manifest and baseline under the same bound per line as every other reader
// of these files; a file with a longer line stays as it is.
func TestPatchRefusesAnOverlongLine(t *testing.T) {
	siteDir := patchSite(t)
	h := "hneu"
	changes := map[Key]Change{{T: "options", K: "blogname"}: {H: &h, Row: json.RawMessage(`{"option_value":"` + b64("Neu") + `"}`)}}
	undo, err := Patch(siteDir, changes)
	if err != nil {
		t.Fatal(err)
	}
	if err := Unpatch(siteDir, undo); err != nil {
		t.Fatal(err)
	}
	defer func(old int) { maxRecordLine = old }(maxRecordLine)
	maxRecordLine = 64
	manifest, baseline := read(t, siteDir, manifestName), read(t, siteDir, baselineName)
	if _, err := Patch(siteDir, changes); !errors.Is(err, agentapi.ErrLineTooLong) || !strings.Contains(err.Error(), baselineName) {
		t.Fatalf("Patch with an overlong baseline line: %v", err)
	}
	// Both files or neither: the manifest was patched first and is back as it was.
	if got := read(t, siteDir, manifestName); got != manifest {
		t.Errorf("manifest changed although the baseline could not be patched:\n%s", got)
	}
	if err := Unpatch(siteDir, undo); !errors.Is(err, agentapi.ErrLineTooLong) {
		t.Fatalf("Unpatch with an overlong baseline line: %v", err)
	}
	if got := read(t, siteDir, baselineName); got != baseline {
		t.Errorf("baseline changed:\n%s", got)
	}
	if _, err := os.Stat(filepath.Join(siteDir, ".wpsync", "content", baselineName+safefs.TmpSuffix)); !os.IsNotExist(err) {
		t.Errorf("temp file left behind: %v", err)
	}
}

// op trash: the agent does what WordPress does – __trashed at the name, the old name in
// _wp_desired_post_slug, status and time of the trash. The baseline follows with what it can
// know (name, status, the two metas it has the values for); h is always the agent's.
func TestPatchFollowsATrashedPost(t *testing.T) {
	siteDir := patchSite(t)
	dir := filepath.Join(siteDir, ".wpsync", "content")
	baseline := `{"t":"posts","k":"220","h":"h220","row":{"post_name":"` + b64("entwurf") + `","post_status":"` + b64("draft") + `"},"p":true}
{"t":"posts","k":"221","h":"h221","row":{"post_name":"` + b64("alt__trashed") + `","post_status":"` + b64("draft") + `"},"p":true}
{"t":"postmeta","k":"220\u0000_wp_desired_post_slug","h":"hold","row":{"values":["` + b64("uralt") + `"]},"p":true}
`
	if err := os.WriteFile(filepath.Join(dir, baselineName), []byte(baseline), 0o644); err != nil {
		t.Fatal(err)
	}
	before := read(t, siteDir, baselineName)
	changes := map[Key]Change{
		{"posts", "220"}: {H: str("n220"), Trash: true},
		{"postmeta", "220\x00_wp_desired_post_slug"}: {H: str("nslug")},
		{"postmeta", "220\x00_wp_trash_meta_status"}: {H: str("nstatus")},
		{"postmeta", "220\x00_wp_trash_meta_time"}:   {H: str("ntime")},
		{"posts", "221"}: {H: str("n221"), Trash: true},
		{"postmeta", "221\x00_wp_trash_meta_status"}: {H: str("nstatus221")},
	}
	undo, err := Patch(siteDir, changes)
	if err != nil {
		t.Fatal(err)
	}
	got := read(t, siteDir, baselineName)
	post := lineOf(t, got, "posts", "220")
	if row := post["row"].(map[string]any); post["h"] != "n220" || row["post_status"] != b64("trash") || row["post_name"] != b64("entwurf__trashed") {
		t.Errorf("posts 220 = %v", post)
	}
	if row := lineOf(t, got, "posts", "221")["row"].(map[string]any); row["post_name"] != b64("alt__trashed") {
		t.Errorf("a name that carries the suffix stays: %v", row)
	}
	slug := lineOf(t, got, "postmeta", "220\x00_wp_desired_post_slug")
	if slug == nil || slug["h"] != "nslug" || slug["p"] != true || !reflect.DeepEqual(slug["row"].(map[string]any)["values"], []any{b64("uralt"), b64("entwurf")}) {
		t.Errorf("desired slug = %v", slug)
	}
	status := lineOf(t, got, "postmeta", "220\x00_wp_trash_meta_status")
	if status == nil || status["h"] != "nstatus" || status["p"] != false || status["why"] != "meta_key" || !reflect.DeepEqual(status["row"].(map[string]any)["values"], []any{b64("draft")}) {
		t.Errorf("trash status = %v", status)
	}
	if lineOf(t, got, "postmeta", "220\x00_wp_trash_meta_time") != nil {
		t.Error("the time of the trash is the agent's alone: manifest only")
	}
	if lineOf(t, read(t, siteDir, manifestName), "postmeta", "220\x00_wp_trash_meta_time")["h"] != "ntime" {
		t.Error("manifest misses the time of the trash")
	}
	if err := Unpatch(siteDir, undo); err != nil {
		t.Fatal(err)
	}
	a, b := strings.Split(strings.TrimSpace(read(t, siteDir, baselineName)), "\n"), strings.Split(strings.TrimSpace(before), "\n")
	sort.Strings(a)
	sort.Strings(b)
	if !reflect.DeepEqual(a, b) {
		t.Errorf("after Unpatch:\n%s", strings.Join(a, "\n"))
	}
}
