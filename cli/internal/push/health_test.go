package push

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestCheckRecordsStatusBodyAndMarkers(t *testing.T) {
	var agents, queries []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		agents = append(agents, r.Header.Get("User-Agent"))
		queries = append(queries, r.URL.RawQuery)
		switch r.URL.Path {
		case "/ok":
			w.Write([]byte("<html>Hallo</html>"))
		case "/fatal":
			w.Write([]byte(`<div class="wp-die-message"><p>Es gab einen kritischen Fehler auf deiner Website.</p></div>`))
		case "/white":
			w.Write([]byte("  \n"))
		case "/down":
			w.WriteHeader(http.StatusServiceUnavailable)
			w.Write([]byte("Wartung"))
		case "/moved":
			http.Redirect(w, r, "/ok", http.StatusFound)
		}
	}))
	defer srv.Close()

	pauses := 0
	probes := Check(srv.Client(), []string{srv.URL + "/ok", srv.URL + "/fatal?x=1", srv.URL + "/white", srv.URL + "/down", srv.URL + "/moved", "http://127.0.0.1:1/"}, func() { pauses++ })

	want := []Probe{
		{Status: 200}, {Status: 200, Marker: true}, {Status: 200, Empty: true}, {Status: 503}, {Status: 200}, {Status: 0, Empty: true},
	}
	for i, w := range want {
		got := probes[i]
		if got.Status != w.Status || got.Marker != w.Marker || got.Empty != w.Empty {
			t.Errorf("probe %d (%s) = %+v, want %+v", i, got.URL, got, w)
		}
	}
	if pauses != 5 {
		t.Errorf("pauses = %d, want one between each pair of requests", pauses)
	}
	if !strings.HasPrefix(agents[0], "wpsync/") {
		t.Errorf("User-Agent = %q", agents[0])
	}
	if !strings.Contains(queries[0], "wpsync_hc=") || !strings.HasPrefix(queries[1], "x=1&wpsync_hc=") {
		t.Errorf("cache buster missing: %v", queries[:2])
	}
}

// P7, AC-67: nur eine Verschlechterung zählt.
func TestWorse(t *testing.T) {
	before := []Probe{
		{URL: "home", Status: 200},
		{URL: "login", Status: 200},
		{URL: "shop", Status: 503},
		{URL: "blog", Status: 200, Marker: true},
		{URL: "feed", Status: 404},
		{URL: "gone", Status: 0, Empty: true},
	}
	same := []Probe{
		{URL: "home", Status: 200}, {URL: "login", Status: 302}, {URL: "shop", Status: 503},
		{URL: "blog", Status: 200, Marker: true}, {URL: "feed", Status: 404}, {URL: "gone", Status: 0, Empty: true},
	}
	if got := Worse(before, same); len(got) != 0 {
		t.Errorf("unchanged site reported as worse: %v", got)
	}

	after := []Probe{
		{URL: "home", Status: 500},
		{URL: "login", Status: 200, Marker: true},
		{URL: "shop", Status: 200},
		{URL: "blog", Status: 200, Empty: true},
		{URL: "feed", Status: 0, Empty: true},
		{URL: "gone", Status: 200},
	}
	got := Worse(before, after)
	if len(got) != 4 {
		t.Fatalf("worse = %v", got)
	}
	for i, part := range []string{"home", "login", "blog", "feed"} {
		if !strings.Contains(got[i], part) {
			t.Errorf("worse[%d] = %q, want it to name %s", i, got[i], part)
		}
	}
}

func TestHealthURLsMergesAndDeduplicates(t *testing.T) {
	got, dropped := HealthURLs("https://kunde.de", []string{"https://kunde.de/", "https://kunde.de/wp-login.php"}, []string{"https://kunde.de/kasse/", "https://kunde.de/", ""})
	want := []string{"https://kunde.de/", "https://kunde.de/wp-login.php", "https://kunde.de/kasse/"}
	if len(got) != len(want) || len(dropped) != 0 {
		t.Fatalf("urls = %v, dropped = %v", got, dropped)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("urls[%d] = %q, want %q", i, got[i], want[i])
		}
	}
}

