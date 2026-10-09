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
	// Eine Meldung, einmal gesagt: was es ist, je Einheit warum – ohne den Sammeltext des Agents daneben.
	if err.Error() != `Plugin-Zustand abgelehnt: plugins/kunde braucht PHP "8.2", das Ziel hat "8.0" (plugins_requirements)` {
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

// pluginPushed pushes a set that activates plugins/kunde and deactivates plugins/alt, and confirms it.
func pluginPushed(t *testing.T) (*fakeSite, Options, string, *bytes.Buffer) {
	t.Helper()
	f := newFakeSite(t)
	o, siteDir, out := pluginSite(t, f)
	o.Activate, o.Deactivate = []string{"plugins/kunde"}, []string{"plugins/alt"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	f.routes = nil
	out.Reset()
	return f, o, siteDir, out
}

// AC-176, AC-194, AC-208, §4.5: das Ergebnis nennt, was geschaltet wurde, und die Routinen, die nicht liefen.
func TestRunReportsThePluginsItSwitched(t *testing.T) {
	f := newFakeSite(t)
	f.deactHooks = map[string]bool{"plugins/alt": true}
	f.inactive = map[string]bool{"plugins/schon-aus": true}
	o, _, out := pluginSite(t, f)
	o.Activate, o.Deactivate = []string{"plugins/kunde"}, []string{"plugins/alt", "plugins/schon-aus"}
	var report Result
	o.Report = &report

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit confirm") {
		t.Errorf("routes = %s", got)
	}
	want := &PluginsReport{Activated: []string{"plugins/kunde"}, Deactivated: []string{"plugins/alt"}, Unchanged: []string{"plugins/schon-aus"}, Skipped: []string{}}
	if report.Status != "confirmed" || !reflect.DeepEqual(report.Plugins, want) || report.Content != nil {
		t.Errorf("report = %+v, plugins = %+v", report, report.Plugins)
	}
	for _, w := range []string{WarningDeactivationReview, WarningActivationHooksSkipped, WarningDeactivationHooksSkipped} {
		if !slices.Contains(report.Warnings, w) {
			t.Errorf("warnings = %v, want %s", report.Warnings, w)
		}
	}
	if len(report.PostActions) != 2 || report.PostActions[0].Step != "plugins_cache" || report.PostActions[1].Step != "plugins_effective" {
		t.Errorf("post actions = %+v", report.PostActions)
	}
	for _, want := range []string{"aktiviert: plugins/kunde (kunde/kunde.php)", "deaktiviert: plugins/alt (alt/alt.php)", "Aktivierungsroutine", "im WP-Admin einmal deaktivieren und aktivieren"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output misses %q:\n%s", want, out)
		}
	}
	if strings.Contains(out.String(), "Inhalte: 0 Zeilen") {
		t.Errorf("a push without a package names no rows:\n%s", out)
	}
}

// A14: was das Ziel überspringt (Staging), steht als skipped im Ergebnis – ohne Hinweis auf eine Routine.
func TestRunReportsSkippedPlugins(t *testing.T) {
	f := newFakeSite(t)
	f.skipped = map[string]string{"plugins/kunde": "disabled_on_staging"}
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	var report Result
	o.Report = &report
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !reflect.DeepEqual(report.Plugins.Skipped, []string{"plugins/kunde"}) || len(report.Plugins.Activated) != 0 || slices.Contains(report.Warnings, WarningActivationHooksSkipped) {
		t.Errorf("report = %+v, plugins = %+v", report, report.Plugins)
	}
	if !strings.Contains(out.String(), "nicht aktiviert: plugins/kunde (disabled_on_staging)") {
		t.Errorf("output:\n%s", out)
	}
}

