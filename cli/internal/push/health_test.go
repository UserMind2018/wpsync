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
	got := HealthURLs([]string{"https://kunde.de/", "https://kunde.de/wp-login.php"}, []string{"https://kunde.de/kasse/", "https://kunde.de/", ""})
	want := []string{"https://kunde.de/", "https://kunde.de/wp-login.php", "https://kunde.de/kasse/"}
	if len(got) != len(want) {
		t.Fatalf("urls = %v", got)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("urls[%d] = %q, want %q", i, got[i], want[i])
		}
	}
}
