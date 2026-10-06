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
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

var (
	// ErrRescueUnreachable: without a way back there is no push (AC-66).
	ErrRescueUnreachable = errors.New("das Notfall-Skript rescue.php ist nicht erreichbar")
	// ErrRescueConfirmed: rescue.php takes back only a push that was swapped in but not yet
	// confirmed; a confirmed one goes through the agent or the WP admin (U18).
	ErrRescueConfirmed = errors.New("rescue.php rollt nur unbestätigte Pushes zurück – dieser ist bestätigt; im WP-Admin unter Werkzeuge → wpsync zurückrollen, ohne WordPress per FTP")
)

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

// RescueRollback restores the snapshot of a push, bypassing WordPress.
func RescueRollback(hc *http.Client, rescueURL, pushID, key string) error {
	_, err := rescuePost(hc, rescueURL, url.Values{"action": {"rollback"}, "push_id": {pushID}, "key": {key}})
	return err
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
