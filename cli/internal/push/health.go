package push

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Probe is what one page looked like from the outside.
type Probe struct {
	URL    string
	Status int  // 0: no answer
	Empty  bool // white page
	Marker bool // WordPress or PHP reported an error in the body
}

// A fatal often comes with HTTP 200 and WordPress' "critical error" page.
var markers = []string{"wp-die-message", "critical error", "kritischen Fehler", "Fatal error", "Parse error"}

// HealthURLs merges the pages the agent names with the ones from the site configuration. The
// site is not trusted: a page it names must lie on the paired site itself (same scheme and host
// as for rescue.php). Pages from the configuration are the user's choice, but only over http(s).
// dropped lists what is not requested, for a hint.
func HealthURLs(siteURL string, agent, site []string) (urls, dropped []string) {
	seen := map[string]bool{}
	add := func(u string, ok bool) {
		switch {
		case u == "" || seen[u]:
		case !ok:
			dropped = append(dropped, u)
		default:
			seen[u] = true
			urls = append(urls, u)
		}
	}
	for _, u := range agent {
		add(u, onSite(siteURL, u))
	}
	for _, u := range site {
		add(u, webURL(u))
	}
	return urls, dropped
}

func webURL(rawURL string) bool {
	u, err := url.Parse(rawURL)
	return err == nil && (u.Scheme == "http" || u.Scheme == "https") && u.Host != ""
}

// Check requests every URL once, with pause between two requests. A redirect is followed only on
// the host of the page itself; any other answers as the 3xx it is.
func Check(hc *http.Client, urls []string, pause func()) []Probe {
	sameHost := *hc
	sameHost.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if len(via) >= 10 {
			return errors.New("stopped after 10 redirects")
		}
		if !strings.EqualFold(req.URL.Host, via[0].URL.Host) {
			return http.ErrUseLastResponse
		}
		return nil
	}
	probes := make([]Probe, len(urls))
	for i, u := range urls {
		if i > 0 {
			pause()
		}
		probes[i] = probe(&sameHost, u)
	}
	return probes
}

func probe(hc *http.Client, rawURL string) Probe {
	p := Probe{URL: rawURL, Empty: true}
	nonce := make([]byte, 4)
	_, _ = rand.Read(nonce)
	sep := "?"
	if strings.Contains(rawURL, "?") {
		sep = "&"
	}
	// The parameter keeps page caches from answering with a copy from before the push.
	req, err := http.NewRequest(http.MethodGet, rawURL+sep+"wpsync_hc="+hex.EncodeToString(nonce), nil)
	if err != nil {
		return p
	}
	req.Header.Set("User-Agent", agentapi.UserAgent())
	req.Header.Set("Cache-Control", "no-cache")
	resp, err := hc.Do(req)
	if err != nil {
		return p
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	p.Status = resp.StatusCode
	p.Empty = len(bytes.TrimSpace(body)) == 0
	for _, m := range markers {
		if bytes.Contains(body, []byte(m)) {
			p.Marker = true
		}
	}
	return p
}

// Worse lists the pages that got worse; empty means the push did no visible harm. A page that
// was already broken before does not count (a site in maintenance mode stays pushable).
func Worse(before, after []Probe) []string {
	var out []string
	for i, a := range after {
		if i >= len(before) {
			break
		}
		b := before[i]
		switch {
		case a.Status == 0 && b.Status != 0:
			out = append(out, fmt.Sprintf("%s antwortet nicht mehr (vorher HTTP %d)", agentapi.Printable(a.URL), b.Status))
		case a.Status >= 500 && b.Status != 0 && b.Status < 500:
			out = append(out, fmt.Sprintf("%s liefert HTTP %d (vorher %d)", agentapi.Printable(a.URL), a.Status, b.Status))
		case a.Marker && !b.Marker:
			out = append(out, fmt.Sprintf("%s zeigt eine Fehlermeldung von WordPress oder PHP", agentapi.Printable(a.URL)))
		case a.Empty && !b.Empty && a.Status != 0:
			out = append(out, fmt.Sprintf("%s ist eine leere Seite", agentapi.Printable(a.URL)))
		}
	}
	return out
}
