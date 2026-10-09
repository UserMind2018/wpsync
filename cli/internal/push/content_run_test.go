package push

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

// stage plays /content/stage: pieces are appended per sha256; a request with data counts as route "stage".
func (f *fakeSite) stage(w http.ResponseWriter, r *http.Request) {
	if f.noContent {
		w.WriteHeader(http.StatusNotFound)
		w.Write([]byte(`{"code":"rest_no_route","message":"no route"}`))
		return
	}
	var req struct {
		SHA256 string `json:"sha256"`
		Size   int64  `json:"size"`
		Offset *int64 `json:"offset"`
		Data   []byte `json:"data"`
	}
	json.NewDecoder(r.Body).Decode(&req)
	if f.staged == nil {
		f.staged = map[string][]byte{}
	}
	if req.Offset != nil {
		f.routes = append(f.routes, "stage")
		if *req.Offset != int64(len(f.staged[req.SHA256])) {
			f.t.Errorf("stage at offset %d, have %d bytes", *req.Offset, len(f.staged[req.SHA256]))
		}
		f.staged[req.SHA256] = append(f.staged[req.SHA256], req.Data...)
	}
	n := int64(len(f.staged[req.SHA256]))
	json.NewEncoder(w).Encode(map[string]any{"sha256": req.SHA256, "received": n, "complete": n == req.Size})
}

// stagedRows parses the rows of a staged package.
func (f *fakeSite) stagedRows(sha string) []PackageRow {
	var rows []PackageRow
	for i, line := range bytes.Split(bytes.TrimSpace(f.staged[sha]), []byte("\n")) {
		var row PackageRow
		if i > 0 && json.Unmarshal(line, &row) == nil {
			rows = append(rows, row)
		}
	}
	return rows
}

// contentPlan answers for the package like PushContent::plan.
func (f *fakeSite) contentPlan(req agentapi.PushBeginRequest) *agentapi.ContentPlan {
	plan := &agentapi.ContentPlan{Rows: map[string]int{}, Limits: agentapi.ContentLimits{MaxRows: 5000, MaxBytes: 8 << 20, BudgetSeconds: 12}, Conflicts: []agentapi.ContentKey{}}
	rows := f.stagedRows(req.Content.SHA256)
	if rows == nil {
		plan.Error = &agentapi.ContentFailure{Code: "package_missing", Message: "nicht abgelegt"}
		return plan
	}
	for _, row := range rows {
		plan.Rows[row.Table]++
	}
	if f.contentFail != nil {
		plan.Error = f.contentFail
		if f.contentFail.Code == "conflict" {
			plan.Conflicts = f.contentFail.Keys
		}
		return plan
	}
	plan.OK, plan.HealthURLs = true, f.contentHealth
	return plan
}

// fakeHash is the fingerprint the fake site reports for a key after the push.
func fakeHash(table, key string) string {
	sum := sha256.Sum256([]byte("after:" + table + ":" + key))
	return hex.EncodeToString(sum[:])
}

// applied answers the content part of the commit: one fingerprint per row, none for a deleted pair.
func (f *fakeSite) applied(sha string) *agentapi.ContentApplied {
	out := &agentapi.ContentApplied{PostActions: f.actions, Seconds: 0.1}
	if out.PostActions == nil {
		out.PostActions = []agentapi.PostAction{{Step: "object_cache", OK: true}}
	}
	for _, row := range f.stagedRows(sha) {
		out.Rows++
		entry := agentapi.ContentAfter{T: row.Table, K: row.Key}
		if !bytes.Contains(row.Row, []byte(`"values":[]`)) {
			h := fakeHash(row.Table, row.Key)
			entry.H = &h
		}
		out.After = append(out.After, entry)
	}
	return out
}

const (
	manifestBefore = `{"head":{"canon_version":1}}
{"t":"posts","k":"219","h":"h219"}
{"t":"posts","k":"220","h":"h220"}
{"t":"postmeta","k":"219\u0000_alt","h":"halt"}
`
	baselineBefore = `{"t":"posts","k":"219","h":"h219","row":{"post_title":"QWx0","post_status":"cHVibGlzaA=="},"p":true}
{"t":"posts","k":"220","h":"h220","row":{"post_title":"RW50d3VyZg==","post_status":"ZHJhZnQ="},"p":true}
{"t":"postmeta","k":"219\u0000_alt","h":"halt","row":{"values":["eA=="]},"p":true}
`
	rowDrop = `{"op":"update","table":"postmeta","key":"219\u0000_alt","expected":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","row":{"values":[]}}`
)

