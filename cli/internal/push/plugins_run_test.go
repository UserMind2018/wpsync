package push

import (
	"bytes"
	"encoding/json"
	"errors"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

const kundeHead = "<?php\n/**\n * Plugin Name: Kunde Widgets\n * Version: 1.2.0\n */\n"

// pluginSite is localSite with an agent 0.9.0 that can seal the rescue envelope, and a new plugin
// plugins/kunde in the working copy that registers an activation hook. plugins/x is edited (localSite).
func pluginSite(t *testing.T, f *fakeSite) (Options, string, *bytes.Buffer) {
	t.Helper()
	f.version = "0.9.0"
	f.rescueDB = &agentapi.RescueDBState{OK: true}
	o, siteDir, out := localSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "plugins/kunde/kunde.php", kundeHead+"register_activation_hook(__FILE__, 'kunde_on');\n", 1800000000)
	return o, siteDir, out
}

// addPulled puts a file into the working copy as a pull delivered it: on disk and in the baseline.
func addPulled(t *testing.T, siteDir, rel, content string) {
	t.Helper()
	base, err := baseline.Load(siteDir)
	if err != nil {
		t.Fatal(err)
	}
	pulled(t, filepath.Join(siteDir, "public"), base, rel, content)
	if err := baseline.Save(siteDir, base); err != nil {
		t.Fatal(err)
	}
}

func unitPaths(req agentapi.PushBeginRequest) []string {
	var out []string
	for _, u := range req.Units {
		out = append(out, u.Path)
	}
	return out
}

// AC-194, AC-199, AC-208: der Probelauf – ohne Fenster – schickt Auftrag und Köpfe und nennt den Plan samt Hinweisen.
func TestRunDryRunPlansThePluginState(t *testing.T) {
	f := newFakeSite(t)
	f.window = false
	f.pluginNames = map[string][2]string{"plugins/kunde": {"Kunde Widgets", "1.2.0"}, "plugins/borlabs-cookie": {"Borlabs Cookie", "3.2.1"}}
	f.deactHooks = map[string]bool{"plugins/borlabs-cookie": true}
	o, _, out := pluginSite(t, f)
	o.DryRun = true
	o.Activate, o.Deactivate = []string{"wp-content/plugins/kunde/"}, []string{"plugins/borlabs-cookie"}
	var plan map[string]any
	o.Event = func(name string, data any) {
		if name == "plan" {
			raw, _ := json.Marshal(data)
			json.Unmarshal(raw, &plan)
		}
	}
	var report Result
	o.Report = &report

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
	req := f.begins[0]
	if !reflect.DeepEqual(req.Activate, []string{"plugins/kunde"}) || !reflect.DeepEqual(req.Deactivate, []string{"plugins/borlabs-cookie"}) {
		t.Errorf("wish = %v / %v", req.Activate, req.Deactivate)
	}
	// A3, U14: die Einheit aus --activate ist im Satz, obwohl sie neu und nicht eigens genannt ist.
	if !reflect.DeepEqual(unitPaths(req), []string{"plugins/kunde", "plugins/x"}) {
		t.Errorf("units = %v", unitPaths(req))
	}
	if head := req.PluginHeads["plugins/kunde"]["kunde.php"]; !strings.HasPrefix(string(head), kundeHead) || len(req.PluginHeads) != 1 {
		t.Errorf("plugin_heads = %v", req.PluginHeads)
	}
	plugins, _ := plan["plugins"].(map[string]any)
	if plugins["ok"] != true || len(plugins["activate"].([]any)) != 1 || len(plugins["deactivate"].([]any)) != 1 {
		t.Errorf("plan.plugins = %v", plan["plugins"])
	}
	if !reflect.DeepEqual(plan["hooks_skipped"], map[string]any{"activate": []any{"plugins/kunde"}, "deactivate": []any{"plugins/borlabs-cookie"}}) {
		t.Errorf("plan.hooks_skipped = %v", plan["hooks_skipped"])
	}
	if !reflect.DeepEqual(plan["rescue_db"], map[string]any{"ok": true}) {
		t.Errorf("plan.rescue_db = %v", plan["rescue_db"])
	}
	if report.Status != "dry_run" || !slices.Contains(report.Warnings, WarningDeactivationReview) || report.Plugins != nil {
		t.Errorf("report = %+v", report)
	}
	for _, want := range []string{"aktivieren:   plugins/kunde – Kunde Widgets 1.2.0 (neu)", "deaktivieren: plugins/borlabs-cookie – Borlabs Cookie 3.2.1", "läuft danach nicht mehr"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output misses %q:\n%s", want, out)
		}
	}
}

