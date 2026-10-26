package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/keychain"
	"github.com/usermind/wpsync/internal/sites"
)

// Spec §5: pair --json --secret-out gibt das Secret genau einmal auf stdout aus und speichert es nicht.
func TestPairJSONSecretOut(t *testing.T) {
	configDir, _ := env(t)
	ag := newAgent(t)
	kc := keychain.NewMemory()
	r := runKC(t, context.Background(), kc, "", "pair", ag.URL(), "123456", "--name", "kunde", "--insecure", "--json", "--secret-out")
	m := lastResult(t, r, "pair", 0)
	d := requireKeys(t, m["data"], "site", "url", "key_id", "agent_version", "required_agent_version", "agent_ok", "secret")
	if d["secret"] != testSecret || d["agent_ok"] != true {
		t.Fatalf("data = %v", d)
	}
	if n := strings.Count(r.stdout, testSecret); n != 1 {
		t.Fatalf("secret %d× on stdout", n)
	}
	if strings.Contains(r.stderr, testSecret) {
		t.Fatal("secret on stderr")
	}
	if _, err := kc.Get("wpsync:kunde", "kunde"); err == nil {
		t.Fatal("--secret-out must not store the secret in the keychain")
	}
	data, err := os.ReadFile(filepath.Join(configDir, "sites", "kunde.yaml"))
	if err != nil || strings.Contains(string(data), testSecret) {
		t.Fatalf("site file = %q, %v", data, err)
	}
}

// Ohne --secret-out bleibt es beim heutigen Verhalten: Secret in die Keychain, nicht in die Ausgabe.
func TestPairJSONStoresInKeychain(t *testing.T) {
	env(t)
	ag := newAgent(t)
	kc := keychain.NewMemory()
	r := runKC(t, context.Background(), kc, "", "pair", ag.URL(), "123456", "--name", "kunde", "--insecure", "--json")
	lastResult(t, r, "pair", 0)
	if strings.Contains(r.stdout+r.stderr, testSecret) {
		t.Fatal("secret in output without --secret-out")
	}
	if got, err := kc.Get("wpsync:kunde", "kunde"); err != nil || got != testSecret {
		t.Fatalf("keychain = %q, %v", got, err)
	}
}

func TestPairSecretOutNeedsJSON(t *testing.T) {
	env(t)
	ag := newAgent(t)
	r := run(t, context.Background(), "", "pair", ag.URL(), "123456", "--insecure", "--secret-out")
	if r.code != 2 || strings.Contains(r.stdout+r.stderr, testSecret) {
		t.Fatalf("exit = %d\n%s%s", r.code, r.stdout, r.stderr)
	}
}

func TestPairJSONRejectedCode(t *testing.T) {
	env(t)
	ag := newAgent(t)
	ag.RejectPair = true
	r := run(t, context.Background(), "", "pair", ag.URL(), "000000", "--insecure", "--json", "--secret-out")
	m := lastResult(t, r, "pair", 12)
	if e := requireKeys(t, m["error"], "code"); e["code"] != "pair_rejected" {
		t.Fatalf("error = %v", e)
	}
}

func TestPairJSONUnreachable(t *testing.T) {
	env(t)
	ag := newAgent(t)
	url := ag.URL()
	ag.srv.Close()
	r := run(t, context.Background(), "", "pair", url, "123456", "--insecure", "--json", "--secret-out")
	m := lastResult(t, r, "pair", 10)
	if e := requireKeys(t, m["error"], "code"); e["code"] != "agent_unreachable" {
		t.Fatalf("error = %v", e)
	}
}

// Kopplung über http ohne --insecure ist ein Aufruffehler, kein Netzfehler.
func TestPairJSONRefusesHTTP(t *testing.T) {
	env(t)
	ag := newAgent(t)
	r := run(t, context.Background(), "", "pair", ag.URL(), "123456", "--json", "--secret-out")
	lastResult(t, r, "pair", 2)
}

// Spec Container-Push C8: pair merkt sich den Gerätenamen in der Site-Datei – den genannten oder
// den Rechnernamen.
func TestPairStoresTheDevice(t *testing.T) {
	env(t)
	ag := newAgent(t)
	r := run(t, context.Background(), "", "pair", ag.URL(), "123456", "--name", "kunde", "--device", "agentic-os-dev", "--insecure", "--json", "--secret-out")
	lastResult(t, r, "pair", 0)
	if s, err := sites.Load("kunde"); err != nil || s.Device != "agentic-os-dev" {
		t.Fatalf("site = %+v, %v", s, err)
	}
	host, _ := os.Hostname()
	r = run(t, context.Background(), "", "pair", ag.URL(), "123456", "--name", "kunde2", "--insecure", "--json", "--secret-out")
	lastResult(t, r, "pair", 0)
	if s, err := sites.Load("kunde2"); err != nil || s.Device != host {
		t.Fatalf("site = %+v, %v (host %s)", s, err, host)
	}
}

