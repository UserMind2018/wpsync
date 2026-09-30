package setup

import (
	"errors"
	"os"
	"strings"
	"testing"
)

func TestDockerVersionOK(t *testing.T) {
	for v, want := range map[string]bool{"29.4.0": true, "25.0.1": true, "20.10.20": false, "": false, "garbage": false} {
		if got := DockerVersionOK(v); got != want {
			t.Errorf("DockerVersionOK(%q) = %v, want %v", v, got, want)
		}
	}
}

func healthy() Env {
	return Env{
		ReadFile:   func(string) ([]byte, error) { return []byte(ResolverContent), nil },
		LookupHost: func(string) ([]string, error) { return []string{"127.0.0.1"}, nil },
		Output: func(name string, args ...string) (string, error) {
			if name == "docker" {
				return "29.4.0\n", nil
			}
			return "ddev version v1.25.4\n", nil
		},
		Stat:            func(string) (os.FileInfo, error) { return nil, nil },
		MailguardSource: "/x/00-local-mailguard.php",
	}
}

func TestDoctorAllGreen(t *testing.T) {
	for _, c := range Doctor(healthy()) {
		if !c.OK {
			t.Errorf("%s failed: %s", c.Name, c.Detail)
		}
	}
}

func TestDoctorReportsFixes(t *testing.T) {
	e := healthy()
	e.ReadFile = func(string) ([]byte, error) { return nil, os.ErrNotExist }
	e.LookupHost = func(string) ([]string, error) { return nil, errors.New("no such host") }
	e.Output = func(name string, args ...string) (string, error) {
		if name == "docker" {
			return "20.10.20", nil
		}
		return "", errors.New("not found")
	}
	e.Stat = func(string) (os.FileInfo, error) { return nil, os.ErrNotExist }

	failed := 0
	for _, c := range Doctor(e) {
		if !c.OK {
			failed++
			if c.Fix == "" {
				t.Errorf("%s: missing fix", c.Name)
			}
		}
	}
	if failed != 5 {
		t.Fatalf("failed = %d, want 5", failed)
	}
}

func TestResolverCommandContainsContent(t *testing.T) {
	if !strings.Contains(ResolverCommand(), "nameserver 1.1.1.1") {
		t.Fatal(ResolverCommand())
	}
}