// A3, AC-194: --activate nimmt auch eine lokal unveränderte Einheit in den Satz.
func TestRunActivateTakesAnUnchangedUnitIntoTheSet(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := pluginSite(t, f)
	addPulled(t, siteDir, "plugins/same/same.php", "<?php\n/* Plugin Name: Same */")
	o.Units = []string{"plugins/x"}
	o.Activate = []string{"plugins/same"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload commit confirm" {
		t.Errorf("routes = %s", got)
	}
	req := f.begins[1]
	if !reflect.DeepEqual(unitPaths(req), []string{"plugins/same", "plugins/x"}) || len(req.Units[0].Files) != 1 || len(req.Units[0].Base) != 1 {
		t.Errorf("units = %+v", req.Units)
	}
	if _, sent := f.uploaded["same.php"]; sent {
		t.Error("an unchanged file needs no upload – the agent builds the unit from what it has")
	}
	j, err := LoadJournal(siteDir, testID)
	if err != nil || !reflect.DeepEqual(j.Activate, []string{"plugins/same"}) || !j.hasDB() {
		t.Errorf("journal = %+v, err = %v", j, err)
	}
}

// A20, AC-202: Deaktivieren braucht weder eine Einheit noch einen lokalen Ordner – auch mit --no-code.
func TestRunDeactivateAloneNeedsNoUnit(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := pluginSite(t, f)
	o.NoCode = true
	o.Deactivate = []string{"plugins/alt"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin commit confirm" {
		t.Errorf("routes = %s", got)
	}
	if req := f.begins[1]; len(req.Units) != 0 || !reflect.DeepEqual(req.Deactivate, []string{"plugins/alt"}) || req.PluginHeads != nil {
		t.Errorf("begin = %+v", req)
	}
	if j, err := LoadJournal(siteDir, testID); err != nil || !reflect.DeepEqual(j.Deactivate, []string{"plugins/alt"}) || j.Content != nil || !j.hasDB() {
		t.Errorf("journal = %+v, err = %v", j, err)
	}
	if strings.Contains(out.String(), "nichts zu pushen") {
		t.Errorf("output:\n%s", out)
	}
}

// AC-196, A19: gegen einen Agent unter 0.9.0 ist es Exit 11 – erkannt an der Version und am Feld plugins, ohne dass etwas übertragen wurde.
func TestRunRefusesAnAgentThatCannotSwitchPlugins(t *testing.T) {
	for name, prepare := range map[string]func(f *fakeSite){
		"old version, no field":  func(f *fakeSite) { f.version, f.noPlugins = "0.8.0", true },
		"new version, no field":  func(f *fakeSite) { f.noPlugins = true },
		"field, but old version": func(f *fakeSite) { f.version = "0.8.9" },
	} {
		f := newFakeSite(t)
		o, _, out := pluginSite(t, f)
		prepare(f)
		o.Activate = []string{"plugins/kunde"}
		if err := Run(o); !errors.Is(err, ErrAgentNoPlugins) {
			t.Errorf("%s: err = %v\n%s", name, err, out)
		}
		if got := strings.Join(f.routes, " "); got != "begin" || len(f.uploaded) != 0 {
			t.Errorf("%s: routes = %s", name, got)
		}
	}
	// Ein Satz nur aus --deactivate: ein alter Agent hält ihn für einen Begin ohne Einheiten.
	f := newFakeSite(t)
	o, _, _ := pluginSite(t, f)
	f.version, f.noPlugins = "0.8.0", true
	o.NoCode, o.Deactivate = true, []string{"plugins/alt"}
	if err := Run(o); !errors.Is(err, ErrAgentNoPlugins) {
		t.Errorf("deactivate alone: err = %v", err)
	}
}

