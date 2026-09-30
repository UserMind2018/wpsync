package keychain

import (
	"errors"
	"testing"
)

func TestMemoryStore(t *testing.T) {
	var s Store = NewMemory()
	if err := s.Set("wpsync:a", "a", "secret"); err != nil {
		t.Fatal(err)
	}
	got, err := s.Get("wpsync:a", "a")
	if err != nil || got != "secret" {
		t.Fatalf("got %q, %v", got, err)
	}
	s.Delete("wpsync:a", "a")
	if _, err := s.Get("wpsync:a", "a"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("err = %v, want ErrNotFound", err)
	}
}

func TestMacOSRejectsUnsafeValues(t *testing.T) {
	if err := (MacOS{}).Set(`wpsync:a"; rm -rf`, "a", "x"); err == nil {
		t.Fatal("expected rejection of unsafe service name")
	}
}
