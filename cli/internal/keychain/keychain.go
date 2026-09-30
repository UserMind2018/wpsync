// Package keychain stores pairing secrets in the macOS login keychain.
// The secret goes to `security` via stdin, never via argv (process list).
package keychain

import (
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"strings"
)

// ErrNotFound means there is no entry.
var ErrNotFound = errors.New("keychain entry not found")

// Store abstracts the keychain for tests.
type Store interface {
	Set(service, account, secret string) error
	Get(service, account string) (string, error)
	Delete(service, account string) error
}

// MacOS uses /usr/bin/security.
type MacOS struct{}

var safe = regexp.MustCompile(`^[A-Za-z0-9._:@-]+$`)

// Set creates or updates the entry.
func (MacOS) Set(service, account, secret string) error {
	for _, v := range []string{service, account, secret} {
		if !safe.MatchString(v) {
			return errors.New("keychain: refusing unsafe characters in service, account or secret")
		}
	}
	cmd := exec.Command("security", "-i")
	cmd.Stdin = strings.NewReader(fmt.Sprintf("add-generic-password -U -s %s -a %s -w %s\n", service, account, secret))
	out, err := cmd.CombinedOutput()
	if err != nil || strings.TrimSpace(string(out)) != "" {
		return fmt.Errorf("keychain: add failed: %v %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

// Get returns the secret.
func (MacOS) Get(service, account string) (string, error) {
	out, err := exec.Command("security", "find-generic-password", "-s", service, "-a", account, "-w").Output()
	if err != nil {
		return "", ErrNotFound
	}
	return strings.TrimSpace(string(out)), nil
}

// Delete removes the entry; a missing entry is not an error.
func (MacOS) Delete(service, account string) error {
	_ = exec.Command("security", "delete-generic-password", "-s", service, "-a", account).Run()
	return nil
}

// Memory is an in-memory Store for tests.
type Memory struct{ m map[string]string }

// NewMemory creates an empty Memory store.
func NewMemory() *Memory { return &Memory{m: map[string]string{}} }

func (s *Memory) Set(service, account, secret string) error {
	s.m[service+"\x00"+account] = secret
	return nil
}

func (s *Memory) Get(service, account string) (string, error) {
	v, ok := s.m[service+"\x00"+account]
	if !ok {
		return "", ErrNotFound
	}
	return v, nil
}

func (s *Memory) Delete(service, account string) error {
	delete(s.m, service+"\x00"+account)
	return nil
}
