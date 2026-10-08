package push

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"maps"
	"net/http"
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// uploadsPlan answers for the unit uploads like PushUploads::plan, or refuses it like an agent
// before 0.6.0 (400 wpsync_push_unit) or a real begin with a conflict (409 wpsync_upload_exists).
func (f *fakeSite) uploadsPlan(w http.ResponseWriter, req agentapi.PushBeginRequest, u agentapi.PushUnit) (agentapi.PushUnitPlan, bool) {
	if !AtLeast(f.version, MinAgentUploads) && !f.acceptOld {
		w.WriteHeader(http.StatusBadRequest)
		w.Write([]byte(`{"code":"wpsync_push_unit","message":"Einheit nicht erlaubt: uploads"}`))
		return agentapi.PushUnitPlan{}, true
	}
	plan := agentapi.PushUnitPlan{Path: u.Path, Exists: true, Conflicts: []string{}, Same: []string{}, Writable: !f.readonly}
	for _, rel := range slices.Sorted(maps.Keys(u.Files)) {
		have, ok := f.uploadsHave[rel]
		sum := sha256.Sum256([]byte(have))
		switch {
		case !ok:
			plan.Need = append(plan.Need, rel)
		case hex.EncodeToString(sum[:]) == u.Files[rel].SHA256:
			plan.Same = append(plan.Same, rel)
		default:
			plan.Conflicts = append(plan.Conflicts, rel)
		}
	}
	if !req.Dry && len(plan.Conflicts) > 0 {
		w.WriteHeader(http.StatusConflict)
		w.Write([]byte(`{"code":"wpsync_upload_exists","message":"Auf dem Ziel liegt am selben Pfad eine andere Datei"}`))
		return agentapi.PushUnitPlan{}, true
	}
	f.upNeed = plan.Need
	return plan, false
}

// uploadsSite is localSite with an agent 0.6.0, unchanged code, a pulled upload in the baseline
// and two local uploads: 2026/10/neu.png and 2026/10/gleich.png.
func uploadsSite(t *testing.T, f *fakeSite) (Options, string, *bytes.Buffer) {
	t.Helper()
	f.version = "0.6.0"
	o, siteDir, out := localSite(t, f)
	docroot := filepath.Join(siteDir, "public")
	write(t, docroot, "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */", 1700000000) // wie gezogen
	base, err := baseline.Load(siteDir)
	if err != nil {
		t.Fatal(err)
	}
	pulled(t, docroot, base, "uploads/2020/01/alt.jpg", "alt")
	if err := baseline.Save(siteDir, base); err != nil {
		t.Fatal(err)
	}
	write(t, docroot, "uploads/2026/10/neu.png", "neu", 1800000000)
	write(t, docroot, "uploads/2026/10/gleich.png", "gleich", 1800000000)
	return o, siteDir, out
}

// AC-140: nur Uploads, kein Code – neue Dateien gehen hoch, gleiche nicht; die Baseline kennt danach die neuen.
func TestRunPushesOnlyUploads(t *testing.T) {
	f := newFakeSite(t)
	f.uploadsHave = map[string]string{"2026/10/gleich.png": "gleich"}
	o, siteDir, out := uploadsSite(t, f)
	o.Uploads = []string{"2026/10/neu.png", "2026/10/gleich.png"}
	var report Result
	o.Report = &report

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload commit confirm" {
		t.Errorf("routes = %s", got)
	}
	if units := f.begins[1].Units; len(units) != 1 || units[0].Path != UploadsUnit || len(units[0].Files) != 2 {
		t.Fatalf("units = %+v", units)
	}
	if !reflect.DeepEqual(f.uploaded, map[string]string{"2026/10/neu.png": "neu"}) || f.upUnit["2026/10/neu.png"] != 0 {
		t.Errorf("uploaded = %v, units = %v", f.uploaded, f.upUnit)
	}
	if !reflect.DeepEqual(report.Units, []string{UploadsUnit}) || report.Status != "confirmed" {
		t.Errorf("report = %+v", report)
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/uploads/2026/10/neu.png"] != (baseline.FileStamp{Size: 3, MTime: 1800000000}) {
		t.Errorf("baseline = %v", base.Files)
	}
	if _, ok := base.Files["wp-content/uploads/2026/10/gleich.png"]; ok {
		t.Error("a file that was already there is not the push's")
	}
	if _, ok := base.Files["wp-content/uploads/2020/01/alt.jpg"]; !ok {
		t.Error("other uploads must stay in the baseline")
	}
	j, err := LoadJournal(siteDir, testID)
	if err != nil || !reflect.DeepEqual(j.Uploads, []string{"2026/10/neu.png"}) || len(j.Units) != 0 || !j.Applied {
		t.Errorf("journal = %+v, %v", j, err)
	}
	if !strings.Contains(out.String(), "uploads – 1 von 2 Dateien neu, 1 liegen schon auf der Site") {
		t.Errorf("output:\n%s", out)
	}
}

