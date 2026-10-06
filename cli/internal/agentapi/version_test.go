package agentapi

import (
	"errors"
	"strings"
	"testing"
)

func TestVersionAtLeast(t *testing.T) {
	cases := []struct {
		have, want string
		ok         bool
	}{
		{"0.3.1", "0.3.0", true},
		{"0.3.0", "0.3.0", true},
		{"0.2.9", "0.3.0", false},
		{"0.10.0", "0.9.0", true},
		{"1.0", "0.3.0", true},
		{"v0.3.0", "0.3.0", true},
		{"", "0.3.0", false},
		{"0.3.x", "0.3.0", false},
	}
	for _, c := range cases {
		if got := VersionAtLeast(c.have, c.want); got != c.ok {
			t.Errorf("VersionAtLeast(%q, %q) = %v, want %v", c.have, c.want, got, c.ok)
		}
	}
}

func TestOutdatedErrorNamesBothVersions(t *testing.T) {
	reason := errors.New("kann nicht anonymisieren")
	err := error(&OutdatedError{Installed: "0.2.0", Required: "0.3.0", Err: reason})
	if !strings.Contains(err.Error(), "installiert: 0.2.0") || !strings.Contains(err.Error(), "nötig: 0.3.0") {
		t.Errorf("message = %q", err.Error())
	}
	if !errors.Is(err, reason) {
		t.Error("OutdatedError must unwrap to its reason")
	}
	if !strings.Contains((&OutdatedError{Required: "0.3.0"}).Error(), "installiert: unbekannt") {
		t.Error("missing version must read unbekannt")
	}
}
