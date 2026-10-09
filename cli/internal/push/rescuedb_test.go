package push

import (
	"bytes"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"reflect"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Spec Content-Push P3 §9: content=1 geht nur mit, wenn der Aufrufer die Inhalte zurückhaben will.
func TestRescueRollbackAsksForTheContentOnlyOnDemand(t *testing.T) {
	var forms []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.ParseForm()
		forms = append(forms, r.PostForm.Encode())
		w.Write([]byte(`{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"stale"}}`))
	}))
	defer srv.Close()

	notes, err := RescueRollbackNotes(srv.Client(), srv.URL+"/rescue.php", testID, "k3y", true)
	if err != nil || notes.Content == nil || notes.Content.State != "rolled_back" || notes.Content.Cache != "stale" {
		t.Fatalf("notes = %+v, %v", notes, err)
	}
	if _, err := RescueRollbackNotes(srv.Client(), srv.URL+"/rescue.php", testID, "k3y", false); err != nil {
		t.Fatal(err)
	}
	if err := RescueRollback(srv.Client(), srv.URL+"/rescue.php", testID, "k3y"); err != nil {
		t.Fatal(err)
	}
	want := []string{
		"action=rollback&content=1&key=k3y&push_id=" + testID,
		"action=rollback&key=k3y&push_id=" + testID,
		"action=rollback&key=k3y&push_id=" + testID,
	}
	if !reflect.DeepEqual(forms, want) {
		t.Errorf("forms = %q", forms)
	}
}

// §7.6: changed_since_push nennt bis zu 200 Schlüssel – die Antwort passt nicht mehr in 4.000 Bytes.
func TestRescueRollbackReadsALongAnswer(t *testing.T) {
	var keys []string
	for i := 0; i < 200; i++ {
		keys = append(keys, fmt.Sprintf(`{"table":"postmeta","key":"%d\u0000_elementor_data_with_a_long_name"}`, 1000000+i))
	}
	body := `{"ok":true,"status":"rolled_back","content":{"state":"kept","error":{"code":"changed_since_push","keys":[` + strings.Join(keys, ",") + `],"total":431}},"warnings":["content_not_rolled_back"]}`
	if len(body) < 8000 {
		t.Fatalf("answer too short for this test: %d", len(body))
	}
	srv := rescueServer(t, 200, body, nil)
	defer srv.Close()
	notes, err := RescueRollbackNotes(srv.Client(), srv.URL+"/rescue.php", testID, "k", true)
	if err != nil {
		t.Fatal(err)
	}
	if notes.Content == nil || notes.Content.Error == nil || len(notes.Content.Error.Keys) != 200 || notes.Content.Error.Total != 431 {
		t.Errorf("content = %+v", notes.Content)
	}
}

// R10: 423 heisst „eine andere Rücknahme oder der Commit läuft“ – ein eigener Fehler, den der Aufrufer wiederholt.
func TestRescueBusyIsItsOwnError(t *testing.T) {
	srv := rescueServer(t, 423, `{"ok":false,"error":"busy"}`, nil)
	defer srv.Close()
	_, err := RescueRollbackNotes(srv.Client(), srv.URL+"/rescue.php", testID, "k", true)
	if !errors.Is(err, ErrRescueBusy) {
		t.Errorf("err = %v, want ErrRescueBusy", err)
	}
	// 423 von etwas anderem (eine Firewall-Seite) ist kein „busy“.
	other := rescueServer(t, 423, `<html>Locked</html>`, nil)
	defer other.Close()
	if _, err := RescueRollbackNotes(other.Client(), other.URL+"/rescue.php", testID, "k", true); err == nil || errors.Is(err, ErrRescueBusy) {
		t.Errorf("err = %v", err)
	}
}