// Code und Uploads in einem Satz: uploads ist die letzte Einheit, ihr Upload trägt deren Index.
func TestRunPushesUploadsTogetherWithCode(t *testing.T) {
	f := newFakeSite(t)
	f.version = "0.6.0"
	o, siteDir, out := localSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "uploads/2026/10/neu.png", "neu", 1800000000)
	o.Uploads = []string{"2026/10/neu.png"}

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	units := f.begins[1].Units
	if len(units) != 2 || units[0].Path != "plugins/x" || units[1].Path != UploadsUnit {
		t.Fatalf("units = %+v", units)
	}
	if f.upUnit["2026/10/neu.png"] != 1 || f.upUnit["main.php"] != 0 {
		t.Errorf("upload units = %v", f.upUnit)
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1800000000 || base.Files["wp-content/uploads/2026/10/neu.png"].Size != 3 {
		t.Errorf("baseline = %v", base.Files)
	}
}

// AC-141: anderer Inhalt am selben Pfad – Abbruch nach dem Probelauf, auch mit --force.
func TestRunNeverReplacesAnUpload(t *testing.T) {
	f := newFakeSite(t)
	f.uploadsHave = map[string]string{"2026/10/neu.png": "anders"}
	o, _, out := uploadsSite(t, f)
	o.Uploads = []string{"2026/10/neu.png"}
	o.Force = true
	var plan map[string]any
	o.Event = func(name string, data any) {
		if name == "plan" {
			plan, _ = data.(map[string]any)
		}
	}

	err := Run(o)
	var exists *UploadExistsError
	if !errors.As(err, &exists) || !errors.Is(err, ErrUploadExists) || !reflect.DeepEqual(exists.Paths, []string{"2026/10/neu.png"}) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
	up, _ := plan["uploads"].(map[string]any)
	if !reflect.DeepEqual(up["conflicts"], []string{"2026/10/neu.png"}) || !reflect.DeepEqual(up["need"], []string{}) {
		t.Errorf("plan.uploads = %v", up)
	}
	if !strings.Contains(out.String(), "ersetzt nie einen Upload") {
		t.Errorf("output:\n%s", out)
	}
}

// AC-140: der Probelauf zeigt need/same/conflicts; ohne --uploads hat der Plan kein „uploads“.
func TestRunDryRunShowsTheUploads(t *testing.T) {
	f := newFakeSite(t)
	f.uploadsHave = map[string]string{"2026/10/gleich.png": "gleich"}
	o, _, _ := uploadsSite(t, f)
	o.Uploads = []string{"2026/10/neu.png", "2026/10/gleich.png"}
	o.DryRun = true
	var plan map[string]any
	o.Event = func(name string, data any) { plan, _ = data.(map[string]any) }

	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
	want := map[string]any{"need": []string{"2026/10/neu.png"}, "same": []string{"2026/10/gleich.png"}, "conflicts": []string{}}
	if !reflect.DeepEqual(plan["uploads"], want) {
		t.Errorf("plan.uploads = %#v", plan["uploads"])
	}

	f2 := newFakeSite(t)
	o2, _, _ := localSite(t, f2)
	o2.DryRun = true
	o2.Event = func(name string, data any) { plan, _ = data.(map[string]any) }
	if err := Run(o2); err != nil {
		t.Fatal(err)
	}
	if _, has := plan["uploads"]; has {
		t.Errorf("plan without --uploads = %v", plan)
	}
}

// A8: kein Code und jede Datei liegt schon da – nichts zu pushen, kein Push auf der Site.
func TestRunWithUploadsThatAreAllThere(t *testing.T) {
	f := newFakeSite(t)
	f.uploadsHave = map[string]string{"2026/10/neu.png": "neu", "2026/10/gleich.png": "gleich"}
	o, _, _ := uploadsSite(t, f)
	o.Uploads = []string{"2026/10/neu.png", "2026/10/gleich.png"}
	if err := Run(o); !errors.Is(err, ErrUploadsThere) || !errors.Is(err, ErrNothing) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
}

// Ein Agent < 0.6.0 kennt die Einheit nicht – egal ob er sie abweist oder annimmt.
func TestRunRefusesUploadsOnAnOldAgent(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := uploadsSite(t, f)
	f.version = "0.5.1"
	o.Uploads = []string{"2026/10/neu.png"}
	if err := Run(o); !errors.Is(err, ErrAgentNoUploads) {
		t.Fatalf("err = %v", err)
	}
	f.acceptOld = true
	if err := Run(o); !errors.Is(err, ErrAgentNoUploads) {
		t.Fatalf("an old agent that answers: %v", err)
	}
	if f.committed {
		t.Error("nothing may be swapped")
	}
}