// AC-195 – nie ein halber Satz: schaltet der Agent nicht oder nennt er andere Einheiten, wird nicht bestätigt, sondern zurückgenommen.
func TestRunNeverConfirmsWithoutThePluginState(t *testing.T) {
	for name, prepare := range map[string]func(f *fakeSite){
		"no plugins in the answer": func(f *fakeSite) { f.noSwitch = true },
		"another unit": func(f *fakeSite) {
			f.tamperPlugins = func(a *agentapi.PluginsApplied) { a.Activated[0].Unit = "plugins/fremd" }
		},
		"one missing": func(f *fakeSite) {
			f.tamperPlugins = func(a *agentapi.PluginsApplied) { a.Deactivated = []agentapi.PluginDeactivated{} }
		},
		"one twice": func(f *fakeSite) {
			f.tamperPlugins = func(a *agentapi.PluginsApplied) { a.Unchanged = append(a.Unchanged, "plugins/kunde") }
		},
	} {
		f := newFakeSite(t)
		o, _, out := pluginSite(t, f)
		prepare(f)
		o.Activate, o.Deactivate = []string{"plugins/kunde"}, []string{"plugins/alt"}
		var report Result
		o.Report = &report

		err := Run(o)
		var rolled *RolledBackError
		if !errors.As(err, &rolled) || rolled.Via != "agent" || len(rolled.Reasons) != 1 || !strings.Contains(rolled.Reasons[0], "Plugin") {
			t.Fatalf("%s: err = %v\n%s", name, err, out)
		}
		if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback") {
			t.Errorf("%s: routes = %s", name, got)
		}
		if report.Status != "rolled_back" || report.Plugins != nil {
			t.Errorf("%s: report = %+v", name, report)
		}
	}
}

// AC-188, AC-195: bricht nur die Seite im Admin-Kontext, nimmt die CLI den Satz über den Agent zurück – und nennt, was die Liste wieder verliert.
func TestRunTakesThePluginStateBackThroughTheAgent(t *testing.T) {
	f := newFakeSite(t)
	f.adminBroken = true
	f.rbBody = `{"ok":true,"plugins":{"deactivated":["kunde/kunde.php"],"reactivated":["alt/alt.php"]},"post_actions":[{"step":"plugins_cache","ok":true}]}`
	o, _, out := pluginSite(t, f)
	o.Activate, o.Deactivate = []string{"plugins/kunde"}, []string{"plugins/alt"}
	var report Result
	o.Report = &report

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || rolled.Via != "agent" || rolled.Content != "rolled_back" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	back := &agentapi.RollbackPlugins{Deactivated: []string{"kunde/kunde.php"}, Reactivated: []string{"alt/alt.php"}}
	if !reflect.DeepEqual(rolled.Plugins, back) || !reflect.DeepEqual(report.PluginsBack, back) || report.Plugins != nil || report.PluginsNotRestored != nil {
		t.Errorf("rolled = %+v, report = %+v", rolled, report)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback") || strings.Contains(got, "rescue") {
		t.Errorf("routes = %s", got)
	}
	for _, want := range []string{"wieder deaktiviert: kunde/kunde.php", "wieder aktiviert: alt/alt.php"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output misses %q:\n%s", want, out)
		}
	}
}