// contentSite is localSite with an agent 0.7.0, the content state of a pull with --content in the
// site folder and a package of four rows built against it: update, trash, a deleted pair, a new pair.
func contentSite(t *testing.T, f *fakeSite) (Options, string, *bytes.Buffer) {
	t.Helper()
	f.version = "0.7.0"
	o, siteDir, out := localSite(t, f)
	dir := filepath.Join(siteDir, ".wpsync", "content")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	mapJSON := `{"canon_version":1,"variants":["plain","esc1","esc2"],"live":{"home":"` + f.srv.URL + `","siteurl":"` + f.srv.URL + `"},"local":"https://kunde.ddev.site","pulled_at":"2026-10-09T10:00:00Z"}` + "\n"
	files := map[string]string{
		"manifest.jsonl": manifestBefore, "baseline.jsonl": baselineBefore, "unfaithful.jsonl": "", "map.json": mapJSON,
		"env.json": `{"php_version":"8.2.0","table_prefix":"wp_"}`, "summary.json": `{"rows":3,"unfaithful":0,"id_max":{},"canon_version":1,"reloaded":false}`,
	}
	for name, text := range files {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(text), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	id := sha256.Sum256([]byte(mapJSON))
	text := packageText(t, []string{rowUpdate, rowTrash, rowDrop, rowMeta}, map[string]any{"home": f.srv.URL, "map_id": hex.EncodeToString(id[:])})
	o.Content = writePackage(t, text)
	return o, siteDir, out
}

func contentFile(t *testing.T, siteDir, name string) string {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(siteDir, ".wpsync", "content", name))
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

// Ein Satz nur aus Inhalten (--no-code): abgelegt, geprüft, angewandt, bestätigt – Manifest und Baseline ziehen nach.
func TestRunPushesContentAlone(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := contentSite(t, f)
	o.NoCode = true
	o.ChunkBytes = 300 // das Paket geht in mehreren Stücken hoch
	var report Result
	o.Report = &report

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	routes := strings.Join(f.routes, " ")
	if !strings.HasPrefix(routes, "stage stage") || !strings.HasSuffix(routes, "stage begin begin commit confirm") {
		t.Errorf("routes = %s", routes)
	}
	pkg, _ := os.ReadFile(o.Content)
	sum := sha256.Sum256(pkg)
	sha := hex.EncodeToString(sum[:])
	if !bytes.Equal(f.staged[sha], pkg) {
		t.Error("the staged package is not the file")
	}
	for i, b := range f.begins {
		if b.Content == nil || b.Content.SHA256 != sha || len(b.Units) != 0 {
			t.Errorf("begin %d = %+v", i, b)
		}
	}
	if !reflect.DeepEqual(report.Units, []string{ContentUnit}) || report.Status != "confirmed" ||
		!reflect.DeepEqual(report.PostActions, []agentapi.PostAction{{Step: "object_cache", OK: true}}) {
		t.Errorf("report = %+v", report)
	}
	manifest, baseline := contentFile(t, siteDir, "manifest.jsonl"), contentFile(t, siteDir, "baseline.jsonl")
	for _, want := range []string{
		`{"t":"posts","k":"219","h":"` + fakeHash("posts", "219") + `"}`,
		`{"t":"posts","k":"220","h":"` + fakeHash("posts", "220") + `"}`,
		`{"t":"postmeta","k":"219\u0000_x","h":"` + fakeHash("postmeta", "219\x00_x") + `"}`,
	} {
		if !strings.Contains(manifest, want) {
			t.Errorf("manifest lacks %s:\n%s", want, manifest)
		}
	}
	if strings.Contains(manifest, "_alt") || strings.Contains(baseline, "_alt") {
		t.Error("the deleted pair must be gone from manifest and baseline")
	}
	if !strings.Contains(baseline, `"row":{"post_title":"TmV1"}`) || !strings.Contains(baseline, `"post_status":"dHJhc2g="`) || !strings.Contains(baseline, `"row":{"values":["eA=="]},"p":true`) {
		t.Errorf("baseline:\n%s", baseline)
	}
	j, err := LoadJournal(siteDir, testID)
	if err != nil || j.Content == nil || j.Content.SHA256 != sha || j.Content.Rows != 4 || !j.Content.Applied || !j.Applied {
		t.Errorf("journal = %+v, %v", j, err)
	}
	if _, err := os.Stat(filepath.Join(siteDir, ".wpsync", "pushes", testID+".content.json")); err != nil {
		t.Errorf("the undo of manifest and baseline is missing: %v", err)
	}
	if !strings.Contains(out.String(), "content – 4 Zeilen, posts 2, postmeta 2") {
		t.Errorf("output:\n%s", out)
	}
}

// Der Probelauf prüft das Paket ohne Push-Fenster und nennt Zeilen, Konflikte und Grenzen im plan.
func TestRunDryRunWithContentNeedsNoWindow(t *testing.T) {
	f := newFakeSite(t)
	f.window = false
	o, siteDir, _ := contentSite(t, f)
	o.NoCode, o.DryRun = true, true
	var plan map[string]any
	o.Event = func(name string, data any) { plan, _ = data.(map[string]any) }
	var report Result
	o.Report = &report

	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "stage begin" {
		t.Errorf("routes = %s", got)
	}
	want := map[string]any{"rows": map[string]int{"posts": 2, "postmeta": 2}, "conflicts": []agentapi.ContentKey{},
		"limits": agentapi.ContentLimits{MaxRows: 5000, MaxBytes: 8 << 20, BudgetSeconds: 12}}
	if !reflect.DeepEqual(plan["content"], want) {
		t.Errorf("plan.content = %#v", plan["content"])
	}
	if report.Status != "dry_run" || contentFile(t, siteDir, "manifest.jsonl") != manifestBefore {
		t.Errorf("report = %+v", report)
	}

	// Ein zweiter Lauf überträgt das Paket nicht noch einmal.
	f.routes = nil
	if err := Run(o); err != nil || strings.Join(f.routes, " ") != "begin" {
		t.Errorf("second run: %v, routes = %v", err, f.routes)
	}
}

// AC-151: lehnt der Agent das Paket ab, endet der Push nach dem Probelauf – mit Grund und allen Schlüsseln.
func TestRunStopsOnARefusalOfTheContent(t *testing.T) {
	f := newFakeSite(t)
	keys := []agentapi.ContentKey{{Table: "posts", Key: "219"}, {Table: "postmeta", Key: "219\x00_alt"}}
	f.contentFail = &agentapi.ContentFailure{Code: "conflict", Message: "Auf dem Ziel seit dem Pull geändert", Keys: keys, Total: 2}
	o, siteDir, out := contentSite(t, f)
	var plan map[string]any
	o.Event = func(name string, data any) { plan, _ = data.(map[string]any) }

	err := Run(o) // mit Code im Satz: auch der geht nicht raus
	var refused *ContentError
	if !errors.As(err, &refused) || refused.Reason != "conflict" || !reflect.DeepEqual(refused.Keys, keys) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "stage begin" {
		t.Errorf("routes = %s", got)
	}
	if ct, _ := plan["content"].(map[string]any); !reflect.DeepEqual(ct["conflicts"], keys) {
		t.Errorf("plan.content = %v", plan["content"])
	}
	if !strings.Contains(out.String(), "(conflict)") || contentFile(t, siteDir, "manifest.jsonl") != manifestBefore {
		t.Errorf("output:\n%s", out)
	}
	o.Force = true
	if err := Run(o); !errors.As(err, &refused) {
		t.Errorf("--force must not push refused content: %v", err)
	}
}

// Das Paket muss zum Inhaltsstand dieses Site-Ordners gehören – geprüft vor jedem Request.
func TestRunChecksThePackageAgainstTheSiteFolder(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := contentSite(t, f)
	good := o.Content
	reason := func() string {
		var refused *ContentError
		if err := Run(o); !errors.As(err, &refused) {
			t.Fatalf("err = %v", err)
		} else {
			return refused.Reason
		}
		return ""
	}
	o.Content = writePackage(t, packageText(t, []string{rowUpdate}, map[string]any{"home": f.srv.URL}))
	if got := reason(); got != "baseline_outdated" {
		t.Errorf("package of another pull: %s", got)
	}
	o.Content = writePackage(t, "kein paket\n")
	if got := reason(); got != "package_invalid" {
		t.Errorf("no package: %s", got)
	}
	o.Content = good
	if err := os.Remove(filepath.Join(siteDir, ".wpsync", "content", "summary.json")); err != nil {
		t.Fatal(err)
	}
	if got := reason(); got != "baseline_outdated" {
		t.Errorf("no content state: %s", got)
	}
	if len(f.routes) != 0 {
		t.Errorf("requests before the local check: %v", f.routes)
	}
}

func TestRunRefusesAnAgentWithoutTheContentChannel(t *testing.T) {
	f := newFakeSite(t)
	f.noContent = true
	o, _, _ := contentSite(t, f)
	if err := Run(o); !errors.Is(err, ErrAgentNoContent) {
		t.Fatalf("err = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("routes = %v", f.routes)
	}
}

// Ein Satz aus allen drei Kanälen: Code, Uploads, Inhalte – Einheiten in dieser Reihenfolge, Inhalte kein Eintrag in units.
func TestRunPushesCodeUploadsAndContentAsOneSet(t *testing.T) {
	f := newFakeSite(t)
	f.contentHealth = []string{"PLACEHOLDER"}
	o, siteDir, out := contentSite(t, f)
	f.contentHealth = []string{f.srv.URL + "/seite-219/", "https://fremd.example/x"}
	write(t, filepath.Join(siteDir, "public"), "uploads/2026/10/neu.png", "neu", 1800000000)
	o.Uploads = []string{"2026/10/neu.png"}
	var report Result
	o.Report = &report

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	units := f.begins[1].Units
	if len(units) != 2 || units[0].Path != "plugins/x" || units[1].Path != UploadsUnit || f.begins[1].Content == nil {
		t.Fatalf("begin = %+v", f.begins[1])
	}
	if !reflect.DeepEqual(report.Units, []string{"plugins/x", UploadsUnit, ContentUnit}) {
		t.Errorf("units = %v", report.Units)
	}
	var paths []string
	for _, c := range f.cookies {
		paths = append(paths, strings.Fields(c + " x")[0])
	}
	if !strings.Contains(strings.Join(paths, " "), "/seite-219/") {
		t.Errorf("the page the package changes was not checked: %v", paths)
	}
	if !strings.Contains(out.String(), "fremd.example") {
		t.Errorf("a page outside the site must be dropped with a note:\n%s", out)
	}
}

// §7.6: wird die Site nach dem Tausch schlechter, geht die Rücknahme zuerst über den Agent – nur er nimmt Inhalte zurück.
func TestRunTakesContentBackThroughTheAgent(t *testing.T) {
	f := newFakeSite(t)
	f.broken = true
	f.rbBody = `{"ok":true,"status":"rolled_back","post_actions":[{"step":"object_cache","ok":true},{"step":"elementor_css","ok":false}]}`
	o, siteDir, out := contentSite(t, f)
	var report Result
	o.Report = &report

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback") || strings.Contains(got, "rescue") {
		t.Errorf("routes = %s", got)
	}
	if report.Status != "rolled_back" || len(report.Warnings) != 0 || len(report.PostActions) != 2 || report.PostActions[1].OK {
		t.Errorf("report = %+v", report)
	}
	if contentFile(t, siteDir, "manifest.jsonl") != manifestBefore || contentFile(t, siteDir, "baseline.jsonl") != baselineBefore {
		t.Error("a rolled back push must leave manifest and baseline alone")
	}
	if !strings.Contains(out.String(), "Nacharbeit elementor_css ist nicht gelungen") {
		t.Errorf("output:\n%s", out)
	}
}

// AC-157: antwortet der Agent nicht, nimmt rescue.php Code und Uploads zurück – das Ergebnis nennt die Inhalte.
func TestRunFallsBackToRescueAndNamesTheContent(t *testing.T) {
	f := newFakeSite(t)
	f.broken, f.rollback = true, 500
	o, _, out := contentSite(t, f)
	var report Result
	o.Report = &report

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || !reflect.DeepEqual(rolled.Warnings, []string{WarningContentNotRolledBack}) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback rescue") {
		t.Errorf("routes = %s", got)
	}
	if report.Status != "rolled_back" || !reflect.DeepEqual(report.Warnings, []string{WarningContentNotRolledBack}) {
		t.Errorf("report = %+v", report)
	}
}