// Was die CLI selbst sieht, stoppt den Push vor dem ersten Request.
func TestRunChecksTheUploadListLocally(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := uploadsSite(t, f)
	uploads := filepath.Join(siteDir, "public", "wp-content", "uploads")
	write(t, filepath.Join(siteDir, "public"), "uploads/2026/10/x.php", "<?php", 1800000000)

	o.Uploads = []string{"2026/10/x.php"}
	var typeErr *UploadTypeError
	if err := Run(o); !errors.As(err, &typeErr) || !errors.Is(err, ErrUploadTypeBlocked) {
		t.Fatalf("type: %v", err)
	}
	o.Uploads = []string{"2026/10/fehlt.png"}
	if err := Run(o); !errors.Is(err, ErrUploadMissing) {
		t.Fatalf("missing: %v", err)
	}
	if err := os.Symlink(filepath.Join(uploads, "2026", "10"), filepath.Join(uploads, "2026", "link")); err != nil {
		t.Fatal(err)
	}
	o.Uploads = []string{"2026/link/neu.png"}
	if err := Run(o); !errors.Is(err, ErrSymlink) {
		t.Fatalf("symlink: %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("local refusals must not cost a request: %v", f.routes)
	}
}

// Ablehnungen des Agents behalten Code und Meldung, tragen aber den Fehler der CLI.
func TestUploadErrorKeepsTheAgentCode(t *testing.T) {
	for code, want := range map[string]error{"wpsync_upload_exists": ErrUploadExists, "wpsync_upload_type_blocked": ErrUploadTypeBlocked} {
		err := uploadError(&agentapi.APIError{Status: 409, Code: code})
		var apiErr *agentapi.APIError
		if !errors.Is(err, want) || !errors.As(err, &apiErr) || apiErr.Code != code {
			t.Errorf("%s: %v", code, err)
		}
	}
	plain := errors.New("x")
	if uploadError(plain) != plain {
		t.Error("other errors stay as they are")
	}

	f := newFakeSite(t)
	f.beginCode = "wpsync_upload_type_blocked"
	o, _, _ := uploadsSite(t, f)
	o.Uploads = []string{"2026/10/neu.png"}
	if err := Run(o); !errors.Is(err, ErrUploadTypeBlocked) {
		t.Fatalf("err = %v", err)
	}
}

// AC-143: was die Rücknahme liegen liess, steht in warnings und in der Ausgabe.
func TestRunPassesOnWhatTheRollbackLeftInPlace(t *testing.T) {
	f := newFakeSite(t)
	f.broken = true
	f.rescueBody = `{"ok":true,"status":"rolled_back","warnings":["upload_changed_since_push"],"kept":["2026/10/neu.png"]}`
	o, _, out := uploadsSite(t, f)
	o.Uploads = []string{"2026/10/neu.png"}
	var report Result
	o.Report = &report

	var rolled *RolledBackError
	if err := Run(o); !errors.As(err, &rolled) {
		t.Fatalf("err = %v", err)
	}
	if !reflect.DeepEqual(report.Warnings, []string{"upload_changed_since_push"}) || report.Status != "rolled_back" {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), `bleibt liegen: "wp-content/uploads/2026/10/neu.png"`) {
		t.Errorf("output:\n%s", out)
	}
}

// AC-143: wpsync rollback vergisst die Uploads des Pushs in der Baseline – ausser denen, die bleiben.
func TestRollbackForgetsTheUploadsOfThePush(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := uploadsSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "uploads/2026/10/zwei.png", "zwei", 1800000000)
	o.Uploads = []string{"2026/10/neu.png", "2026/10/zwei.png"}
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	f.rbBody = `{"ok":true,"status":"rolled_back","warnings":["upload_changed_since_push"],"kept":["2026/10/zwei.png"]}`
	var report Result
	o.Report = &report

	if err := Rollback(o, testID); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	base, _ := baseline.Load(siteDir)
	if _, ok := base.Files["wp-content/uploads/2026/10/neu.png"]; ok {
		t.Error("neu.png is gone from the site, so it leaves the baseline")
	}
	if _, ok := base.Files["wp-content/uploads/2026/10/zwei.png"]; !ok {
		t.Error("zwei.png stays on the site and in the baseline")
	}
	if _, ok := base.Files["wp-content/uploads/2020/01/alt.jpg"]; !ok {
		t.Error("other uploads stay")
	}
	if !reflect.DeepEqual(report.Units, []string{UploadsUnit}) || !reflect.DeepEqual(report.Warnings, []string{"upload_changed_since_push"}) {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "bleibt liegen") {
		t.Errorf("output:\n%s", out)
	}
}
