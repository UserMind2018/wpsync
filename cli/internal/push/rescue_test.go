package push

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// Derselbe Wert wie PushRescue::key() im Agent – sonst lehnt rescue.php jeden Rollback ab.
func TestRescueKeyMatchesTheAgent(t *testing.T) {
	const secret = "000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f"
	got := RescueKey(secret, "p_20261005_0123456789ab", "salt")
	// php -r 'echo hash_hmac("sha256", "rescue:p_20261005_0123456789ab:salt", "000102…1e1f");'
	const want = "f6816807861967d30c2ce1f92b8c6710528f01f54e73a65220966f2d9ff397a8"
	if got != want {
		t.Errorf("RescueKey = %s, want %s", got, want)
	}
	if RescueKey(secret, "p_20261005_0123456789ab", "other") == got {
		t.Error("salt must change the key")
	}
}

// Rechnet Schlüssel und Hash mit dem echten Agent-Code nach: PushRescue::write() bekommt den
// Hash so, wie Push.php ihn ablegt (sha256 über den hex-Schlüssel), PushRescue::handle() dann
// den Go-Schlüssel als POST-Feld – mit denselben Dateien, die rescue.php lädt. Ohne php im PATH
// übersprungen.
func TestRescueKeyAcceptedByAgentCode(t *testing.T) {
	php, err := exec.LookPath("php")
	if err != nil {
		t.Skip("php not installed")
	}
	src, err := filepath.Abs("../../../agent/src/PushRescue.php")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(src); err != nil {
		t.Skipf("agent source not found: %v", err)
	}
	const secret = "000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f"
	const pushID = "p_20261005_0123456789ab"
	key := RescueKey(secret, pushID, "salt")
	sum := sha256.Sum256([]byte(key))

	// PushRescue vergleicht mit dem aufgelösten wp-content; das Temp-Verzeichnis von macOS ist ein Symlink.
	content, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	work := filepath.Join(content, "wpsync-push-abc")
	if err := os.MkdirAll(filepath.Join(work, pushID), 0o755); err != nil {
		t.Fatal(err)
	}
	script := `define("WPSYNC_RESCUE", true); require dirname(getenv("SRC")) . "/PushSwap.php"; require getenv("SRC");
$c = getenv("CONTENT"); $id = getenv("PUSH_ID");
\WpSync\PushRescue::write($c . "/wpsync-push-abc", $id, getenv("KEY_HASH"), [], \WpSync\PushRescue::COMMITTED);
echo hash_equals(getenv("KEY_HASH"), hash("sha256", \WpSync\PushRescue::key(getenv("SECRET"), $id, "salt"))) ? "same" : "differs", "\n";
foreach (["wrong", getenv("KEY")] as $k) {
    list($s, $b) = \WpSync\PushRescue::handle([$c], ["action" => "rollback", "push_id" => $id, "key" => $k], time());
    echo $s, " ", json_encode($b), "\n";
}`
	cmd := exec.Command(php, "-r", script)
	cmd.Env = append(os.Environ(), "SRC="+src, "CONTENT="+content, "PUSH_ID="+pushID, "SECRET="+secret,
		"KEY="+key, "KEY_HASH="+hex.EncodeToString(sum[:]))
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("php: %v\n%s", err, out)
	}
	want := "same\n403 {\"ok\":false,\"error\":\"wrong key\"}\n200 {\"ok\":true,\"status\":\"rolled_back\"}\n"
	if string(out) != want {
		t.Errorf("php output:\n%s\nwant:\n%s", out, want)
	}
}

func rescueServer(t *testing.T, status int, body string, form *map[string]string) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("method = %s", r.Method)
		}
		r.ParseForm()
		if form != nil {
			*form = map[string]string{"action": r.PostForm.Get("action"), "push_id": r.PostForm.Get("push_id"), "key": r.PostForm.Get("key")}
		}
		w.WriteHeader(status)
		w.Write([]byte(body))
	}))
}

func TestRescuePing(t *testing.T) {
	var form map[string]string
	ok := rescueServer(t, 200, `{"ok":true}`, &form)
	defer ok.Close()
	if err := RescuePing(ok.Client(), ok.URL+"/rescue.php"); err != nil {
		t.Fatal(err)
	}
	if form["action"] != "ping" {
		t.Errorf("form = %v", form)
	}

	// AC-66: ein Sicherheits-Plugin sperrt direkte PHP-Aufrufe unter plugins/.
	blocked := rescueServer(t, 403, `<html>Forbidden</html>`, nil)
	defer blocked.Close()
	if err := RescuePing(blocked.Client(), blocked.URL+"/rescue.php"); !errors.Is(err, ErrRescueUnreachable) {
		t.Errorf("err = %v, want ErrRescueUnreachable", err)
	}
	html := rescueServer(t, 200, `<html>Startseite</html>`, nil)
	defer html.Close()
	if err := RescuePing(html.Client(), html.URL+"/rescue.php"); !errors.Is(err, ErrRescueUnreachable) {
		t.Errorf("err = %v, want ErrRescueUnreachable for a non-JSON answer", err)
	}
}

