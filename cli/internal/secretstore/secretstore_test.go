package secretstore

import (
	"errors"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/keychain"
)

func TestKeychainUsesFormerServiceNames(t *testing.T) {
	kc := keychain.NewMemory()
	s := Keychain{KC: kc}
	if err := s.Set("kunde", "geheim"); err != nil {
		t.Fatal(err)
	}
	if got, err := kc.Get("wpsync:kunde", "kunde"); err != nil || got != "geheim" {
		t.Fatalf("keychain entry = %q, %v", got, err)
	}
	if got, err := s.Get("kunde"); err != nil || got != "geheim" {
		t.Fatalf("Get = %q, %v", got, err)
	}
	s.Delete("kunde")
	if _, err := s.Get("kunde"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("after Delete: err = %v", err)
	}
}

func TestStdinReadsSecretAndDBPassword(t *testing.T) {
	s := NewStdin(strings.NewReader("pairing-secret\r\ndb-pass\n"))
	if got, err := s.Get("egal"); err != nil || got != "pairing-secret" {
		t.Fatalf("Get = %q, %v", got, err)
	}
	if got, err := s.Get("egal"); err != nil || got != "pairing-secret" {
		t.Fatalf("second Get must return the same line, got %q, %v", got, err)
	}
	if got, err := s.DBPassword(); err != nil || got != "db-pass" {
		t.Fatalf("DBPassword = %q, %v", got, err)
	}
}

func TestStdinWithoutTrailingNewline(t *testing.T) {
	s := NewStdin(strings.NewReader("nur-secret"))
	if got, err := s.Get(""); err != nil || got != "nur-secret" {
		t.Fatalf("Get = %q, %v", got, err)
	}
	if _, err := s.DBPassword(); err == nil || !strings.Contains(err.Error(), "Zeile 2 fehlt") {
		t.Fatalf("missing second line: err = %v", err)
	}
}

func TestStdinRejectsEmptyAndOverlongLines(t *testing.T) {
	if _, err := NewStdin(strings.NewReader("\n")).Get(""); err == nil || !strings.Contains(err.Error(), "leer") {
		t.Fatalf("empty line: err = %v", err)
	}
	if _, err := NewStdin(strings.NewReader(strings.Repeat("x", maxLine+1))).Get(""); err == nil {
		t.Fatal("overlong line must fail")
	}
}

func TestStdinStoresNothing(t *testing.T) {
	s := NewStdin(strings.NewReader(""))
	if err := s.Set("kunde", "x"); !errors.Is(err, ErrReadOnly) {
		t.Fatalf("Set: err = %v", err)
	}
	if err := s.Delete("kunde"); err != nil {
		t.Fatalf("Delete: err = %v", err)
	}
}

// Fehlermeldungen nennen nie den Inhalt einer Zeile.
func TestStdinErrorsNeverContainTheSecret(t *testing.T) {
	s := NewStdin(strings.NewReader("streng-geheim"))
	s.Get("")
	_, err := s.DBPassword()
	if err == nil || strings.Contains(err.Error(), "streng-geheim") {
		t.Fatalf("err = %v", err)
	}
}