// §7.6: scheitert der Code nach gelungener DB-Rücknahme, sagt die Meldung, dass die Inhalte schon zurück sind.
func TestRescueRestoreFailedNamesTheContent(t *testing.T) {
	srv := rescueServer(t, 500, `{"ok":false,"error":"restore failed","units":["plugins/x"],"content":{"state":"rolled_back"}}`, nil)
	defer srv.Close()
	_, err := RescueRollbackNotes(srv.Client(), srv.URL+"/rescue.php", testID, "k", true)
	if err == nil || !strings.Contains(err.Error(), "restore failed") || !strings.Contains(err.Error(), "die Inhalte sind schon zurückgenommen") {
		t.Errorf("err = %v", err)
	}
	plain := rescueServer(t, 500, `{"ok":false,"error":"restore failed","units":["plugins/x"]}`, nil)
	defer plain.Close()
	if _, err := RescueRollbackNotes(plain.Client(), plain.URL+"/rescue.php", testID, "k", false); err == nil || strings.Contains(err.Error(), "Inhalte") {
		t.Errorf("err = %v", err)
	}
}

// AC-175: ein aufgeräumter Stub, an dessen Stelle der Server umleitet (WordPress „canonical redirect“), ist weg – nicht kaputt.
func TestRescueStubThatRedirectsIsGone(t *testing.T) {
	stub := "/wpsync-rescue-" + strings.Repeat("a", 32) + ".php"
	for _, status := range []int{301, 302, 303, 307, 308} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Location", "/")
			w.WriteHeader(status)
			w.Write([]byte("<html>Moved</html>"))
		}))
		if err := RescueRollback(srv.Client(), srv.URL+stub, testID, "k"); !errors.Is(err, ErrRescueGone) {
			t.Errorf("HTTP %d on the stub: err = %v, want ErrRescueGone", status, err)
		}
		if _, err := RescueRollbackNotes(srv.Client(), srv.URL+stub, testID, "k", true); !errors.Is(err, ErrRescueGone) {
			t.Errorf("HTTP %d on the stub with content: err = %v, want ErrRescueGone", status, err)
		}
		// Unter dem Plugin-Pfad bleibt eine Umleitung ein gewöhnlicher Fehler.
		err := RescueRollback(srv.Client(), srv.URL+"/wp-content/plugins/wpsync-agent/rescue.php", testID, "k")
		if err == nil || errors.Is(err, ErrRescueGone) {
			t.Errorf("HTTP %d on the plugin path: err = %v", status, err)
		}
		srv.Close()
	}
	// Andere Antworten ohne JSON am Stub bleiben, was sie sind.
	for _, status := range []int{200, 403, 500} {
		srv := rescueServer(t, status, `<html>x</html>`, nil)
		if err := RescueRollback(srv.Client(), srv.URL+stub, testID, "k"); err == nil || errors.Is(err, ErrRescueGone) {
			t.Errorf("HTTP %d on the stub: err = %v", status, err)
		}
		srv.Close()
	}
}

// R8: action=cache – gelungen ist es nur mit {"ok":true,"cache":"flushed"}.
func TestRescueCache(t *testing.T) {
	var form map[string]string
	ok := rescueServer(t, 200, `{"ok":true,"cache":"flushed"}`, &form)
	defer ok.Close()
	if err := RescueCache(ok.Client(), ok.URL+"/rescue.php", testID, "k3y"); err != nil {
		t.Fatal(err)
	}
	if form["action"] != "cache" || form["push_id"] != testID || form["key"] != "k3y" {
		t.Errorf("form = %v", form)
	}
	for _, c := range []struct {
		status int
		body   string
	}{
		{500, `{"ok":false,"error":"cache failed"}`},
		{409, `{"ok":false,"error":"nothing to flush"}`},
		{400, `{"ok":false,"error":"bad request"}`}, // rescue.php of an agent 0.7.x knows no such action
		{200, `{"ok":true}`},
		{200, `{"ok":true,"cache":"stale"}`},
		{200, `<html>There has been a critical error on this website.</html>`},
		{503, `Briefly unavailable for scheduled maintenance.`},
	} {
		srv := rescueServer(t, c.status, c.body, nil)
		if err := RescueCache(srv.Client(), srv.URL+"/rescue.php", testID, "k"); err == nil {
			t.Errorf("HTTP %d %s: want an error", c.status, c.body)
		}
		srv.Close()
	}
}