// §7.6: haben sich Zeilen seit dem Push geändert, bleibt der Satz ganz – rescue.php wird nicht gerufen.
func TestRunKeepsTheSetWhenContentChangedSinceThePush(t *testing.T) {
	f := newFakeSite(t)
	f.broken, f.rollback, f.rbCode = true, 409, "wpsync_content_changed_since_push"
	o, _, out := contentSite(t, f)
	var report Result
	o.Report = &report

	err := Run(o)
	var refused *ContentError
	if !errors.As(err, &refused) || refused.Reason != "changed_since_push" || errors.As(err, new(*RolledBackError)) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if strings.Contains(strings.Join(f.routes, " "), "rescue") || f.rolledBack {
		t.Errorf("routes = %v", f.routes)
	}
	if report.Status != "committed" {
		t.Errorf("status = %q – the push is swapped and neither confirmed nor rolled back", report.Status)
	}
}

// Scheitern die Inhalte im Commit, hat der Agent alles zurückgetauscht: kein Health-Check, kein Rollback.
func TestRunReportsAContentRefusalOfTheCommit(t *testing.T) {
	f := newFakeSite(t)
	f.commitCode = "wpsync_content_conflict"
	o, siteDir, _ := contentSite(t, f)
	var report Result
	o.Report = &report

	err := Run(o)
	var refused *ContentError
	if !errors.As(err, &refused) || refused.Reason != "conflict" || len(refused.Keys) != 1 {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "upload commit") {
		t.Errorf("routes = %s", got)
	}
	if report.Status != "" || contentFile(t, siteDir, "manifest.jsonl") != manifestBefore {
		t.Errorf("report = %+v", report)
	}
}

