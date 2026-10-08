package push

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"path"
	"regexp"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

var (
	// ErrRescueUnreachable: without a way back there is no push (AC-66).
	ErrRescueUnreachable = errors.New("das Notfall-Skript rescue.php ist nicht erreichbar")
	// ErrRescueConfirmed: rescue.php takes back only a push that was swapped in but not yet
	// confirmed; a confirmed one goes through the agent or the WP admin (U18).
	ErrRescueConfirmed = errors.New("rescue.php rollt nur unbestätigte Pushes zurück – dieser ist bestätigt; im WP-Admin unter Werkzeuge → wpsync zurückrollen, ohne WordPress per FTP")
	// ErrRescueGone: the stub in the webroot lives only while a push is open (Spec Stufe 2, 12, R8).
	ErrRescueGone = errors.New("der Notfallweg über rescue.php besteht nur bis kurz nach der Bestätigung eines Pushs und ist nicht mehr da – im WP-Admin unter Werkzeuge → wpsync zurückrollen, ohne WordPress per FTP")
)

// stubName matches the rescue stub the agent puts into the webroot.
var stubName = regexp.MustCompile(`^wpsync-rescue-[a-f0-9]{32}\.php$`)

// RescueKey derives the per-push rollback key from the pairing secret. The agent stores only its
// sha256, so rescue.php needs neither the database nor the secret.
func RescueKey(secret, pushID, salt string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("rescue:" + pushID + ":" + salt))
	return hex.EncodeToString(mac.Sum(nil))
}

// RescueAllowed accepts a rescue URL only on the paired site itself; the key must not travel
// in plain text unless the site itself was paired over http (local test sources).
func RescueAllowed(siteURL, rescueURL string) error {
	if !onSite(siteURL, rescueURL) {
		return fmt.Errorf("der Agent nennt eine Rescue-URL ausserhalb der gekoppelten Site: %s", agentapi.Printable(rescueURL))
	}
	return nil
}

// onSite: rawURL has the scheme and host (with port) of the paired site. http passes only when
// the site itself was paired over http.
func onSite(siteURL, rawURL string) bool { return agentapi.SameOrigin(siteURL, rawURL) }

// RescuePing checks that rescue.php answers without WordPress.
func RescuePing(hc *http.Client, rescueURL string) error {
	if _, err := rescuePost(hc, rescueURL, url.Values{"action": {"ping"}}); err != nil {
		return fmt.Errorf("%w: %v", ErrRescueUnreachable, err)
	}
	return nil
}

// RescueBlockedError: rescue.php did not answer and the agent names active plugins that can block
// PHP below wp-content (Spec Stufe 2, 12, R7). It still is ErrRescueUnreachable.
type RescueBlockedError struct {
	Plugins []string // folder names as the agent reports them
	Err     error
}

func (e *RescueBlockedError) Error() string {
	names := make([]string, len(e.Plugins))
	for i, p := range e.Plugins {
		names[i] = agentapi.Printable(p)
	}
	return fmt.Sprintf("%v (aktiv: %s)", e.Err, strings.Join(names, ", "))
}

func (e *RescueBlockedError) Unwrap() error { return e.Err }

// HardeningHint says where an active plugin blocks PHP below wp-content.
func HardeningHint(plugins []string) string {
	var out []string
	for _, p := range plugins {
		switch p {
		case "better-wp-security", "ithemes-security-pro":
			out = append(out, "Solid Security/iThemes Security: Advanced → System Tweaks → „Disable PHP in Plugins“")
		case "sucuri-scanner":
			out = append(out, "Sucuri Security: Settings → Hardening (PHP-Ausführung in wp-content)")
		default:
			out = append(out, agentapi.Printable(p))
		}
	}
	return strings.Join(out, "; ")
}

// rescueReady checks the way back before anything is created or swapped (AC-66).
func rescueReady(o Options, r agentapi.PushRescue) error {
	if err := RescueAllowed(o.Site.URL, r.URL); err != nil {
		return err
	}
	if err := RescuePing(o.HTTP, r.URL); err != nil {
		if len(r.Hardening) > 0 {
			return &RescueBlockedError{Plugins: r.Hardening, Err: err}
		}
		return err
	}
	return nil
}

// RescueRollback restores the snapshot of a push, bypassing WordPress.
func RescueRollback(hc *http.Client, rescueURL, pushID, key string) error {
	_, err := RescueRollbackNotes(hc, rescueURL, pushID, key)
	return err
}

// RescueRollbackNotes is RescueRollback and returns what rescue.php reports beyond the status
// (agent 0.6.0: uploads left in place because they changed since the push).
func RescueRollbackNotes(hc *http.Client, rescueURL, pushID, key string) (agentapi.RollbackNotes, error) {
	body, err := rescuePost(hc, rescueURL, url.Values{"action": {"rollback"}, "push_id": {pushID}, "key": {key}})
	if err != nil {
		return agentapi.RollbackNotes{}, err
	}
	raw, _ := json.Marshal(body)
	var notes agentapi.RollbackNotes
	_ = json.Unmarshal(raw, &notes) // a field of another type stays empty
	return notes.Clean(), nil
}

func rescuePost(hc *http.Client, rescueURL string, form url.Values) (map[string]any, error) {
	req, err := http.NewRequest(http.MethodPost, rescueURL, strings.NewReader(form.Encode()))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("User-Agent", agentapi.UserAgent())
	// The key travels in the body; a 307/308 would resend it to any host. Never follow.
	noRedirect := *hc
	noRedirect.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	resp, err := noRedirect.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 4000))
	var body map[string]any
	if json.Unmarshal(raw, &body) != nil {
		if resp.StatusCode == http.StatusNotFound && stubName.MatchString(path.Base(req.URL.Path)) {
			return nil, ErrRescueGone
		}
		return nil, fmt.Errorf("HTTP %d, keine Antwort von rescue.php", resp.StatusCode)
	}
	if ok, _ := body["ok"].(bool); resp.StatusCode != http.StatusOK || !ok {
		if body["error"] == "confirmed" {
			return nil, ErrRescueConfirmed
		}
		if by, _ := body["by"].(string); body["error"] == "superseded" && pushIDRe.MatchString(by) {
			return nil, fmt.Errorf("HTTP %d: superseded – zuerst den späteren Push %s zurückrollen", resp.StatusCode, by)
		}
		return nil, fmt.Errorf("HTTP %d: %s", resp.StatusCode, agentapi.Printable(fmt.Sprint(body["error"])))
	}
	return body, nil
}
