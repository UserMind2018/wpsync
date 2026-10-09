package agentapi

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
)

func strp(s string) *string { return &s }

// Spec Content-Push P4 §4.2: der Begin trägt activate, deactivate und plugin_heads (base64) – und nichts davon ohne Auftrag.
func TestPushBeginSendsThePluginWish(t *testing.T) {
	var bodies []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		raw, _ := io.ReadAll(r.Body)
		bodies = append(bodies, string(raw))
		w.Write([]byte(`{"push_id":"","agent_version":"0.9.0","window_open":true,"units":[],"rescue":{"url":"x"}}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	req := PushBeginRequest{Target: "live", Dry: true, Activate: []string{"plugins/kunde"}, Deactivate: []string{"plugins/alt"},
		PluginHeads: map[string]map[string][]byte{"plugins/kunde": {"kunde.php": []byte("<?php\n/* Plugin Name: Kunde */")}}}
	if _, err := c.PushBegin(req); err != nil {
		t.Fatal(err)
	}
	if _, err := c.PushBegin(PushBeginRequest{Target: "live", Dry: true}); err != nil {
		t.Fatal(err)
	}
	var sent map[string]any
	if err := json.Unmarshal([]byte(bodies[0]), &sent); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(sent["activate"], []any{"plugins/kunde"}) || !reflect.DeepEqual(sent["deactivate"], []any{"plugins/alt"}) {
		t.Errorf("sent = %s", bodies[0])
	}
	heads, _ := sent["plugin_heads"].(map[string]any)
	files, _ := heads["plugins/kunde"].(map[string]any)
	if files["kunde.php"] != "PD9waHAKLyogUGx1Z2luIE5hbWU6IEt1bmRlICov" {
		t.Errorf("plugin_heads = %v", sent["plugin_heads"])
	}
	for _, field := range []string{"activate", "deactivate", "plugin_heads"} {
		if strings.Contains(bodies[1], `"`+field+`"`) {
			t.Errorf("a begin without a wish names %s: %s", field, bodies[1])
		}
	}
}

// §4.2: der Plan des Agents – und alles daraus gesäubert: Einheiten und Einträge nur in ihrer Form,
// Namen ohne Steuerzeichen, Codes nur als Codes, Listen begrenzt.
func TestPushBeginReadsAndCleansThePluginsPlan(t *testing.T) {
	answer := ""
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(answer)) }))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	begin := func(plugins string) *PluginsPlan {
		t.Helper()
		answer = `{"push_id":"","agent_version":"0.9.0","window_open":true,"units":[],"rescue":{"url":"x"}` + plugins + `}`
		res, err := c.PushBegin(PushBeginRequest{Target: "live", Dry: true})
		if err != nil {
			t.Fatal(err)
		}
		return res.Plugins
	}
	if got := begin(""); got != nil {
		t.Fatalf("an agent before 0.9.0 names no plugins: %+v", got)
	}
	got := begin(`,"plugins":{"ok":true,"error":null,
		"activate":[{"unit":"plugins/kunde","state":"new","file":"kunde/kunde.php","name":"Kunde Größe","version":"1.2.0","requirements":{"checked":"head","ok":true,"failed":[]}},
			{"unit":"plugins/wp-rocket","state":"skipped","why":"disabled_on_staging","file":null,"name":null,"version":null,"requirements":{"checked":"at_commit","ok":true,"failed":[]}}],
		"deactivate":[{"unit":"plugins/alt","state":"active","files":["alt/alt.php"],"name":"Alt","version":"3.2.1","required_by":[],"hooks":true}],
		"warnings":["deactivation_review"],"health_urls":["https://kunde.de/wp-admin/admin-ajax.php"]}`)
	want := &PluginsPlan{OK: true,
		Activate: []PluginActivate{
			{Unit: "plugins/kunde", State: "new", File: strp("kunde/kunde.php"), Name: strp("Kunde Größe"), Version: strp("1.2.0"), Requirements: PluginRequirements{Checked: "head", OK: true, Failed: []PluginRequirement{}}},
			{Unit: "plugins/wp-rocket", State: "skipped", Why: "disabled_on_staging", Requirements: PluginRequirements{Checked: "at_commit", OK: true, Failed: []PluginRequirement{}}},
		},
		Deactivate: []PluginDeactivate{{Unit: "plugins/alt", State: "active", Files: []string{"alt/alt.php"}, Name: strp("Alt"), Version: strp("3.2.1"), RequiredBy: []string{}, Hooks: true}},
		Warnings:   []string{"deactivation_review"}, HealthURLs: []string{"https://kunde.de/wp-admin/admin-ajax.php"}}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("plan = %+v\nwant   %+v", got, want)
	}

	hostile := begin(`,"plugins":{"ok":false,
		"error":{"code":"\u001b[2J","message":"nein \u001b[31m","plugins":[{"unit":"plugins/kunde","why":"requires_php","needs":"8.2\u0007","has":"8.0"},{"unit":"../../etc","why":"x"},{"unit":"plugins/ok","why":"<b>"}]},
		"activate":[{"unit":"themes/x","state":"new"},{"unit":"plugins/kunde","state":"\u001b","why":"<script>","file":"../wp-config.php","name":"Böse\u001b[31m","version":"1‮0","requirements":{"checked":"HEAD","ok":false,"failed":[{"why":"requires_php","needs":"9","has":"8"},{"why":"\u001b","needs":"x","has":"y"}]}}],
		"deactivate":[{"unit":"plugins/alt","state":"active","files":["alt/alt.php","../x.php","hello.php"],"name":null,"version":null,"required_by":["plugins/addon","addon","../x"],"hooks":false}],
		"warnings":["deactivation_review","<b>","requirements_unchecked"],"health_urls":["a","b","c","d","e"]}`)
	if hostile.Error == nil || hostile.Error.Code != "plugins_failed" || strings.Contains(hostile.Error.Message, "\x1b") {
		t.Errorf("error = %+v", hostile.Error)
	}
	if !reflect.DeepEqual(hostile.Error.Plugins, []PluginRefusal{{Unit: "plugins/kunde", Why: "requires_php", Needs: "8.2�", Has: "8.0"}, {Unit: "plugins/ok"}}) {
		t.Errorf("error.plugins = %+v", hostile.Error.Plugins)
	}
	if len(hostile.Activate) != 1 {
		t.Fatalf("activate = %+v", hostile.Activate)
	}
	a := hostile.Activate[0]
	if a.Unit != "plugins/kunde" || a.State != "" || a.Why != "" || a.File != nil || *a.Name != "Böse�[31m" || *a.Version != "1�0" || a.Requirements.Checked != "" ||
		!reflect.DeepEqual(a.Requirements.Failed, []PluginRequirement{{Why: "requires_php", Needs: "9", Has: "8"}}) {
		t.Errorf("activate[0] = %+v (name %q, version %q)", a, *a.Name, *a.Version)
	}
	d := hostile.Deactivate[0]
	if !reflect.DeepEqual(d.Files, []string{"alt/alt.php"}) || !reflect.DeepEqual(d.RequiredBy, []string{"plugins/addon"}) {
		t.Errorf("deactivate[0] = %+v", d)
	}
	if !reflect.DeepEqual(hostile.Warnings, []string{"deactivation_review", "requirements_unchecked"}) || len(hostile.HealthURLs) != 3 {
		t.Errorf("warnings = %v, health = %v", hostile.Warnings, hostile.HealthURLs)
	}
}

// §4.3: der Commit nennt, was er an der Liste geändert hat.
func TestPushCommitFullReadsThePlugins(t *testing.T) {
	answer := `{"next":null,"stamps":{},"content":{"rows":0,"after":[],"post_actions":[{"step":"plugins_cache","ok":true}],"seconds":0.01},
		"plugins":{"activated":[{"unit":"plugins/kunde","file":"kunde/kunde.php"},{"unit":"themes/x","file":"x/x.php"}],
			"deactivated":[{"unit":"plugins/alt","files":["alt/alt.php","../x.php"]}],"unchanged":["plugins/da","x"],"skipped":[{"unit":"plugins/wp-rocket","why":"disabled_on_staging"},{"unit":"plugins/y","why":"<b>"}]}}`
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(answer)) }))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	res, err := c.PushCommitFull("p_20261009_0123456789ab")
	if err != nil {
		t.Fatal(err)
	}
	want := &PluginsApplied{
		Activated:   []PluginActivated{{Unit: "plugins/kunde", File: "kunde/kunde.php"}},
		Deactivated: []PluginDeactivated{{Unit: "plugins/alt", Files: []string{"alt/alt.php"}}},
		Unchanged:   []string{"plugins/da"},
		Skipped:     []PluginSkipped{{Unit: "plugins/wp-rocket", Why: "disabled_on_staging"}, {Unit: "plugins/y"}},
	}
	if !reflect.DeepEqual(res.Plugins, want) {
		t.Errorf("plugins = %+v", res.Plugins)
	}
	answer = `{"next":null,"stamps":{}}`
	if res, _ := c.PushCommitFull("p_20261009_0123456789ab"); res == nil || res.Plugins != nil {
		t.Errorf("a commit without a plugin state names none: %+v", res)
	}
}

// §4.4: Rücknahme über den Agent und über rescue.php – plugins bzw. plugins_not_restored, gesäubert.
func TestRollbackNotesCarryThePlugins(t *testing.T) {
	var n RollbackNotes
	body := `{"ok":true,"status":"rolled_back","plugins":{"deactivated":["kunde/kunde.php","../x.php"],"reactivated":["alt/alt.php"]},
		"plugins_not_restored":{"added":["kunde/kunde.php","<b>"],"removed":[]},"warnings":["plugins_not_restored","content_not_rolled_back"]}`
	if err := json.Unmarshal([]byte(body), &n); err != nil {
		t.Fatal(err)
	}
	n = n.Clean()
	if !reflect.DeepEqual(n.Plugins, &RollbackPlugins{Deactivated: []string{"kunde/kunde.php"}, Reactivated: []string{"alt/alt.php"}, DeactivatedTotal: 2}) { // S6: was wegfällt, zählt
		t.Errorf("plugins = %+v", n.Plugins)
	}
	if !reflect.DeepEqual(n.PluginsNotRestored, &PluginsKept{Added: []string{"kunde/kunde.php"}, Removed: []string{}, AddedTotal: 2}) {
		t.Errorf("plugins_not_restored = %+v", n.PluginsNotRestored)
	}
	if !reflect.DeepEqual(n.Warnings, []string{"plugins_not_restored", "content_not_rolled_back"}) {
		t.Errorf("warnings = %v", n.Warnings)
	}
	var plain RollbackNotes
	json.Unmarshal([]byte(`{"ok":true,"status":"rolled_back"}`), &plain)
	if plain = plain.Clean(); plain.Plugins != nil || plain.PluginsNotRestored != nil {
		t.Errorf("without a plugin state: %+v", plain)
	}
}

// A16: eine Ablehnung des Plugin-Zustands trägt data.plugins und – ohne Umschlag – data.detail.
func TestAPIErrorCarriesPluginsAndDetail(t *testing.T) {
	answer, status := "", 409
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(status)
		w.Write([]byte(answer))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	fail := func() *APIError {
		t.Helper()
		_, err := c.PushBegin(PushBeginRequest{Target: "live"})
		var apiErr *APIError
		if !errors.As(err, &apiErr) {
			t.Fatalf("err = %v", err)
		}
		return apiErr
	}
	answer = `{"code":"wpsync_plugins_requirements","message":"Voraussetzungen nicht erfüllt","data":{"status":409,"plugins":[{"unit":"plugins/kunde","why":"requires_php","needs":"8.2","has":"8.0"},{"unit":"../x","why":"y"}]}}`
	if e := fail(); !reflect.DeepEqual(e.Plugins, []PluginRefusal{{Unit: "plugins/kunde", Why: "requires_php", Needs: "8.2", Has: "8.0"}}) || e.Detail != "" {
		t.Errorf("err = %+v", e)
	}
	answer = `{"code":"wpsync_plugins_rescue_db","message":"ohne Umschlag","data":{"status":409,"plugins":[],"detail":"no_image_key"}}`
	if e := fail(); e.Detail != "no_image_key" || len(e.Plugins) != 0 {
		t.Errorf("err = %+v", e)
	}
	answer = `{"code":"wpsync_plugins_rescue_db","message":"x","data":{"detail":"\u001b[2J"}}`
	if e := fail(); e.Detail != "unknown" {
		t.Errorf("detail = %q", e.Detail)
	}
	// Nur für Codes der Familie: ein anderer Fehler trägt nichts davon.
	answer = `{"code":"wpsync_push_state","message":"x","data":{"plugins":[{"unit":"plugins/kunde","why":"y"}],"detail":"z"}}`
	if e := fail(); e.Plugins != nil || e.Detail != "" {
		t.Errorf("err = %+v", e)
	}
}

// V12: das Protokoll nennt an der Einheit plugins, was der Push geschaltet hat und was zurückging.
func TestPushListReadsThePluginUnit(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"pushes":[{"push_id":"p_20261009_0123456789ab","device":"mac","target":"live","status":"rolled_back","units":[
			{"path":"plugins","activated":["kunde/kunde.php","../x.php"],"deactivated":["alt/alt.php"],"back":{"deactivated":["kunde/kunde.php"],"reactivated":["alt/alt.php","<b>"]},"via":"rescue"}],"created":1}]}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	list, err := c.PushList()
	if err != nil || len(list) != 1 || len(list[0].Units) != 1 {
		t.Fatalf("list = %+v, err = %v", list, err)
	}
	u := list[0].Units[0]
	if !reflect.DeepEqual(u.Activated, []string{"kunde/kunde.php"}) || !reflect.DeepEqual(u.Deactivated, []string{"alt/alt.php"}) ||
		!reflect.DeepEqual(u.Back, &RollbackPlugins{Deactivated: []string{"kunde/kunde.php"}, Reactivated: []string{"alt/alt.php"}, ReactivatedTotal: 2}) || u.Via != "rescue" {
		t.Errorf("unit = %+v", u)
	}
}

// Security-Review P4 S6: ein Eintrag, den der Agent schaltet, fällt in der CLI nicht still weg – auch mit
// Klammern oder Umlaut nicht; gezählt wird, was eine Liste nicht zeigt; „unknown“ bleibt erhalten.
func TestEntriesWithUnusualCharactersAreKeptAndCounted(t *testing.T) {
	var n RollbackNotes
	body := `{"ok":true,"status":"rolled_back",
		"plugins":{"deactivated":["plg-x/mein(plugin).php","plg-y/größe+1.php","../x.php","hello.php","a/b\u0001.php"],"reactivated":["alt/alt.php"],"reactivated_total":140},
		"plugins_not_restored":{"added":[],"removed":[],"unknown":true},"warnings":["content_not_rolled_back","plugins_not_restored"]}`
	if err := json.Unmarshal([]byte(body), &n); err != nil {
		t.Fatal(err)
	}
	n = n.Clean()
	want := &RollbackPlugins{Deactivated: []string{"plg-x/mein(plugin).php", "plg-y/größe+1.php"}, Reactivated: []string{"alt/alt.php"}, DeactivatedTotal: 5, ReactivatedTotal: 140}
	if !reflect.DeepEqual(n.Plugins, want) {
		t.Errorf("plugins = %+v", n.Plugins)
	}
	if !reflect.DeepEqual(n.PluginsNotRestored, &PluginsKept{Added: []string{}, Removed: []string{}, Unknown: true}) {
		t.Errorf("plugins_not_restored = %+v", n.PluginsNotRestored)
	}
	raw, _ := json.Marshal(n.Plugins)
	if string(raw) != `{"deactivated":["plg-x/mein(plugin).php","plg-y/größe+1.php"],"reactivated":["alt/alt.php"],"deactivated_total":5,"reactivated_total":140}` {
		t.Errorf("json = %s", raw)
	}
	// Ohne Abweichung kein Zähler; ein unsinniger Zähler der Site zählt nicht.
	var plain RollbackNotes
	json.Unmarshal([]byte(`{"plugins":{"deactivated":["a/a.php"],"reactivated":[],"deactivated_total":-3}}`), &plain)
	if plain = plain.Clean(); plain.Plugins.DeactivatedTotal != 0 || plain.Plugins.ReactivatedTotal != 0 {
		t.Errorf("plugins = %+v", plain.Plugins)
	}
	if PluginEntry("plg-x/mein(plugin).php") || !PluginEntry("a/sub dir/b.php") {
		t.Error("PluginEntry is the narrow form that is shown unquoted")
	}
}

// Nach-Review NR-6: ein Eintrag mit Zeichen, die eine Anzeige umsteuern oder unsichtbar sind (C1, Bidi,
// Zero-Width), geht nicht roh in --json – er wird nicht gezeigt, aber gezählt.
func TestEntriesThatSteerADisplayAreCountedNotPassed(t *testing.T) {
	bad := []string{"a/x‮gnp.php", "a/x\u0085y.php", "a/x​y.php", "a/x⁦y.php", "a/x" + string(rune(0xfeff)) + "y.php", "a/x‏y.php", "a/x y.php"}
	raw, _ := json.Marshal(map[string]any{"plugins": map[string]any{"deactivated": append([]string{"gut/größe(1).php"}, bad...), "reactivated": []string{}}})
	var n RollbackNotes
	if err := json.Unmarshal(raw, &n); err != nil {
		t.Fatal(err)
	}
	n = n.Clean()
	if !reflect.DeepEqual(n.Plugins.Deactivated, []string{"gut/größe(1).php"}) || n.Plugins.DeactivatedTotal != 1+len(bad) {
		t.Errorf("plugins = %+v", n.Plugins)
	}
	out, _ := json.Marshal(n.Plugins)
	for _, r := range string(out) {
		if Unsafe(r) || invisible(r) {
			t.Errorf("json carries %U: %s", r, out)
		}
	}
}
