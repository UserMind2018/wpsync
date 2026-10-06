package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/keychain"
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