// Spec 0.9.1 G2, G3, AC-244–AC-246: --device > Name aus dem WP-Admin > Rechnername. Die CLI merkt
// sich den Namen, den die Site gespeichert hat, und zeigt ihn samt Kennung.
func TestPairTakesTheNameTheSiteStored(t *testing.T) {
	env(t)
	ag := newAgent(t)
	ag.NamesDevice, ag.CodeDevice = true, "Studio Büro"
	host, _ := os.Hostname()

	// AC-244: Code mit Name, pair ohne --device.
	r := run(t, context.Background(), "", "pair", ag.URL(), "123456", "--name", "kunde", "--insecure", "--json", "--secret-out")
	d := requireKeys(t, lastResult(t, r, "pair", 0)["data"], "key_id", "device")
	if d["device"] != "Studio Büro" || d["key_id"] != "0123456789abcdef" {
		t.Errorf("data = %v", d)
	}
	if s, err := sites.Load("kunde"); err != nil || s.Device != "Studio Büro" {
		t.Fatalf("site = %+v, %v", s, err)
	}
	if req := ag.pairs[0]; req["device"] != host {
		t.Errorf("request = %v, want the host name %q", req, host)
	} else if _, has := req["device_given"]; has {
		t.Errorf("device_given without --device: %v", req)
	}

	// AC-245: derselbe Fall mit --device.
	r = run(t, context.Background(), "", "pair", ag.URL(), "123456", "--name", "kunde2", "--device", "Laptop", "--insecure", "--json", "--secret-out")
	if d := lastResult(t, r, "pair", 0)["data"].(map[string]any); d["device"] != "Laptop" {
		t.Errorf("data = %v", d)
	}
	if s, _ := sites.Load("kunde2"); s.Device != "Laptop" || ag.pairs[1]["device_given"] != true {
		t.Errorf("site = %+v, request = %v", s, ag.pairs[1])
	}

	// AC-246: Code ohne Name, ohne --device – der Rechnername, wie bisher.
	ag.CodeDevice = ""
	r = runKC(t, context.Background(), keychain.NewMemory(), "", "pair", ag.URL(), "123456", "--name", "kunde3", "--insecure")
	if s, _ := sites.Load("kunde3"); r.code != 0 || s.Device != host {
		t.Errorf("exit %d, site = %+v", r.code, s)
	}
	// Die Ausgabe nennt Gerät und Kennung.
	if !strings.Contains(r.stdout, "Gerät: "+host+" (Kennung 0123456789abcdef)") {
		t.Errorf("stdout = %s", r.stdout)
	}
}

// Spec 0.9.1 G7: wpsync sites nennt je Kopplung Gerätename und Kennung – als Text und als JSON, ohne
// Anfrage an die Site und ohne Secret.
func TestSitesListsDeviceAndKeyID(t *testing.T) {
	env(t)
	r := run(t, context.Background(), "", "sites", "--json")
	if d := lastResult(t, r, "sites", 0)["data"].(map[string]any); len(d["sites"].([]any)) != 0 {
		t.Errorf("data = %v", d)
	}
	sites.Save(&sites.Site{Name: "kunde", URL: "https://kunde.example", KeyID: "0123456789abcdef", RPS: 1, Device: "Studio Büro"})
	sites.Save(&sites.Site{Name: "alt", URL: "https://alt.example", KeyID: "fedcba9876543210", RPS: 1})
	r = runKC(t, context.Background(), lockedKeychain{t}, "", "sites", "--json")
	list := lastResult(t, r, "sites", 0)["data"].(map[string]any)["sites"].([]any)
	if len(list) != 2 {
		t.Fatalf("sites = %v", list)
	}
	first, second := list[0].(map[string]any), list[1].(map[string]any)
	if keys(second) != "device key_id site url" || second["device"] != "Studio Büro" || second["key_id"] != "0123456789abcdef" || second["site"] != "kunde" {
		t.Errorf("site = %v", second)
	}
	if first["site"] != "alt" || first["device"] != "" || first["key_id"] != "fedcba9876543210" {
		t.Errorf("site = %v", first)
	}
	r = runKC(t, context.Background(), lockedKeychain{t}, "", "sites")
	if r.code != 0 || !strings.Contains(r.stdout, "Studio Büro") || !strings.Contains(r.stdout, "0123456789abcdef") || !strings.Contains(r.stdout, "fedcba9876543210") {
		t.Errorf("exit %d\n%s%s", r.code, r.stdout, r.stderr)
	}
	if r := run(t, context.Background(), "", "sites", "extra"); r.code != 2 {
		t.Errorf("exit = %d", r.code)
	}
}