// brokenContentSite is a push of code and content after which the frontend fails and the agent
// does not answer the rollback (HTTP 500) – the case rescue.php exists for.
func brokenContentSite(t *testing.T) (*fakeSite, Options, string, *bytes.Buffer, *Result) {
	t.Helper()
	f := newFakeSite(t)
	f.broken, f.rollback = true, 500
	f.rescueDB = &agentapi.RescueDBState{OK: true}
	o, siteDir, out := contentSite(t, f)
	f.version = "0.8.0"
	report := &Result{}
	o.Report = report
	return f, o, siteDir, out, report
}

// AC-158 (CLI-Seite): die Rücknahme nach dem Health-Check stellt über rescue.php alles wieder her –
// Exit 43 ohne content_not_rolled_back.
func TestRunTakesTheWholeSetBackThroughRescue(t *testing.T) {
	f, o, siteDir, out, report := brokenContentSite(t)
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"none"}}`

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if len(rolled.Warnings) != 0 || rolled.Via != "rescue" || rolled.Content != "rolled_back" {
		t.Errorf("rolled = %+v", rolled)
	}
	// Der Agent zuerst (D13), dann rescue.php, dann einmal push/list, damit der Agent sofort nachholt.
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback rescue list") {
		t.Errorf("routes = %s", got)
	}
	if len(f.rescueForms) != 1 || f.rescueForms[0].Get("content") != "1" {
		t.Errorf("forms = %v", f.rescueForms)
	}
	if report.Status != "rolled_back" || len(report.Warnings) != 0 || report.Via != "rescue" || report.Content != nil || report.ContentError != nil {
		t.Errorf("report = %+v", report)
	}
	if contentFile(t, siteDir, "manifest.jsonl") != manifestBefore || contentFile(t, siteDir, "baseline.jsonl") != baselineBefore {
		t.Error("the health rollback has nothing to revert: manifest and baseline never carried the push")
	}
	if !strings.Contains(out.String(), "über rescue.php") {
		t.Errorf("output:\n%s", out)
	}
}

// AC-169: sagt rescue.php cache: "stale", ruft die CLI einmal action=cache – mit demselben Schlüssel.
func TestRunFlushesAStaleObjectCacheThroughRescue(t *testing.T) {
	f, o, _, out, report := brokenContentSite(t)
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"stale"}}`

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback rescue cache list") {
		t.Errorf("routes = %s", got)
	}
	if f.rescueKey != RescueKey("secret", testID, testSalt) {
		t.Errorf("the cache step carries the key of the push: %q", f.rescueKey)
	}
	if len(report.Warnings) != 0 {
		t.Errorf("warnings = %v", report.Warnings)
	}
}