// Ein Agent, der tauscht, die Inhalte aber nicht anwendet, wird nie bestätigt.
func TestRunNeverConfirmsHalfASet(t *testing.T) {
	f := newFakeSite(t)
	f.noApply = true
	o, _, _ := contentSite(t, f)
	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || strings.Contains(strings.Join(f.routes, " "), "confirm") {
		t.Fatalf("err = %v, routes = %v", err, f.routes)
	}
}

// §10: nach Staging bleiben Manifest und Baseline, wie sie sind – sie beschreiben Live.
func TestRunToStagingLeavesManifestAndBaseline(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := contentSite(t, f)
	o.Target, o.NoCode = TargetStaging, true

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if contentFile(t, siteDir, "manifest.jsonl") != manifestBefore || contentFile(t, siteDir, "baseline.jsonl") != baselineBefore {
		t.Error("a push to staging changed manifest or baseline")
	}
	j, _ := LoadJournal(siteDir, testID)
	if j == nil || j.Content == nil || j.Content.Applied {
		t.Errorf("journal = %+v", j)
	}
	if !strings.Contains(out.String(), "--content <paket> --no-code") {
		t.Errorf("the hint for the push to live must name the content:\n%s", out)
	}
}

// contentPushed runs a confirmed push of content to live and returns the site for a rollback.
func contentPushed(t *testing.T) (*fakeSite, Options, string) {
	t.Helper()
	f := newFakeSite(t)
	o, siteDir, out := contentSite(t, f)
	o.NoCode = true
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	f.routes = nil
	return f, o, siteDir
}