// AC-186, AC-195: antwortet WordPress nicht mehr, geht der ganze Satz über rescue.php zurück – mit content=1, auch ohne Paket.
func TestRunTakesThePluginStateBackThroughRescue(t *testing.T) {
	f := newFakeSite(t)
	f.broken, f.rollback = true, 500
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"none"},"plugins":{"deactivated":["kunde/kunde.php"],"reactivated":[]}}`
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	var report Result
	o.Report = &report

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || rolled.Via != "rescue" || rolled.Content != "rolled_back" || len(rolled.Warnings) != 0 {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback rescue list") {
		t.Errorf("routes = %s", got)
	}
	if len(f.rescueForms) != 1 || f.rescueForms[0].Get("content") != "1" {
		t.Errorf("forms = %v", f.rescueForms)
	}
	if report.Plugins != nil || report.PluginsBack == nil || !reflect.DeepEqual(report.PluginsBack.Deactivated, []string{"kunde/kunde.php"}) {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "über rescue.php zurückgenommen, Plugin-Zustand eingeschlossen") {
		t.Errorf("output:\n%s", out)
	}
}

// AC-190, A18: lässt rescue.php den DB-Anteil stehen, sagt das Ergebnis plugins_not_restored – mit den Einträgen, die noch gelten.
func TestRunNamesThePluginStateRescueLeft(t *testing.T) {
	for name, body := range map[string]string{
		"kept":            `{"ok":true,"status":"rolled_back","content":{"state":"kept","error":{"code":"changed_since_push"}},"plugins_not_restored":{"added":["kunde/kunde.php"],"removed":[]},"warnings":["content_not_rolled_back","plugins_not_restored"]}`,
		"an older rescue": `{"ok":true,"status":"rolled_back"}`,
	} {
		f := newFakeSite(t)
		f.broken, f.rollback = true, 500
		f.rescueBody = body
		o, _, out := pluginSite(t, f)
		o.Activate = []string{"plugins/kunde"}
		var report Result
		o.Report = &report

		err := Run(o)
		var rolled *RolledBackError
		if !errors.As(err, &rolled) || rolled.Content != "kept" {
			t.Fatalf("%s: err = %v\n%s", name, err, out)
		}
		if !reflect.DeepEqual(report.Warnings[len(report.Warnings)-2:], []string{WarningContentNotRolledBack, WarningPluginsNotRestored}) {
			t.Errorf("%s: warnings = %v", name, report.Warnings)
		}
		if report.Plugins == nil || report.PluginsBack != nil {
			t.Errorf("%s: the plugin state still stands: %+v", name, report)
		}
		if name == "kept" && (report.PluginsNotRestored == nil || !reflect.DeepEqual(report.PluginsNotRestored.Added, []string{"kunde/kunde.php"}) || !strings.Contains(out.String(), "noch aktiv: kunde/kunde.php")) {
			t.Errorf("report = %+v\n%s", report, out)
		}
		if strings.Contains(strings.Join(f.routes, " "), "list") {
			t.Errorf("%s: nothing to catch up on while the database part stands: %v", name, f.routes)
		}
	}
}

// wpsync rollback: derselbe Weg – über den Agent, sonst über rescue.php mit content=1.
func TestRollbackOfAPluginState(t *testing.T) {
	f, o, _, out := pluginPushed(t)
	f.rbBody = `{"ok":true,"plugins":{"deactivated":["kunde/kunde.php"],"reactivated":["alt/alt.php"]}}`
	var report Result
	o.Report = &report
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback" {
		t.Errorf("routes = %s", got)
	}
	if report.Status != "rolled_back" || report.Via != "agent" || report.PluginsBack == nil || !reflect.DeepEqual(report.PluginsBack.Reactivated, []string{"alt/alt.php"}) {
		t.Errorf("report = %+v", report)
	}
	// V13: der Plugin-Zustand ist keine Einheit des Ergebnisses.
	if !reflect.DeepEqual(report.Units, []string{"plugins/kunde", "plugins/x"}) {
		t.Errorf("units = %v", report.Units)
	}
	if !strings.Contains(out.String(), "wieder aktiviert: alt/alt.php") {
		t.Errorf("output:\n%s", out)
	}

	f, o, _, out = pluginPushed(t)
	f.rollback = 500
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"kept","error":{"code":"db_unreachable"}},"plugins_not_restored":{"added":["kunde/kunde.php"],"removed":["alt/alt.php"]},"warnings":["content_not_rolled_back","plugins_not_restored"]}`
	o.Report = &report
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "rollback rescue" || f.rescueForms[0].Get("content") != "1" {
		t.Errorf("routes = %s, forms = %v", got, f.rescueForms)
	}
	if !reflect.DeepEqual(report.Warnings, []string{WarningContentNotRolledBack, WarningPluginsNotRestored}) || report.PluginsNotRestored == nil {
		t.Errorf("report = %+v", report)
	}
	for _, want := range []string{"Plugin-Zustand", "noch aktiv: kunde/kunde.php", "noch inaktiv: alt/alt.php", "db_unreachable"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output misses %q:\n%s", want, out)
		}
	}
}

// --confirm nach rescue.php: der Plugin-Zustand bleibt – und kein Hinweis auf einen Inhaltsstand, den es nicht gibt.
func TestConfirmPendingAfterRescueKeepsThePluginState(t *testing.T) {
	f, o, _, out := pluginPushed(t)
	f.confirmBody = `{"ok":true,"status":"rolled_back","warnings":["content_kept"]}`
	var report Result
	o.Report = &report
	if err := ConfirmPending(o, testID); err != nil {
		t.Fatal(err)
	}
	if report.Status != "rolled_back" || !reflect.DeepEqual(report.Warnings, []string{WarningContentKept}) {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "Plugin-Zustand") || strings.Contains(out.String(), "--content") {
		t.Errorf("output:\n%s", out)
	}
}