// AC-169: scheitert der Cache-Schritt, bleibt die Rücknahme gültig – die CLI meldet object_cache_stale und nennt den Ausweg.
func TestRunNamesAnObjectCacheThatStaysStale(t *testing.T) {
	for _, c := range []struct {
		status int
		body   string
	}{{500, `{"ok":false,"error":"cache failed"}`}, {200, `<html>critical error</html>`}} {
		f, o, _, out, report := brokenContentSite(t)
		f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"stale"}}`
		f.cacheStatus, f.cacheBody = c.status, c.body

		err := Run(o)
		var rolled *RolledBackError
		if !errors.As(err, &rolled) {
			t.Fatalf("err = %v\n%s", err, out)
		}
		if !reflect.DeepEqual(report.Warnings, []string{WarningObjectCacheStale}) || report.Status != "rolled_back" {
			t.Errorf("report = %+v", report)
		}
		if slices.Contains(report.Warnings, WarningContentNotRolledBack) || report.Content != nil {
			t.Errorf("the content is back: %+v", report)
		}
		if !strings.Contains(out.String(), "Der Object-Cache der Site trägt noch den gepushten Stand – beim Hoster leeren, falls die Site nicht antwortet.") {
			t.Errorf("output:\n%s", out)
		}
	}
}

// R7, AC-163: liess rescue.php die Inhalte stehen, sagt das Ergebnis warum – Code und Uploads sind trotzdem zurück.
func TestRunNamesWhyRescueKeptTheContent(t *testing.T) {
	f, o, _, out, report := brokenContentSite(t)
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"kept","error":{"code":"changed_since_push","keys":[{"table":"posts","key":"219"}],"total":1}},"warnings":["content_not_rolled_back"]}`

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || !reflect.DeepEqual(rolled.Warnings, []string{WarningContentNotRolledBack}) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if rolled.ContentError == nil || rolled.ContentError.Code != "changed_since_push" || rolled.Content != "kept" {
		t.Errorf("rolled = %+v", rolled)
	}
	want := &ContentErrorReport{Code: "changed_since_push", Keys: []agentapi.ContentKey{{Table: "posts", Key: "219"}}, Total: 1}
	if !reflect.DeepEqual(report.ContentError, want) || report.Via != "rescue" {
		t.Errorf("report = %+v, content_error = %+v", report, report.ContentError)
	}
	if report.Content == nil {
		t.Error("the content still stands on the site: report.Content must stay")
	}
	// Nichts nachzuholen, solange die Inhalte stehen: kein Cache-Schritt, kein push/list.
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback rescue") {
		t.Errorf("routes = %s", got)
	}
}

// AC-174: CLI 0.8.0 gegen Agent 0.7.x – rescue.php ignoriert content=1 und sagt nichts über die Inhalte: wie P2.
func TestRunAgainstAnOlderRescueBehavesLikeBefore(t *testing.T) {
	f := newFakeSite(t)
	f.broken, f.rollback = true, 500
	o, _, out := contentSite(t, f) // agent 0.7.0: kein rescue.db, rescue.php antwortet ohne content
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
	if !reflect.DeepEqual(report.Warnings, []string{WarningContentNotRolledBack}) || report.ContentError != nil || report.Content == nil {
		t.Errorf("report = %+v", report)
	}
	if strings.Contains(out.String(), "Notfall-Rücknahme der Inhalte nicht möglich") {
		t.Errorf("an agent without rescue.db gets no hint:\n%s", out)
	}
}

