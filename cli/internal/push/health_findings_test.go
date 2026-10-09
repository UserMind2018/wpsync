package push

import (
	"encoding/json"
	"errors"
	"reflect"
	"strings"
	"testing"
)

func TestProbeFinding(t *testing.T) {
	for _, c := range []struct {
		p    Probe
		want string
	}{
		{Probe{Status: 0, Empty: true}, "keine Antwort"},
		{Probe{Status: 200}, "HTTP 200"},
		{Probe{Status: 500, Marker: true}, "HTTP 500, Fehlermeldung von WordPress oder PHP"},
		{Probe{Status: 200, Empty: true}, "HTTP 200, leere Seite"},
	} {
		if got := c.p.Finding(); got != c.want {
			t.Errorf("Finding(%+v) = %q, want %q", c.p, got, c.want)
		}
	}
}

// S5: WorsePages nennt dieselben Seiten wie Worse, mit dem Befund vorher und nachher.
func TestWorsePagesMatchesWorse(t *testing.T) {
	before := []Probe{{URL: "home", Status: 200}, {URL: "shop", Status: 503}, {URL: "blog", Status: 200}}
	after := []Probe{{URL: "home", Status: 0, Empty: true}, {URL: "shop", Status: 500}, {URL: "blog", Status: 200, Empty: true}}
	got := WorsePages(before, after)
	want := []HealthFinding{
		{URL: "home", Before: "HTTP 200", After: "keine Antwort"},
		{URL: "blog", Before: "HTTP 200", After: "HTTP 200, leere Seite"},
	}
	if !reflect.DeepEqual(got, want) || len(Worse(before, after)) != len(got) {
		t.Fatalf("pages = %+v, worse = %v", got, Worse(before, after))
	}
	if WorsePages(before, before) != nil {
		t.Error("an unchanged site has no findings")
	}
}

// S5, Spec Content-Push §7.4: auch ein reiner Code-Push nennt nach der Rücknahme je Seite den Befund.
func TestRunReportsTheHealthOfEveryWorsePage(t *testing.T) {
	f := newFakeSite(t)
	f.broken = true
	o, _, _ := localSite(t, f)
	var report Result
	o.Report = &report

	var rolled *RolledBackError
	if err := Run(o); !errors.As(err, &rolled) {
		t.Fatalf("err = %v", err)
	}
	want := []HealthFinding{
		{URL: f.srv.URL + "/", Before: "HTTP 200", After: "HTTP 500, Fehlermeldung von WordPress oder PHP"},
		{URL: f.srv.URL + "/wp-login.php", Before: "HTTP 200", After: "HTTP 500, Fehlermeldung von WordPress oder PHP"},
	}
	if !reflect.DeepEqual(report.Health, want) {
		t.Fatalf("health = %+v", report.Health)
	}
	data, _ := json.Marshal(report)
	if !strings.Contains(string(data), `"health":[{"url":"`+f.srv.URL+`/","before":"HTTP 200","after":"HTTP 500, Fehlermeldung von WordPress oder PHP"}`) {
		t.Errorf("json = %s", data)
	}
	ok, _ := json.Marshal(Result{Status: "confirmed"})
	if strings.Contains(string(ok), "health") {
		t.Errorf("health must be left out when nothing got worse: %s", ok)
	}
}