// V12: das Protokoll zeigt die Einheit plugins mit dem, was geschaltet wurde.
func TestPushesNamesThePluginState(t *testing.T) {
	f := newFakeSite(t)
	f.list = `{"pushes":[{"push_id":"` + testID + `","device":"mac","target":"live","status":"rolled_back","created":1791158400,
"units":[{"path":"plugins/kunde","files":2,"uploaded":2},{"path":"plugins","activated":["kunde/kunde.php"],"deactivated":["alt/alt.php"],
"back":{"deactivated":["kunde/kunde.php"],"reactivated":[]},"via":"rescue"}]}]}`
	o, _, out := localSite(t, f)
	if err := Pushes(o); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.String(), "plugins (aktiviert: kunde/kunde.php; deaktiviert: alt/alt.php; über rescue.php zurückgenommen)") {
		t.Errorf("output:\n%s", out)
	}
}

// Ergänzt beim Umsetzen (nicht im Plan): ein Satz nur aus --deactivate – der Agent antwortet mit
// units: [] und stamps: {} – läuft ohne Sonderfall bis zur Bestätigung; das Ergebnis hat keine Einheit.
func TestRunOfOnlyADeactivationRunsToItsEnd(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := pluginSite(t, f)
	o.NoCode = true
	o.Deactivate = []string{"plugins/alt"}
	var report Result
	o.Report = &report
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin commit confirm" {
		t.Errorf("routes = %s", got)
	}
	want := &PluginsReport{Activated: []string{}, Deactivated: []string{"plugins/alt"}, Unchanged: []string{}, Skipped: []string{}}
	if report.Status != "confirmed" || len(report.Units) != 0 || report.Units == nil || !reflect.DeepEqual(report.Plugins, want) || report.Content != nil {
		t.Errorf("report = %+v, plugins = %+v", report, report.Plugins)
	}
	raw, _ := json.Marshal(report)
	for _, part := range []string{`"units":[]`, `"plugins":{"activated":[],"deactivated":["plugins/alt"],"unchanged":[],"skipped":[]}`} {
		if !strings.Contains(string(raw), part) {
			t.Errorf("json misses %s: %s", part, raw)
		}
	}
	if j, err := LoadJournal(siteDir, testID); err != nil || !j.Applied || len(j.Units) != 0 {
		t.Errorf("journal = %+v, err = %v", j, err)
	}
	if !strings.Contains(out.String(), "ist live") {
		t.Errorf("output:\n%s", out)
	}
}