// R15: was an eingefügten Objekten stehen blieb, steht im Ergebnis – die Rücknahme gilt.
func TestRunNamesWhatRescueLeftOnInsertedObjects(t *testing.T) {
	f, o, _, out, report := brokenContentSite(t)
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"none","left":[{"table":"postmeta","key":"1000001\u0000farbe"}],"left_total":1},"warnings":["content_left_extra"]}`

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if !reflect.DeepEqual(report.Warnings, []string{WarningContentLeftExtra}) || report.ContentLeftTotal != 1 || len(report.ContentLeft) != 1 || report.ContentLeft[0].Table != "postmeta" {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "blieb stehen") || !strings.Contains(out.String(), "postmeta") || !strings.Contains(out.String(), "id_has_leftovers") {
		t.Errorf("output:\n%s", out)
	}
}

// R10: bei 423 wiederholt die CLI dreimal im Abstand von 2 s – beim Agent wie bei rescue.php.
func TestRunRepeatsARollbackWhileTheSiteIsBusy(t *testing.T) {
	f, o, _, out, report := brokenContentSite(t)
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"none"}}`
	f.rescueBusy = 3
	var slept []time.Duration
	o.Sleep = func(d time.Duration) { slept = append(slept, d) }

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if len(f.rescueForms) != 4 || report.Status != "rolled_back" {
		t.Errorf("rescue was asked %d times, report = %+v", len(f.rescueForms), report)
	}
	waits := 0
	for _, d := range slept {
		if d == 2*time.Second {
			waits++
		}
	}
	if waits < 3 {
		t.Errorf("slept = %v", slept)
	}

	// Bleibt es dabei, ist die Rücknahme gescheitert – nie stillschweigend.
	f, o, _, out, report = brokenContentSite(t)
	f.rescueBusy = 4
	err = Run(o)
	if errors.As(err, &rolled) || err == nil || !strings.Contains(err.Error(), "ROLLBACK FEHLGESCHLAGEN") || !errors.Is(err, ErrRescueBusy) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if len(f.rescueForms) != 4 || report.Status != "committed" {
		t.Errorf("rescue was asked %d times, report = %+v", len(f.rescueForms), report)
	}

	// Der Agent: wpsync_push_busy (423) ist keine Ablehnung – nach der Wiederholung nimmt er alles zurück.
	f, o, _, out, report = brokenContentSite(t)
	f.rollback, f.rbBusy = 200, 2
	err = Run(o)
	if !errors.As(err, &rolled) || rolled.Via != "agent" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback rollback rollback") || strings.Contains(got, "rescue") {
		t.Errorf("routes = %s", got)
	}
	if report.Via != "agent" || len(report.Warnings) != 0 {
		t.Errorf("report = %+v", report)
	}
}

// Ohne Inhalte bleibt der Aufruf von rescue.php, wie er war: kein content=1, kein Cache-Schritt, kein push/list.
func TestRunWithoutContentAsksRescueForNothingNew(t *testing.T) {
	f := newFakeSite(t)
	f.broken = true
	f.version = "0.8.0"
	o, _, out := localSite(t, f)
	var report Result
	o.Report = &report
	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if len(f.rescueForms) != 1 || f.rescueForms[0].Has("content") {
		t.Errorf("forms = %v", f.rescueForms)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rescue") {
		t.Errorf("routes = %s", got)
	}
	if rolled.Content != "" || rolled.Via != "rescue" || report.Via != "rescue" {
		t.Errorf("rolled = %+v, report = %+v", rolled, report)
	}
}

// AC-158 (wpsync rollback): antwortet der Agent nicht, nimmt rescue.php auch die Inhalte zurück –
// Manifest, Baseline und Journal gehen zurück wie bei der Rücknahme über den Agent. Exit 0.
func TestRollbackThroughRescueRevertsManifestAndBaseline(t *testing.T) {
	for _, state := range []string{"rolled_back", "nothing"} {
		f, o, siteDir := contentPushed(t)
		f.rollback = 500
		f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"` + state + `"}}`
		var out bytes.Buffer
		o.Out = &out
		var report Result
		o.Report = &report

		if err := Rollback(o, testID); err != nil {
			t.Fatal(err)
		}
		if got := strings.Join(f.routes, " "); got != "rollback rescue list" {
			t.Errorf("%s: routes = %s", state, got)
		}
		if len(f.rescueForms) != 1 || f.rescueForms[0].Get("content") != "1" {
			t.Errorf("forms = %v", f.rescueForms)
		}
		if len(report.Warnings) != 0 || report.Status != "rolled_back" || report.Via != "rescue" || report.ContentError != nil {
			t.Errorf("%s: report = %+v", state, report)
		}
		for _, line := range strings.Split(strings.TrimSpace(manifestBefore), "\n") {
			if !strings.Contains(contentFile(t, siteDir, "manifest.jsonl"), line+"\n") {
				t.Errorf("%s: the manifest lacks the line of before the push: %s", state, line)
			}
		}
		if len(strings.Split(strings.TrimSpace(contentFile(t, siteDir, "manifest.jsonl")), "\n")) != len(strings.Split(strings.TrimSpace(manifestBefore), "\n")) {
			t.Errorf("%s: manifest:\n%s", state, contentFile(t, siteDir, "manifest.jsonl"))
		}
		if j, _ := LoadJournal(siteDir, testID); j.Applied || j.Content.Applied {
			t.Errorf("%s: journal = %+v", state, j)
		}
		if !strings.Contains(out.String(), "✓ Push "+testID+" ist zurückgerollt.") || strings.Contains(out.String(), "stehen noch") {
			t.Errorf("%s: output:\n%s", state, &out)
		}
	}
}

// AC-163 (wpsync rollback): blieben die Inhalte stehen, bleiben Manifest und Baseline beim gepushten Stand; das Ergebnis nennt den Grund.
func TestRollbackThroughRescueThatKeptTheContent(t *testing.T) {
	f, o, siteDir := contentPushed(t)
	f.rollback = 500
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"kept","error":{"code":"db_unreachable"}},"warnings":["content_not_rolled_back"]}`
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
	if !reflect.DeepEqual(report.Warnings, []string{WarningContentNotRolledBack}) || report.ContentError == nil || report.ContentError.Code != "db_unreachable" || report.Via != "rescue" {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "die Inhalte des Pushs stehen noch") || !strings.Contains(out.String(), "db_unreachable") {
		t.Errorf("output:\n%s", &out)
	}
	if contentFile(t, siteDir, "manifest.jsonl") != patched {
		t.Error("while the content stands, the manifest keeps the pushed state")
	}
	if j, _ := LoadJournal(siteDir, testID); !j.Content.Applied {
		t.Errorf("journal = %+v", j)
	}
}