// AC-153: die Rücknahme über den Agent setzt auch Manifest und Baseline dieses Rechners zurück.
func TestRollbackRevertsManifestAndBaseline(t *testing.T) {
	f, o, siteDir := contentPushed(t)
	f.rbBody = `{"ok":true,"status":"rolled_back","post_actions":[{"step":"object_cache","ok":true}]}`
	var report Result
	o.Report = &report

	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	sameLines := func(a, b string) bool {
		x, y := strings.Split(strings.TrimSpace(a), "\n"), strings.Split(strings.TrimSpace(b), "\n")
		if len(x) != len(y) {
			return false
		}
		for _, l := range x {
			if !strings.Contains(b, l+"\n") {
				return false
			}
		}
		return true
	}
	if !sameLines(manifestBefore, contentFile(t, siteDir, "manifest.jsonl")) || !sameLines(baselineBefore, contentFile(t, siteDir, "baseline.jsonl")) {
		t.Errorf("manifest:\n%s\nbaseline:\n%s", contentFile(t, siteDir, "manifest.jsonl"), contentFile(t, siteDir, "baseline.jsonl"))
	}
	if !reflect.DeepEqual(report.Units, []string{ContentUnit}) || report.Status != "rolled_back" || len(report.PostActions) != 1 {
		t.Errorf("report = %+v", report)
	}
	j, _ := LoadJournal(siteDir, testID)
	if j.Applied || j.Content.Applied {
		t.Errorf("journal = %+v", j)
	}
}

