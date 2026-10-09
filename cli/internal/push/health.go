package push

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
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

// stagingDirRe is the folder of a staging copy as the agent draws it (StagingGuard::DIR_RE).
var stagingDirRe = regexp.MustCompile(`^wpsync-staging-[a-f0-9]{12}$`)

// copyAccess is the way of the health check into the staging copy (Spec 2b 6.2, V20): the access
// cookie and the only place it may travel to.
type copyAccess struct {
	site   string       // paired site
	base   string       // staging URL without a slash at the end
	path   string       // its path
	cookie *http.Cookie // wpsync_stg, name and value only
}

// newCopyAccess accepts a staging URL only on the paired site and only in a folder named like a
// staging copy – with any other base the cookie would travel to pages of live.
func newCopyAccess(siteURL, base string, cookie *http.Cookie) (*copyAccess, error) {
	base = strings.TrimRight(base, "/")
	u, err := url.Parse(base)
	if err != nil || !agentapi.SameOrigin(siteURL, base) || u.RawQuery != "" || u.Fragment != "" ||
		!stagingDirRe.MatchString(u.Path[strings.LastIndex(u.Path, "/")+1:]) || hasDots(u.Path) {
		return nil, fmt.Errorf("%w (Staging-Kopie)", agentapi.ErrForeignURL)
	}
	if cookie == nil || cookie.Name == "" || cookie.Value == "" {
		return nil, errors.New("kein Zugangs-Cookie für die Staging-Kopie")
	}
	return &copyAccess{site: siteURL, base: base, path: u.Path, cookie: &http.Cookie{Name: cookie.Name, Value: cookie.Value}}, nil
}

// inside: rawURL lies on the paired site below the staging folder.
func (a *copyAccess) inside(rawURL string) bool {
	if !agentapi.SameOrigin(a.site, rawURL) {
		return false
	}
	u, err := url.Parse(rawURL)
	return err == nil && strings.HasPrefix(u.Path, a.path+"/") && !hasDots(u.Path)
}

// hasDots: the decoded path climbs or stands still somewhere; a server would resolve that.
func hasDots(path string) bool {
	for _, seg := range strings.Split(path, "/") {
		if seg == "." || seg == ".." {
			return true
		}
	}
	return false
}

// Check requests every URL once, with pause between two requests. A redirect is followed only on
// the host of the page itself; any other answers as the 3xx it is.
func Check(hc *http.Client, urls []string, pause func()) []Probe {
	return check(context.Background(), hc, urls, pause, nil)
}

// check is Check; with acc it checks a staging copy: every request carries the access cookie and
// nothing else, uses no cookie jar (the copy also starts an admin session – V20), and neither a
// page nor a redirect outside the copy is requested, so the cookie never reaches live or another
// host. A request still running when ctx ends counts as no answer.
func check(ctx context.Context, hc *http.Client, urls []string, pause func(), acc *copyAccess) []Probe {
	client := *hc
	client.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if len(via) >= 10 {
			return errors.New("stopped after 10 redirects")
		}
		if !strings.EqualFold(req.URL.Host, via[0].URL.Host) {
			return http.ErrUseLastResponse
		}
		if acc != nil && !acc.inside(req.URL.String()) {
			return http.ErrUseLastResponse
		}
		return nil
	}
	if acc != nil {
		client.Jar = nil
	}
	probes := make([]Probe, len(urls))
	for i, u := range urls {
		if i > 0 {
			pause()
		}
		if acc != nil && !acc.inside(u) {
			probes[i] = Probe{URL: u, Empty: true} // never requested
			continue
		}
		probes[i] = probe(ctx, &client, u, acc)
	}
	return probes
}

func probe(ctx context.Context, hc *http.Client, rawURL string, acc *copyAccess) Probe {
	p := Probe{URL: rawURL, Empty: true}
	nonce := make([]byte, 4)
	_, _ = rand.Read(nonce)
	sep := "?"
	if strings.Contains(rawURL, "?") {
		sep = "&"
	}
	// The parameter keeps page caches from answering with a copy from before the push.
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL+sep+"wpsync_hc="+hex.EncodeToString(nonce), nil)
	if err != nil {
		return p
	}
	req.Header.Set("User-Agent", agentapi.UserAgent())
	req.Header.Set("Cache-Control", "no-cache")
	if acc != nil {
		req.AddCookie(acc.cookie)
	}
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
		if why := worsened(before[i], a); why != "" {
			out = append(out, why)
		}
	}
	return out
}

// worsened says why page a is worse than b, "" if it is not – the one rule of Worse and WorsePages.
func worsened(b, a Probe) string {
	switch {
	case a.Status == 0 && b.Status != 0:
		return fmt.Sprintf("%s antwortet nicht mehr (vorher HTTP %d)", agentapi.Printable(a.URL), b.Status)
	case a.Status >= 500 && b.Status != 0 && b.Status < 500:
		return fmt.Sprintf("%s liefert HTTP %d (vorher %d)", agentapi.Printable(a.URL), a.Status, b.Status)
	case a.Marker && !b.Marker:
		return fmt.Sprintf("%s zeigt eine Fehlermeldung von WordPress oder PHP", agentapi.Printable(a.URL))
	case a.Empty && !b.Empty && a.Status != 0:
		return fmt.Sprintf("%s ist eine leere Seite", agentapi.Printable(a.URL))
	}
	return ""
}

// HealthFinding is one page that got worse after the swap, for the result (Spec Content-Push
// §7.4, S5). URL is the page as requested; Before and After are Probe.Finding.
type HealthFinding struct {
	URL    string `json:"url"`
	Before string `json:"before"`
	After  string `json:"after"`
}

// Finding is the short description of a probe: "HTTP 200", "HTTP 500, Fehlermeldung von
// WordPress oder PHP", "HTTP 200, leere Seite" or "keine Antwort".
func (p Probe) Finding() string {
	if p.Status == 0 {
		return "keine Antwort"
	}
	s := fmt.Sprintf("HTTP %d", p.Status)
	if p.Marker {
		s += ", Fehlermeldung von WordPress oder PHP"
	}
	if p.Empty {
		s += ", leere Seite"
	}
	return s
}

// WorsePages lists the pages that got worse with their state before and after; nil if none.
func WorsePages(before, after []Probe) []HealthFinding {
	var out []HealthFinding
	for i, a := range after {
		if i >= len(before) {
			break
		}
		if worsened(before[i], a) != "" {
			out = append(out, HealthFinding{URL: a.URL, Before: before[i].Finding(), After: a.Finding()})
		}
	}
	return out
}