// wpsync rollback: auch hier der Cache-Schritt und die Wiederholung bei 423.
func TestRollbackThroughRescueFlushesTheCacheAndWaitsWhileBusy(t *testing.T) {
	f, o, _ := contentPushed(t)
	f.rollback, f.rescueBusy = 500, 2
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"stale"}}`
	f.cacheStatus, f.cacheBody = 500, `{"ok":false,"error":"cache failed"}`
	var out bytes.Buffer
	o.Out = &out
	var report Result
	o.Report = &report

	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback rescue rescue rescue cache list" {
		t.Errorf("routes = %s", got)
	}
	if !reflect.DeepEqual(report.Warnings, []string{WarningObjectCacheStale}) {
		t.Errorf("warnings = %v", report.Warnings)
	}
	if !strings.Contains(out.String(), "Der Object-Cache der Site trägt noch den gepushten Stand") {
		t.Errorf("output:\n%s", &out)
	}

	// Der Agent ist beschäftigt (423): wiederholen statt aufzugeben oder auf rescue.php auszuweichen.
	f, o, _ = contentPushed(t)
	f.rbBusy = 3
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback rollback rollback rollback" {
		t.Errorf("routes = %s", got)
	}
	f, o, _ = contentPushed(t)
	f.rbBusy = 4
	err := Rollback(o, testID)
	var apiErr *agentapi.APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 423 || strings.Contains(strings.Join(f.routes, " "), "rescue") {
		t.Errorf("err = %v, routes = %v", err, f.routes)
	}
}

// R12: ohne Umschlag wird trotzdem gepusht – mit Hinweis und der Warnung rescue_db_unavailable.
func TestRunWarnsWhenRescueCannotTakeTheContentBack(t *testing.T) {
	f := newFakeSite(t)
	f.rescueDB = &agentapi.RescueDBState{Reason: "no_image_key"}
	o, _, out := contentSite(t, f)
	f.version = "0.8.0"
	var report Result
	o.Report = &report
	var plan map[string]any
	o.Event = func(name string, data any) {
		if name == "plan" {
			plan = data.(map[string]any)
		}
	}

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if report.Status != "confirmed" || !reflect.DeepEqual(report.Warnings, []string{WarningRescueDBUnavailable}) {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "Notfall-Rücknahme der Inhalte nicht möglich (no_image_key) – bei einem Ausfall gehen nur Code und Uploads zurück") {
		t.Errorf("output:\n%s", out)
	}
	if strings.Count(out.String(), "Notfall-Rücknahme der Inhalte nicht möglich") != 1 {
		t.Errorf("the hint comes once:\n%s", out)
	}
	if db, _ := plan["rescue_db"].(*agentapi.RescueDBState); db == nil || db.OK || db.Reason != "no_image_key" {
		t.Errorf("plan event = %+v", plan)
	}

	// Im Probelauf dasselbe: der Hinweis und die Warnung, nichts wird angelegt.
	f = newFakeSite(t)
	f.rescueDB = &agentapi.RescueDBState{Reason: "driver"}
	o, _, out = contentSite(t, f)
	f.version = "0.8.0"
	o.DryRun, o.Report = true, &report
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if report.Status != "dry_run" || !reflect.DeepEqual(report.Warnings, []string{WarningRescueDBUnavailable}) || len(f.begins) != 1 {
		t.Errorf("report = %+v, begins = %d", report, len(f.begins))
	}
}

// R12, AC-160: --require-rescue-db bricht vor dem Tausch ab – schon nach dem Probelauf, wenn der Agent es dort sagt.
func TestRunRequireRescueDBStopsBeforeTheSwap(t *testing.T) {
	f := newFakeSite(t)
	f.rescueDB = &agentapi.RescueDBState{Reason: "no_crypto"}
	o, _, out := contentSite(t, f)
	f.version = "0.8.0"
	o.RequireRescueDB = true
	var report Result
	o.Report = &report

	err := Run(o)
	var need *RescueDBError
	if !errors.As(err, &need) || need.Reason != "no_crypto" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if len(f.begins) != 1 || !f.begins[0].Dry || f.committed {
		t.Errorf("nothing may be created: begins = %d, committed = %v", len(f.begins), f.committed)
	}
	if report.PushID != "" || report.Status != "" {
		t.Errorf("report = %+v", report)
	}

	// Sagt erst der echte Begin nein (die Probe scheiterte), ist der Push angelegt, aber nichts hochgeladen oder getauscht.
	f = newFakeSite(t)
	f.rescueDB = &agentapi.RescueDBState{OK: true}
	f.rescueDBReal = &agentapi.RescueDBState{Reason: "probe_failed"}
	o, _, out = contentSite(t, f)
	f.version = "0.8.0"
	o.RequireRescueDB = true
	err = Run(o)
	if !errors.As(err, &need) || need.Reason != "probe_failed" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); strings.Contains(got, "upload") || strings.Contains(got, "commit") || f.committed {
		t.Errorf("routes = %s", got)
	}
	if !strings.Contains(err.Error(), "verfällt auf dem Server") {
		t.Errorf("the message must say what happens to the push that was created: %v", err)
	}

	// Ohne das Flag wird in demselben Fall gepusht und gewarnt.
	f = newFakeSite(t)
	f.rescueDB = &agentapi.RescueDBState{OK: true}
	f.rescueDBReal = &agentapi.RescueDBState{Reason: "probe_failed"}
	o, _, out = contentSite(t, f)
	f.version = "0.8.0"
	o.Report = &report
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !reflect.DeepEqual(report.Warnings, []string{WarningRescueDBUnavailable}) || !strings.Contains(out.String(), "(probe_failed)") {
		t.Errorf("report = %+v\n%s", report, out)
	}
}

// AC-174: ein Agent 0.7.x nennt kein rescue.db. Ohne Flag wie P2 – kein Hinweis, keine Warnung; mit Flag wird nicht gepusht.
func TestRunRequireRescueDBAgainstAnOlderAgent(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := contentSite(t, f) // 0.7.0
	var report Result
	o.Report = &report
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if len(report.Warnings) != 0 || strings.Contains(out.String(), "Notfall-Rücknahme") {
		t.Errorf("report = %+v\n%s", report, out)
	}

	f = newFakeSite(t)
	o, _, out = contentSite(t, f)
	o.RequireRescueDB = true
	err := Run(o)
	var need *RescueDBError
	if !errors.As(err, &need) || need.Reason != "agent_outdated" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if len(f.begins) != 1 {
		t.Errorf("begins = %d", len(f.begins))
	}
}

// Ohne Inhalte verlangt das Flag nichts: Code und Uploads nimmt rescue.php immer zurück.
func TestRunRequireRescueDBMeansNothingWithoutContent(t *testing.T) {
	f := newFakeSite(t)
	f.version = "0.8.0"
	o, _, out := localSite(t, f)
	o.RequireRescueDB = true
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
}

// §8.2: wpsync pushes nennt, dass rescue.php die Inhalte zurückgenommen hat.
func TestPushesNamesARollbackThroughRescue(t *testing.T) {
	f := newFakeSite(t)
	f.list = `{"pushes":[{"push_id":"` + testID + `","device":"mac","target":"live","status":"rolled_back","created":1791158400,
"units":[{"path":"plugins/x","files":2,"uploaded":1},{"path":"content","files":4,"uploaded":4,"via":"rescue","post_actions":[{"step":"object_cache","ok":true}]}]}]}`
	o, _, out := localSite(t, f)
	if err := Pushes(o); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.String(), "content (über rescue.php zurückgenommen)") {
		t.Errorf("output:\n%s", out)
	}
}

// Security-Review P3, N7: antwortet rescue.php mit HTTP 200, aber ohne status "rolled_back", gilt
// der Push nicht als zurückgerollt – weder nach dem Health-Check noch bei wpsync rollback. Manifest,
// Baseline und Journal bleiben, wie sie sind, und die CLI ruft weder den Cache-Schritt noch push/list.
func TestAnAnswerWithoutTheStatusIsNoRollback(t *testing.T) {
	for _, body := range []string{`{"ok":true}`, `{"ok":true,"status":"committed","content":{"state":"rolled_back","cache":"stale"}}`} {
		f, o, _, out, report := brokenContentSite(t)
		f.rescueBody = body
		err := Run(o)
		var rolled *RolledBackError
		if err == nil || errors.As(err, &rolled) || !strings.Contains(err.Error(), "ROLLBACK FEHLGESCHLAGEN") || !strings.Contains(err.Error(), "rolled_back") {
			t.Fatalf("%s: err = %v\n%s", body, err, out)
		}
		if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback rescue") {
			t.Errorf("%s: routes = %s", body, got)
		}
		if report.Status == "rolled_back" {
			t.Errorf("%s: report = %+v", body, report)
		}

		f, o, siteDir := contentPushed(t)
		f.rollback = 500
		f.rescueBody = body
		manifest, baseline := contentFile(t, siteDir, "manifest.jsonl"), contentFile(t, siteDir, "baseline.jsonl")
		var buf bytes.Buffer
		o.Out = &buf
		var result Result
		o.Report = &result
		err = Rollback(o, testID)
		if err == nil || !strings.Contains(err.Error(), "Rollback über rescue.php fehlgeschlagen") {
			t.Fatalf("%s: err = %v\n%s", body, err, &buf)
		}
		if got := strings.Join(f.routes, " "); got != "rollback rescue" {
			t.Errorf("%s: routes = %s", body, got)
		}
		if contentFile(t, siteDir, "manifest.jsonl") != manifest || contentFile(t, siteDir, "baseline.jsonl") != baseline {
			t.Errorf("%s: manifest or baseline were reverted", body)
		}
		if j, _ := LoadJournal(siteDir, testID); !j.Applied || !j.Content.Applied {
			t.Errorf("%s: journal = %+v", body, j)
		}
		if strings.Contains(buf.String(), "ist zurückgerollt") || result.Status == "rolled_back" {
			t.Errorf("%s: output:\n%s\nresult = %+v", body, &buf, result)
		}
	}
}