// Ergänzt: derselbe Satz ohne Paket nimmt bei der Rücknahme den Weg eines Satzes mit Paket – zuerst
// der Agent; antwortet er nicht, rescue.php mit content=1.
func TestRunOfOnlyADeactivationGoesBackLikeASetWithContent(t *testing.T) {
	f := newFakeSite(t)
	f.adminBroken = true
	f.rbBody = `{"ok":true,"plugins":{"deactivated":[],"reactivated":["alt/alt.php"]}}`
	o, _, out := pluginSite(t, f)
	o.NoCode, o.Deactivate = true, []string{"plugins/alt"}
	var report Result
	o.Report = &report
	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || rolled.Via != "agent" || rolled.Content != "rolled_back" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin commit rollback" {
		t.Errorf("routes = %s", got)
	}
	if report.PluginsBack == nil || !reflect.DeepEqual(report.PluginsBack.Reactivated, []string{"alt/alt.php"}) || report.Plugins != nil {
		t.Errorf("report = %+v", report)
	}

	f = newFakeSite(t)
	f.adminBroken, f.rollback = true, 500
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"none"},"plugins":{"deactivated":[],"reactivated":["alt/alt.php"]}}`
	o, _, out = pluginSite(t, f)
	o.NoCode, o.Deactivate = true, []string{"plugins/alt"}
	o.Report = &report
	err = Run(o)
	if !errors.As(err, &rolled) || rolled.Via != "rescue" || rolled.Content != "rolled_back" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin commit rollback rescue list" {
		t.Errorf("routes = %s", got)
	}
	if len(f.rescueForms) != 1 || f.rescueForms[0].Get("content") != "1" {
		t.Errorf("forms = %v", f.rescueForms)
	}
}

// Ergänzt: antwortet der Agent und lehnt die Rücknahme ab (die Liste ist nicht lesbar, eine Zeile
// geändert), geht nichts an ihm vorbei über rescue.php – der Satz bleibt ganz.
func TestRunNeverGoesAroundARefusingAgentForAPluginState(t *testing.T) {
	f := newFakeSite(t)
	f.adminBroken, f.rollback, f.rbCode = true, 409, "wpsync_content_changed_since_push"
	o, _, out := pluginSite(t, f)
	o.NoCode, o.Deactivate = true, []string{"plugins/alt"}
	var report Result
	o.Report = &report
	err := Run(o)
	var rolled *RolledBackError
	if err == nil || errors.As(err, &rolled) || !strings.Contains(err.Error(), "ROLLBACK NICHT MÖGLICH") {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin commit rollback" {
		t.Errorf("routes = %s", got)
	}
	if report.Status != "committed" || report.Plugins == nil {
		t.Errorf("the push still stands: %+v", report)
	}
}

// Ergänzt: die Wörter am Draht von --json (Spec P4 §4.5, V18) – Warnungen und die Felder des Ergebnisses.
func TestPluginWordsOfTheJSONResult(t *testing.T) {
	words := []string{WarningDeactivationReview, WarningRequirementsUnchecked, WarningActivationHooksSkipped, WarningDeactivationHooksSkipped, WarningPluginsNotRestored}
	if !reflect.DeepEqual(words, []string{"deactivation_review", "requirements_unchecked", "activation_hooks_skipped", "deactivation_hooks_skipped", "plugins_not_restored"}) {
		t.Errorf("warnings = %v", words)
	}
	raw, _ := json.Marshal(Result{Units: []string{}, Status: "rolled_back", Via: "rescue", Warnings: []string{WarningContentNotRolledBack, WarningPluginsNotRestored},
		PluginsBack:        &agentapi.RollbackPlugins{Deactivated: []string{"kunde/kunde.php"}, Reactivated: []string{}},
		PluginsNotRestored: &agentapi.PluginsKept{Added: []string{"kunde/kunde.php"}, Removed: []string{"alt/alt.php"}}})
	for _, part := range []string{
		`"warnings":["content_not_rolled_back","plugins_not_restored"]`,
		`"plugins_back":{"deactivated":["kunde/kunde.php"],"reactivated":[]}`,
		`"plugins_not_restored":{"added":["kunde/kunde.php"],"removed":["alt/alt.php"]}`,
	} {
		if !strings.Contains(string(raw), part) {
			t.Errorf("json misses %s: %s", part, raw)
		}
	}
	if raw, _ := json.Marshal(Result{Units: []string{}}); strings.Contains(string(raw), "plugins") {
		t.Errorf("a result without a plugin state names none: %s", raw)
	}
}

// Im E2E gefunden (Lauf 4): der Plan nannte eine neue Einheit „(neu, bleibt auf der Site inaktiv)“,
// obwohl derselbe Push sie aktiviert. Für eine Einheit aus --activate sagt die Zeile nur noch „(neu)“ –
// was mit ihr geschieht, steht in der Zeile „aktivieren:“ darunter.
func TestRunPlanDoesNotCallAnActivatedUnitInactive(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := pluginSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "plugins/nurcode/nurcode.php", "<?php\n/* Plugin Name: Nur Code */\n", 1800000000)
	o.DryRun = true
	o.Units = []string{"plugins/nurcode"}
	o.Activate = []string{"plugins/kunde"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	for _, want := range []string{"plugins/kunde – 1 von 1 Dateien zu übertragen (neu)\n", "plugins/nurcode – 1 von 1 Dateien zu übertragen (neu, bleibt auf der Site inaktiv)\n"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output misses %q:\n%s", want, out)
		}
	}
}

// Im E2E gefunden (Lauf 4): der Hinweis „Nach dem Test nach Live“ nach einem Push nach Staging nannte
// die Schalter nicht – wer ihn kopierte, pushte den Code ohne den Plugin-Zustand.
func TestRunToStagingNamesTheSwitchesForLive(t *testing.T) {
	f := newFakeSite(t)
	f.skipped = map[string]string{"plugins/wp-rocket": "disabled_on_staging"}
	o, siteDir, out := pluginSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "plugins/wp-rocket/wp-rocket.php", "<?php\n/* Plugin Name: WP Rocket */\n", 1800000000)
	o.Target = TargetStaging
	o.Units = []string{"plugins/x"}
	o.Activate, o.Deactivate = []string{"plugins/kunde", "plugins/wp-rocket"}, []string{"plugins/alt"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	want := "Nach dem Test nach Live: wpsync push kunde code plugins/kunde plugins/wp-rocket plugins/x --activate plugins/kunde,plugins/wp-rocket --deactivate plugins/alt\n"
	if !strings.Contains(out.String(), want) {
		t.Errorf("output misses %q:\n%s", want, out)
	}
}

// Security-Review P4 S1: lehnt der Agent die Rücknahme eines bestätigten Pushs ab, weil der Öffner des
// Fensters keine Plugins schalten darf, ist das plugins_not_allowed (Exit 1) – nie der Umweg über rescue.php.
func TestRollbackRefusedForWantOfTheRightToSwitchPlugins(t *testing.T) {
	f, o, _, out := pluginPushed(t)
	f.rollback, f.rbCode = 403, "wpsync_plugins_not_allowed"
	err := Rollback(o, testID)
	var pe *PluginsError
	if !errors.As(err, &pe) || pe.Reason != "plugins_not_allowed" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "rollback" {
		t.Errorf("routes = %s", got)
	}
}

// Security-Review P4 S4: die Liste wird erst wirksam, wenn der Object-Cache sie hergibt. Meldet der Agent
// eine der Nacharbeiten dazu als gescheitert, hätte der Health-Check womöglich den alten Stand geprüft –
// ein Satz mit Plugin-Zustand wird dann nicht bestätigt, sondern zurückgenommen (Exit 43).
func TestRunNeverConfirmsAPluginStateWhoseCacheStepFailed(t *testing.T) {
	for _, step := range []string{"object_cache", "plugins_cache", "plugins_effective"} {
		f := newFakeSite(t)
		f.actions = []agentapi.PostAction{{Step: "object_cache", OK: step != "object_cache"}, {Step: "plugins_cache", OK: step != "plugins_cache"},
			{Step: "plugins_effective", OK: step != "plugins_effective"}, {Step: "rewrite_rules", OK: true}}
		o, _, out := pluginSite(t, f)
		o.Activate = []string{"plugins/kunde"}
		var report Result
		o.Report = &report
		err := Run(o)
		var rolled *RolledBackError
		if !errors.As(err, &rolled) || rolled.Via != "agent" || len(rolled.Reasons) != 1 || !strings.Contains(rolled.Reasons[0], step) {
			t.Fatalf("%s: err = %v\n%s", step, err, out)
		}
		if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback") {
			t.Errorf("%s: routes = %s", step, got)
		}
		if report.Status != "rolled_back" || report.Plugins != nil {
			t.Errorf("%s: report = %+v", step, report)
		}
	}
	// Ein anderer gescheiterter Schritt (Rewrite-Regeln, ein Cache-Plugin) bleibt, was er war: eine Zeile, kein Abbruch.
	f := newFakeSite(t)
	f.actions = []agentapi.PostAction{{Step: "object_cache", OK: true}, {Step: "plugins_effective", OK: true}, {Step: "rewrite_rules", OK: false}}
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
}

// S4: ohne Plugin-Zustand ändert sich nichts – ein gescheiterter Schritt object_cache nach einem Inhalts-Push ist wie bisher keine Rücknahme.
func TestRunConfirmsAContentPushWhoseCacheStepFailed(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := contentSite(t, f)
	f.actions = []agentapi.PostAction{{Step: "object_cache", OK: false}}
	o.NoCode = true
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit confirm") {
		t.Errorf("routes = %s", got)
	}
}

// Security-Review P4 S6: ein Eintrag mit ungewöhnlichen Zeichen steht in Ausgabe und Ergebnis – in der
// Ausgabe gequotet –, und wo der Agent die Einträge nicht kennt, sagt die CLI das, statt nichts zu nennen.
func TestRollbackNamesOddEntriesQuotedAndUnknownOnes(t *testing.T) {
	f, o, _, out := pluginPushed(t)
	f.rbBody = `{"ok":true,"plugins":{"deactivated":["plg-x/mein(plugin).php"],"reactivated":["alt/alt.php"],"reactivated_total":3}}`
	var report Result
	o.Report = &report
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if report.PluginsBack == nil || !reflect.DeepEqual(report.PluginsBack.Deactivated, []string{"plg-x/mein(plugin).php"}) || report.PluginsBack.ReactivatedTotal != 3 {
		t.Errorf("report = %+v", report.PluginsBack)
	}
	for _, want := range []string{`wieder deaktiviert: "plg-x/mein(plugin).php"`, "wieder aktiviert: alt/alt.php (und 2 weitere)"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output misses %q:\n%s", want, out)
		}
	}

	f, o, _, out = pluginPushed(t)
	f.rollback = 500
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"kept","error":{"code":"rescue_db_unavailable"}},"plugins_not_restored":{"added":[],"removed":[],"unknown":true},"warnings":["content_not_rolled_back","plugins_not_restored"]}`
	o.Report = &report
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if report.PluginsNotRestored == nil || !report.PluginsNotRestored.Unknown || !slices.Contains(report.Warnings, WarningPluginsNotRestored) {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "welche Einträge der Push geändert hat, ist auf der Site nicht vermerkt") {
		t.Errorf("output:\n%s", out)
	}
}