// §7.6: changed_since_push – nichts wird zurückgenommen, auch nicht über rescue.php, lokal bleibt alles.
func TestRollbackOfChangedContentTakesNothingBack(t *testing.T) {
	f, o, siteDir := contentPushed(t)
	f.rollback, f.rbCode = 409, "wpsync_content_changed_since_push"
	patched := contentFile(t, siteDir, "manifest.jsonl")

	err := Rollback(o, testID)
	var refused *ContentError
	if !errors.As(err, &refused) || refused.Reason != "changed_since_push" {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback" {
		t.Errorf("routes = %s", got)
	}
	if contentFile(t, siteDir, "manifest.jsonl") != patched {
		t.Error("the manifest must keep the pushed state")
	}
}

// AC-157: der spätere Weg über rescue.php nennt die Inhalte – und lässt Manifest und Baseline beim gepushten Stand.
func TestRollbackThroughRescueNamesTheContentLeftBehind(t *testing.T) {
	f, o, siteDir := contentPushed(t)
	f.rollback = 500
	patched := contentFile(t, siteDir, "manifest.jsonl")
	var out bytes.Buffer
	o.Out = &out
	var report Result
	o.Report = &report

	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback rescue" {
		t.Errorf("routes = %s", got)
	}
	if !reflect.DeepEqual(report.Warnings, []string{WarningContentNotRolledBack}) || !strings.Contains(out.String(), "die Inhalte des Pushs stehen noch") {
		t.Errorf("report = %+v\n%s", report, &out)
	}
	if contentFile(t, siteDir, "manifest.jsonl") != patched {
		t.Error("while the content stands, the manifest keeps the pushed state")
	}
	if j, _ := LoadJournal(siteDir, testID); !j.Content.Applied {
		t.Errorf("journal = %+v", j)
	}
}

// AC-157: holt ein späterer rollback die Inhalte über den Agent nach, gehen auch Manifest und
// Baseline zurück – der erste, über rescue.php, hat die Baseline der Dateien schon zurückgesetzt.
func TestRollbackCatchingUpRevertsManifestAndBaseline(t *testing.T) {
	f, o, siteDir := contentPushed(t)
	f.rollback = 500
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if contentFile(t, siteDir, "manifest.jsonl") == manifestBefore {
		t.Fatal("while the content stands, the manifest keeps the pushed state")
	}

	f.rollback, f.routes = 200, nil
	var out bytes.Buffer
	o.Out = &out
	var report Result
	o.Report = &report
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback" {
		t.Errorf("routes = %s", got)
	}
	for name, before := range map[string]string{"manifest.jsonl": manifestBefore, "baseline.jsonl": baselineBefore} {
		got := strings.Split(strings.TrimSpace(contentFile(t, siteDir, name)), "\n")
		want := strings.Split(strings.TrimSpace(before), "\n")
		sort.Strings(got)
		sort.Strings(want)
		if !reflect.DeepEqual(got, want) {
			t.Errorf("%s:\n%s", name, strings.Join(got, "\n"))
		}
	}
	if len(report.Warnings) != 0 {
		t.Errorf("report = %+v\n%s", report, &out)
	}
	if j, _ := LoadJournal(siteDir, testID); j.Content.Applied {
		t.Errorf("journal = %+v", j)
	}
}
