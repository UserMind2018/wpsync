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

// ErrRescueUnreachable: without a way back there is no push (AC-66).
var ErrRescueUnreachable = errors.New("das Notfall-Skript rescue.php ist nicht erreichbar")

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
	site, err1 := url.Parse(siteURL)
	rescue, err2 := url.Parse(rescueURL)
	if err1 != nil || err2 != nil || rescue.Host == "" || !strings.EqualFold(site.Host, rescue.Host) || site.Scheme != rescue.Scheme {
		return fmt.Errorf("der Agent nennt eine Rescue-URL ausserhalb der gekoppelten Site: %q", rescueURL)
	}
	return nil
}

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
		return nil, fmt.Errorf("HTTP %d: %v", resp.StatusCode, body["error"])
	}
	return body, nil
}