func TestRescueRollback(t *testing.T) {
	var form map[string]string
	ok := rescueServer(t, 200, `{"ok":true,"status":"rolled_back"}`, &form)
	defer ok.Close()
	if err := RescueRollback(ok.Client(), ok.URL+"/rescue.php", "p_20261005_0123456789ab", "k3y"); err != nil {
		t.Fatal(err)
	}
	if form["action"] != "rollback" || form["push_id"] != "p_20261005_0123456789ab" || form["key"] != "k3y" {
		t.Errorf("form = %v", form)
	}

	denied := rescueServer(t, 403, `{"ok":false,"error":"wrong key"}`, nil)
	defer denied.Close()
	err := RescueRollback(denied.Client(), denied.URL+"/rescue.php", "p_20261005_0123456789ab", "bad")
	if err == nil || !strings.Contains(err.Error(), "wrong key") {
		t.Errorf("err = %v", err)
	}

	// Die übrigen Antworten von PushRescue::handle()/rollback() und rescue.php.
	for _, c := range []struct {
		status int
		body   string
	}{
		{429, `{"ok":false,"error":"locked"}`},
		{404, `{"ok":false,"error":"unknown push"}`},
		{409, `{"ok":false,"error":"superseded","by":"p_20261006_0123456789ab"}`},
		{500, `{"ok":false,"error":"rescue failed"}`},
		{500, `{"ok":false,"error":"restore failed","units":["plugins/x"]}`},
	} {
		srv := rescueServer(t, c.status, c.body, nil)
		err := RescueRollback(srv.Client(), srv.URL+"/rescue.php", "p_20261005_0123456789ab", "k3y")
		srv.Close()
		if err == nil {
			t.Errorf("HTTP %d %s: want an error", c.status, c.body)
		}
	}

	// U6: wer abgelöst wurde, erfährt, welcher Push zuerst zurückgerollt werden muss.
	superseded := rescueServer(t, 409, `{"ok":false,"error":"superseded","by":"p_20261006_0123456789ab"}`, nil)
	defer superseded.Close()
	err = RescueRollback(superseded.Client(), superseded.URL+"/rescue.php", "p_20261005_0123456789ab", "k3y")
	if err == nil || !strings.Contains(err.Error(), "p_20261006_0123456789ab") {
		t.Errorf("err = %v, want the superseding push", err)
	}
}

func TestRescueAllowed(t *testing.T) {
	ok := [][2]string{
		{"https://kunde.de", "https://kunde.de/wp-content/plugins/wpsync-agent/rescue.php"},
		{"http://src.ddev.site:8080", "http://src.ddev.site:8080/wp-content/plugins/wpsync-agent/rescue.php"},
	}
	for _, c := range ok {
		if err := RescueAllowed(c[0], c[1]); err != nil {
			t.Errorf("RescueAllowed(%q, %q) = %v", c[0], c[1], err)
		}
	}
	bad := [][2]string{
		{"https://kunde.de", "http://kunde.de/wp-content/plugins/wpsync-agent/rescue.php"},
		{"https://kunde.de", "https://evil.example/rescue.php"},
		{"https://kunde.de", "https://kunde.de.evil.example/rescue.php"},
		{"https://kunde.de", ""},
	}
	for _, c := range bad {
		if err := RescueAllowed(c[0], c[1]); err == nil {
			t.Errorf("RescueAllowed(%q, %q) should fail", c[0], c[1])
		}
	}
}

// Der Schlüssel steht im POST-Body. Ein 307/308 schickte ihn sonst unverändert an jedes Ziel,
// auch an einen fremden Host – RescueAllowed prüft nur die erste URL.
func TestRescueDoesNotFollowRedirects(t *testing.T) {
	leaked := false
	elsewhere := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		leaked = true
		w.Write([]byte(`{"ok":true}`))
	}))
	defer elsewhere.Close()
	site := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, elsewhere.URL+"/rescue.php", http.StatusTemporaryRedirect)
	}))
	defer site.Close()

	hc := site.Client()
	if err := RescueRollback(hc, site.URL+"/rescue.php", "p_20261005_0123456789ab", "key"); err == nil {
		t.Error("a redirect must not count as a rollback")
	}
	if err := RescuePing(hc, site.URL+"/rescue.php"); err == nil {
		t.Error("a redirect must not count as a reachable rescue.php")
	}
	if leaked {
		t.Error("the rescue request followed the redirect")
	}
	if hc.CheckRedirect != nil {
		t.Error("the caller's client must stay unchanged")
	}
}

// R8: der Stub ist nach dem Aufräumen weg – 404 heisst dann „Notfallweg vorbei“, nicht „kaputt“.
func TestRescueRollbackOnAStubThatIsGone(t *testing.T) {
	gone := rescueServer(t, 404, `<html>Not Found</html>`, nil)
	defer gone.Close()
	err := RescueRollback(gone.Client(), gone.URL+"/wpsync-rescue-"+strings.Repeat("a", 32)+".php", "p_20261005_0123456789ab", "k")
	if !errors.Is(err, ErrRescueGone) {
		t.Errorf("stub: err = %v, want ErrRescueGone", err)
	}
	err = RescueRollback(gone.Client(), gone.URL+"/wp-content/plugins/wpsync-agent/rescue.php", "p_20261005_0123456789ab", "k")
	if err == nil || errors.Is(err, ErrRescueGone) {
		t.Errorf("plugin path: err = %v, want a plain HTTP 404", err)
	}
}

// AC-136
func TestHardeningHint(t *testing.T) {
	got := HardeningHint([]string{"ithemes-security-pro", "sucuri-scanner", "evil\x1b[2J"})
	for _, want := range []string{"Disable PHP in Plugins", "Sucuri", "evil"} {
		if !strings.Contains(got, want) {
			t.Errorf("hint %q lacks %q", got, want)
		}
	}
	if strings.ContainsRune(got, '\x1b') {
		t.Errorf("hint passes control characters: %q", got)
	}
	blocked := &RescueBlockedError{Plugins: []string{"x\x1by"}, Err: ErrRescueUnreachable}
	if strings.ContainsRune(blocked.Error(), '\x1b') {
		t.Errorf("error passes control characters: %q", blocked.Error())
	}
}