// Security-Review P4 H3: fällt die Seite im Admin-Kontext aus dem Health-Check (der Agent nennt sie unter
// einem anderen Origin – http/https, www), steht das als Warnung im Ergebnis, nicht nur als Textzeile.
func TestRunWarnsWhenTheAdminPageIsNotChecked(t *testing.T) {
	f := newFakeSite(t)
	f.adminURL = "https://www.anderswo.example/wp-admin/admin-ajax.php"
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	var report Result
	o.Report = &report
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if report.Status != "confirmed" || !slices.Contains(report.Warnings, WarningAdminCheckSkipped) {
		t.Errorf("report = %+v", report)
	}
	if !strings.Contains(out.String(), "Plugins im Admin-Kontext prüft dieser Push nicht") {
		t.Errorf("output:\n%s", out)
	}
	// Mit der Seite der eigenen Site: keine Warnung.
	f = newFakeSite(t)
	o, _, _ = pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	o.Report = &report
	if err := Run(o); err != nil || slices.Contains(report.Warnings, WarningAdminCheckSkipped) {
		t.Errorf("err = %v, warnings = %v", err, report.Warnings)
	}
	if WarningAdminCheckSkipped != "admin_check_skipped" {
		t.Errorf("warning = %s", WarningAdminCheckSkipped)
	}
}

