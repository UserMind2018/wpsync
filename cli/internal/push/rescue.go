package push

import (
	"context"
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
	"slices"
	"strings"
	"time"

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
	// ErrRescueBusy: another rollback of this push, or its commit, holds the lock on the site
	// (HTTP 423, Spec Content-Push P3 R10). The caller repeats the request.
	ErrRescueBusy = errors.New("auf der Site läuft für diesen Push gerade eine andere Rücknahme oder sein Commit")
)

// busyRetries: so often a rollback is repeated while the site answers 423, busyPause apart (Spec
// Content-Push P3 §7.1).
const (
	busyRetries = 3
	busyPause   = 2 * time.Second
)

// maxRescueAnswer bounds what is read of an answer of rescue.php: with content it may name up to
// 200 keys (Spec Content-Push P3 §7.6) – more than the 4,000 bytes that were enough before.
const maxRescueAnswer = 1 << 20

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

// RescueRollback restores the snapshot of a push, bypassing WordPress – code and uploads, never
// content.
func RescueRollback(hc *http.Client, rescueURL, pushID, key string) error {
	_, err := RescueRollbackNotes(hc, rescueURL, pushID, key, false)
	return err
}

// RescueRollbackNotes is RescueRollback and returns what rescue.php reports beyond the status
// (agent 0.6.0: uploads left in place because they changed since the push). With content it asks
// rescue.php to take the content of the push back first (content=1, agent 0.8.0, Spec Content-Push
// P3 R11): the notes then carry Content. An older rescue.php ignores the wish and says nothing
// about the content.
func RescueRollbackNotes(hc *http.Client, rescueURL, pushID, key string, content bool) (agentapi.RollbackNotes, error) {
	form := url.Values{"action": {"rollback"}, "push_id": {pushID}, "key": {key}}
	if content {
		form.Set("content", "1")
	}
	body, err := rescuePost(hc, rescueURL, form)
	if err != nil {
		return agentapi.RollbackNotes{}, err
	}
	raw, _ := json.Marshal(body)
	var notes agentapi.RollbackNotes
	_ = json.Unmarshal(raw, &notes) // a field of another type stays empty
	return notes.Clean(), nil
}

// RescueCache asks rescue.php to flush a persistent object cache that still holds the pushed
// state (action=cache, Spec Content-Push P3 R8) – a step of its own after a rollback whose answer
// said cache: "stale". It loads WordPress without plugins and themes; a failure never touches the
// rollback, which is done by then.
func RescueCache(hc *http.Client, rescueURL, pushID, key string) error {
	body, err := rescuePost(hc, rescueURL, url.Values{"action": {"cache"}, "push_id": {pushID}, "key": {key}})
	if err != nil {
		return err
	}
	if body["cache"] != "flushed" {
		return errors.New("rescue.php hat den Object-Cache nicht geleert")
	}
	return nil
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
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, maxRescueAnswer))
	var body map[string]any
	if json.Unmarshal(raw, &body) != nil {
		// A stub that was tidied away: the server answers 404 – or redirects, as WordPress does for
		// an address it does not know (Spec Content-Push P3 §9).
		gone := resp.StatusCode == http.StatusNotFound || (resp.StatusCode >= 300 && resp.StatusCode < 400)
		if gone && stubName.MatchString(path.Base(req.URL.Path)) {
			return nil, ErrRescueGone
		}
		return nil, fmt.Errorf("HTTP %d, keine Antwort von rescue.php", resp.StatusCode)
	}
	if ok, _ := body["ok"].(bool); resp.StatusCode != http.StatusOK || !ok {
		if body["error"] == "confirmed" {
			return nil, ErrRescueConfirmed
		}
		if resp.StatusCode == http.StatusLocked && body["error"] == "busy" {
			return nil, ErrRescueBusy
		}
		// The code could not be swapped back after the content was: say so, the next call skips the database.
		if content, _ := body["content"].(map[string]any); body["error"] == "restore failed" && content["state"] == "rolled_back" {
			return nil, fmt.Errorf("HTTP %d: restore failed – die Inhalte sind schon zurückgenommen, Code und Uploads noch nicht", resp.StatusCode)
		}
		if by, _ := body["by"].(string); body["error"] == "superseded" && pushIDRe.MatchString(by) {
			return nil, fmt.Errorf("HTTP %d: superseded – zuerst den späteren Push %s zurückrollen", resp.StatusCode, by)
		}
		return nil, fmt.Errorf("HTTP %d: %s", resp.StatusCode, agentapi.Printable(fmt.Sprint(body["error"])))
	}
	return body, nil
}

// rescueBack takes a push back through rescue.php, the way that needs no WordPress (Spec
// Content-Push P3 §9): with content=1 when the push carried content, repeated while another run
// holds the lock of the push; then – if rows were written back while a persistent object cache
// holds the pushed state – the cache step; and one push/list, best effort, so that the agent
// catches up on the post actions as soon as WordPress answers again.
//
// The warning content_not_rolled_back is the CLI's word whenever the content still stands: when
// rescue.php says "kept", and when it says nothing about the content at all (an agent before
// 0.8.0, which ignores content=1).
func rescueBack(o Options, j *Journal) (agentapi.RollbackNotes, error) {
	key := RescueKey(o.Secret, j.PushID, j.Salt)
	var notes agentapi.RollbackNotes
	var err error
	for attempt := 0; ; attempt++ {
		notes, err = RescueRollbackNotes(o.HTTP, j.RescueURL, j.PushID, key, j.Content != nil)
		if !errors.Is(err, ErrRescueBusy) || attempt == busyRetries {
			break
		}
		o.Sleep(busyPause)
	}
	if err != nil || j.Content == nil {
		return notes, err
	}
	if notes.Content == nil || notes.Content.State == "kept" {
		if !slices.Contains(notes.Warnings, WarningContentNotRolledBack) {
			notes.Warnings = append(notes.Warnings, WarningContentNotRolledBack)
		}
		return notes, nil
	}
	if notes.Content.Cache == "stale" {
		if cerr := RescueCache(o.HTTP, j.RescueURL, j.PushID, key); cerr != nil {
			fmt.Fprintln(o.Out, "  ! Der Object-Cache der Site trägt noch den gepushten Stand – beim Hoster leeren, falls die Site nicht antwortet.")
			notes.Warnings = append(notes.Warnings, WarningObjectCacheStale)
		}
	}
	o.nudge()
	return notes, nil
}

// nudge asks the agent for its push log once, ignoring the answer: any signed request lets it
// take over what rescue.php did and catch up on the post actions (Push::sync()). WordPress may
// still be down – then the marker rescue.php left does the same on the next page view.
func (o Options) nudge() {
	prev := o.Client.Ctx
	parent := prev
	if parent == nil {
		parent = context.Background()
	}
	ctx, cancel := context.WithTimeout(parent, 20*time.Second)
	defer func() {
		cancel()
		o.Client.Ctx = prev
	}()
	o.Client.Ctx = ctx
	_, _ = o.Client.PushList()
}