// Die Site ist nicht vertrauenswürdig: Seiten, die der Agent nennt, liegen auf der gekoppelten
// Site selbst – sonst spräche der Mac fremde Hosts an.
func TestHealthURLsDropsAgentURLsOutsideTheSite(t *testing.T) {
	agent := []string{
		"https://kunde.de/",
		"http://kunde.de/",               // downgrade
		"https://evil.example/",          // other host
		"https://kunde.de.evil.example/", // suffix trick
		"https://kunde.de:8443/",         // other port
		"http://169.254.169.254/latest/", // metadata service
		"file:///etc/passwd",             // other scheme
		"/relative",                      // no host
		"https://KUNDE.de/wp-login.php",  // host case does not matter
	}
	got, dropped := HealthURLs("https://kunde.de", agent, nil)
	if strings.Join(got, " ") != "https://kunde.de/ https://KUNDE.de/wp-login.php" {
		t.Errorf("urls = %v", got)
	}
	if len(dropped) != 7 {
		t.Errorf("dropped = %v", dropped)
	}

	// A source paired over http (local DDEV) keeps its http pages.
	got, dropped = HealthURLs("http://src.ddev.site", []string{"http://src.ddev.site/", "https://src.ddev.site/"}, nil)
	if strings.Join(got, " ") != "http://src.ddev.site/" || len(dropped) != 1 {
		t.Errorf("http site: urls = %v, dropped = %v", got, dropped)
	}
}

// Seiten aus der eigenen Site-Konfiguration darf der User bewusst auch woanders hin zeigen
// lassen, aber nur über http(s).
func TestHealthURLsChecksTheSchemeOfConfiguredURLs(t *testing.T) {
	got, dropped := HealthURLs("https://kunde.de", nil, []string{"https://cdn.kunde.de/status", "http://kunde.de/alt", "file:///etc/passwd", "gopher://kunde.de/", "kunde.de/ohne-schema"})
	if strings.Join(got, " ") != "https://cdn.kunde.de/status http://kunde.de/alt" {
		t.Errorf("urls = %v", got)
	}
	if len(dropped) != 3 {
		t.Errorf("dropped = %v", dropped)
	}
}

// Eine Weiterleitung auf einen anderen Host wird nicht verfolgt; die Seite zählt als 3xx.
func TestCheckFollowsRedirectsOnlyOnTheSameHost(t *testing.T) {
	visited := false
	elsewhere := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		visited = true
		w.Write([]byte("<html>fremd</html>"))
	}))
	defer elsewhere.Close()
	site := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/away":
			http.Redirect(w, r, elsewhere.URL+"/", http.StatusFound)
		case "/here":
			http.Redirect(w, r, "/ok", http.StatusFound)
		default:
			w.Write([]byte("<html>ok</html>"))
		}
	}))
	defer site.Close()

	hc := site.Client()
	probes := Check(hc, []string{site.URL + "/away", site.URL + "/here"}, func() {})
	if probes[0].Status != http.StatusFound {
		t.Errorf("redirect to another host: %+v, want the 302 itself", probes[0])
	}
	if probes[1].Status != http.StatusOK || probes[1].Empty {
		t.Errorf("redirect on the same host: %+v, want it followed", probes[1])
	}
	if visited {
		t.Error("the health check followed a redirect to another host")
	}
	if hc.CheckRedirect != nil {
		t.Error("the caller's client must stay unchanged")
	}
}

func TestWorseQuotesTheURL(t *testing.T) {
	got := Worse([]Probe{{URL: "https://x.example/\x1b[2J", Status: 200}}, []Probe{{URL: "https://x.example/\x1b[2J", Status: 500}})
	if len(got) != 1 || strings.Contains(got[0], "\x1b") || !strings.Contains(got[0], `"https://x.example/\x1b[2J"`) {
		t.Errorf("worse = %q", got)
	}
}