// Nach einer Rücknahme über rescue.php trägt das Ergebnis keine post_actions: die des Commits gelten nicht
// mehr, und die der Rücknahme holt der Agent erst nach, wenn WordPress wieder lädt. Über den Agent bleiben
// es die Nacharbeiten der Rücknahme.
func TestRunDropsThePostActionsOfTheCommitAfterARescueRollback(t *testing.T) {
	f := newFakeSite(t)
	f.broken, f.rollback = true, 500
	f.rescueBody = `{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"none"},"plugins":{"deactivated":["kunde/kunde.php"],"reactivated":[]}}`
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	var report Result
	o.Report = &report
	var rolled *RolledBackError
	if err := Run(o); !errors.As(err, &rolled) || rolled.Via != "rescue" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if report.PostActions != nil {
		t.Errorf("post actions of the commit in the result of a rollback: %+v", report.PostActions)
	}
	if raw, _ := json.Marshal(report); strings.Contains(string(raw), "post_actions") {
		t.Errorf("json = %s", raw)
	}

	f = newFakeSite(t)
	f.adminBroken = true
	f.rbBody = `{"ok":true,"plugins":{"deactivated":["kunde/kunde.php"],"reactivated":[]},"post_actions":[{"step":"rewrite_rules","ok":true}]}`
	o, _, out = pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	o.Report = &report
	if err := Run(o); !errors.As(err, &rolled) || rolled.Via != "agent" {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if len(report.PostActions) != 1 || report.PostActions[0].Step != "rewrite_rules" {
		t.Errorf("post actions = %+v", report.PostActions)
	}
}

// Die Meldung einer Ablehnung nennt je Einheit den Grund in Worten – einmal, ohne den Sammeltext des Agents
// und ohne denselben Hinweis ein zweites Mal dahinter (im E2E als doppelt aufgefallen).
func TestPluginsErrorSaysEachReasonOnce(t *testing.T) {
	e := &PluginsError{Reason: "plugins_requirements", Message: "Voraussetzungen nicht erfüllt: plugins/alt – PHP- oder WordPress-Version, ein fehlendes Plugin oder ein aktives Plugin, das dieses voraussetzt.",
		Plugins: []agentapi.PluginRefusal{
			{Unit: "plugins/alt", Why: "required_by", Needs: "plugins/addon"},
			{Unit: "plugins/neu", Why: "requires_wp", Needs: "7.0", Has: "6.5.2"},
			{Unit: "plugins/neu", Why: "requires_plugins", Needs: "woo-commerce"},
		}}
	want := `Plugin-Zustand abgelehnt: plugins/alt wird von "plugins/addon" vorausgesetzt; plugins/neu braucht WordPress "7.0", das Ziel hat "6.5.2"; plugins/neu setzt "woo-commerce" voraus, das auf dem Ziel nicht aktiv ist (plugins_requirements)`
	if e.Error() != want {
		t.Errorf("message = %s", e.Error())
	}
	for why, text := range map[string]string{
		"no_plugin_file": "plugins/x hat keine PHP-Datei mit „Plugin Name:“ direkt im Ordner",
		"ambiguous":      "plugins/x hat mehrere PHP-Dateien mit „Plugin Name:“ direkt im Ordner",
		"unit_missing":   "plugins/x liegt nicht im Satz dieses Pushs",
		"file_name":      "plugins/x: der Name der Hauptdatei ist nicht zulässig",
		"neuer_grund":    "plugins/x (neuer_grund)",
	} {
		got := (&PluginsError{Reason: "plugins_invalid", Plugins: []agentapi.PluginRefusal{{Unit: "plugins/x", Why: why}}}).Error()
		if got != "Plugin-Zustand abgelehnt: "+text+" (plugins_invalid)" {
			t.Errorf("%s: %s", why, got)
		}
	}
	// Ohne Einheiten spricht der Agent.
	if got := (&PluginsError{Reason: "plugins_unsupported", Message: "Auf einer Multisite schaltet wpsync keine Plugins."}).Error(); got != "Auf einer Multisite schaltet wpsync keine Plugins. (plugins_unsupported)" {
		t.Errorf("message = %s", got)
	}
}

// Nach-Review NR-3: ein fehlender Schritt plugins_effective ist nicht „bestanden“. Hat der Push auf Live
// wirklich etwas geschaltet, verlangt die CLI den Schritt mit ok: true – sonst Rücknahme (Exit 43).
func TestRunNeedsTheReadBackOfTheListOnLive(t *testing.T) {
	for name, actions := range map[string][]agentapi.PostAction{
		"no such step":     {{Step: "object_cache", OK: true}, {Step: "plugins_cache", OK: true}},
		"no steps at all":  {},
		"the step, failed": {{Step: "object_cache", OK: true}, {Step: "plugins_effective", OK: false}},
	} {
		f := newFakeSite(t)
		f.actions = actions
		o, _, out := pluginSite(t, f)
		o.Activate = []string{"plugins/kunde"}
		var rolled *RolledBackError
		if err := Run(o); !errors.As(err, &rolled) || len(rolled.Reasons) != 1 || !strings.Contains(rolled.Reasons[0], "plugins_effective") {
			t.Fatalf("%s: err = %v\n%s", name, err, out)
		}
		if got := strings.Join(f.routes, " "); !strings.HasSuffix(got, "commit rollback") {
			t.Errorf("%s: routes = %s", name, got)
		}
	}
	// Hat der Satz an der Liste nichts geändert, gibt es nichts zurückzulesen.
	f := newFakeSite(t)
	f.inactive = map[string]bool{"plugins/schon-aus": true}
	f.actions = []agentapi.PostAction{{Step: "object_cache", OK: true}}
	o, _, out := pluginSite(t, f)
	o.NoCode, o.Deactivate = true, []string{"plugins/schon-aus"}
	if err := Run(o); err != nil {
		t.Fatalf("unchanged: %v\n%s", err, out)
	}
	// In der Kopie gibt es keinen Object-Cache und keinen Schritt: dort wird nichts verlangt.
	f = newFakeSite(t)
	f.actions = []agentapi.PostAction{{Step: "rewrite_rules", OK: true}}
	o, _, out = pluginSite(t, f)
	o.Target = TargetStaging
	o.Activate = []string{"plugins/kunde"}
	if err := Run(o); err != nil {
		t.Fatalf("staging: %v\n%s", err, out)
	}
}

// Nach-Review NR-8: nennt der Agent gar keine Seite im Admin-Kontext, fehlt der Check ebenfalls – mit Warnung.
func TestRunWarnsWhenTheAgentNamesNoAdminPage(t *testing.T) {
	f := newFakeSite(t)
	f.adminURL = "-"
	o, _, out := pluginSite(t, f)
	o.Activate = []string{"plugins/kunde"}
	var report Result
	o.Report = &report
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !slices.Contains(report.Warnings, WarningAdminCheckSkipped) || !strings.Contains(out.String(), "Plugins im Admin-Kontext prüft dieser Push nicht") {
		t.Errorf("warnings = %v\n%s", report.Warnings, out)
	}
}