// V10: mit --content fragt die CLI zuerst nach der Version – das Paket wird einem alten Agent gar nicht erst abgelegt.
func TestRunAsksForTheVersionBeforeStagingAPackage(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := contentSite(t, f)
	f.version, f.noPlugins = "0.8.0", true
	o.NoCode, o.Deactivate = true, []string{"plugins/alt"}
	if err := Run(o); !errors.Is(err, ErrAgentNoPlugins) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "ping" || len(f.staged) != 0 {
		t.Errorf("routes = %s, staged = %d", got, len(f.staged))
	}
	// Gegen 0.9.0 geht derselbe Satz durch: ping, Paket, Probelauf, Begin, Commit, Bestätigung.
	f = newFakeSite(t)
	o, _, out = contentSite(t, f)
	f.version, f.rescueDB = "0.9.0", &agentapi.RescueDBState{OK: true}
	o.NoCode, o.Deactivate = true, []string{"plugins/alt"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasPrefix(got, "ping stage") || !strings.HasSuffix(got, "begin begin commit confirm") {
		t.Errorf("routes = %s", got)
	}
}

// AC-199: lehnt der Agent den Plugin-Zustand im Probelauf ab, endet der Push dort – mit reason und den Einheiten.
func TestRunStopsOnARefusalOfThePluginState(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := pluginSite(t, f)
	refused := []agentapi.PluginRefusal{{Unit: "plugins/kunde", Why: "requires_php", Needs: "8.2", Has: "8.0"}}
	f.pluginsFail = &agentapi.PluginsFailure{Code: "plugins_requirements", Message: "Voraussetzungen nicht erfüllt: plugins/kunde", Plugins: refused}
	o.Activate = []string{"plugins/kunde"}

	err := Run(o)
	var pe *PluginsError
	if !errors.As(err, &pe) || pe.Reason != "plugins_requirements" || !reflect.DeepEqual(pe.Plugins, refused) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if !strings.Contains(err.Error(), "plugins/kunde (requires_php: verlangt \"8.2\", vorhanden \"8.0\")") {
		t.Errorf("message = %q", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
	if _, jerr := LoadJournal(siteDir, testID); jerr == nil {
		t.Error("a refused push leaves no journal")
	}

	// Ein Grund aus der Familie des Inhaltskanals (MyISAM) bleibt, was er ist.
	f.pluginsFail = &agentapi.PluginsFailure{Code: "engine_unsupported", Message: "Nicht InnoDB: options"}
	var ce *ContentError
	if err := Run(o); !errors.As(err, &ce) || ce.Reason != "engine_unsupported" {
		t.Errorf("err = %v", err)
	}
}

// Was nur der echte Begin ablehnen kann (Öffner, Probe des Umschlags), kommt als Fehler des Agents – mit reason und detail.
func TestRunMapsARefusalOfTheRealBegin(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	f.realBeginBody = `{"code":"wpsync_plugins_not_allowed","message":"Plugins schaltet ein Push nur mit Öffner","data":{"status":403,"plugins":[]}}`
	err := Run(o)
	var pe *PluginsError
	if !errors.As(err, &pe) || pe.Reason != "plugins_not_allowed" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin" {
		t.Errorf("routes = %s", got)
	}

	f.realBeginBody = `{"code":"wpsync_plugins_rescue_db","message":"ohne Umschlag","data":{"status":409,"plugins":[],"detail":"probe_failed"}}`
	if err := Run(o); !errors.As(err, &pe) || pe.Reason != "rescue_db_unavailable" || pe.Detail != "probe_failed" {
		t.Errorf("err = %v", err)
	}
}

// AC-181, A9: ohne Umschlag bricht die CLI schon nach dem Probelauf ab – auch ohne --require-rescue-db.
func TestRunNeedsTheRescueEnvelopeForAPluginState(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := pluginSite(t, f)
	f.rescueDB = &agentapi.RescueDBState{Reason: "no_image_key"}
	o.Deactivate = []string{"plugins/alt"}

	err := Run(o)
	var need *RescueDBError
	if !errors.As(err, &need) || need.Reason != "no_image_key" || !need.Mandatory {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if !strings.Contains(err.Error(), "Plugins schaltet") {
		t.Errorf("message = %q", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
}

// A22, AC-208: ohne --yes nennt die Rückfrage jedes Plugin, das abgeschaltet wird, beim Namen.
func TestRunAsksBeforeDeactivatingAndNamesThePlugins(t *testing.T) {
	f := newFakeSite(t)
	f.pluginNames = map[string][2]string{"plugins/borlabs-cookie": {"Borlabs Cookie", "3.2.1"}}
	f.inactive = map[string]bool{"plugins/schon-aus": true}
	o, _, _ := pluginSite(t, f)
	o.NoCode = true
	o.Deactivate = []string{"plugins/borlabs-cookie", "plugins/ohne-namen", "plugins/schon-aus"}
	o.Yes = false
	asked := ""
	o.Confirm = func(q string) bool {
		asked = q
		return false
	}
	if err := Run(o); !errors.Is(err, ErrAborted) {
		t.Fatalf("err = %v", err)
	}
	want := "Deaktiviert auf " + f.srv.URL + ": Borlabs Cookie 3.2.1, plugins/ohne-namen – das Plugin läuft danach nicht mehr. "
	if !strings.HasPrefix(asked, want) || !strings.HasSuffix(asked, "pushen?") || strings.Contains(asked, "schon-aus") {
		t.Errorf("question = %q", asked)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
	o.Confirm = nil // ohne Terminal und ohne --yes
	if err := Run(o); !errors.Is(err, ErrNeedsYes) {
		t.Errorf("err = %v", err)
	}
}

// A13, AC-188: die Seite im Admin-Kontext wird wie jede Health-Seite vorher und nachher geholt.
func TestRunChecksTheAdminPageOfThePlan(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	hits := 0
	for _, c := range f.cookies {
		if strings.HasPrefix(c, "/wp-admin/admin-ajax.php ") {
			hits++
		}
	}
	if hits != 2 {
		t.Errorf("admin-ajax.php requested %d times, want before and after: %v", hits, f.cookies)
	}
}

// Ein falscher Aufruf endet, bevor die Site gefragt wird.
func TestRunRefusesWrongSwitchesBeforeAnyRequest(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := pluginSite(t, f)
	for _, bad := range []Options{
		{Activate: []string{"themes/t"}}, {Deactivate: []string{"plugins/wpsync-agent"}},
		{Activate: []string{"plugins/fehlt"}}, {Activate: []string{"plugins/kunde"}, Deactivate: []string{"plugins/kunde"}},
	} {
		o.Activate, o.Deactivate = bad.Activate, bad.Deactivate
		if err := Run(o); !errors.Is(err, ErrPluginSwitch) {
			t.Errorf("%+v: err = %v", bad, err)
		}
	}
	if len(f.routes) != 0 {
		t.Errorf("routes = %v", f.routes)
	}
}

// Ergänzt beim Umsetzen (nicht im Plan): lehnt der echte Begin ohne Umschlag ab (plugins_rescue_db),
// verwirft der Agent den eben angelegten Push samt dem hineingezogenen Paket. Ein neuer Versuch legt
// das Paket neu ab – die CLI fragt vor jedem Probelauf, was auf der Site liegt.
func TestRunStagesThePackageAgainAfterARefusedRealBegin(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := contentSite(t, f)
	f.version, f.rescueDB = "0.9.0", &agentapi.RescueDBState{OK: true}
	o.NoCode, o.Deactivate = true, []string{"plugins/alt"}
	f.realBeginBody = `{"code":"wpsync_plugins_rescue_db","message":"ohne Umschlag","data":{"status":409,"plugins":[],"detail":"write_failed"}}`

	err := Run(o)
	var pe *PluginsError
	if !errors.As(err, &pe) || pe.Reason != "rescue_db_unavailable" || pe.Detail != "write_failed" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "ping stage begin begin" {
		t.Errorf("routes = %s", got)
	}
	f.staged = nil // PushContent::take zog das Paket in den Push-Ordner, der Agent hat ihn gelöscht
	f.realBeginBody, f.routes = "", nil
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "ping stage begin begin commit confirm" {
		t.Errorf("second try: routes = %s", got)
	}
}
