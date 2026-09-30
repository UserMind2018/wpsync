// Package setup prepares a Mac once (Konzept E17) and diagnoses problems (wpsync doctor).
package setup

import (
	"fmt"
	"net"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"time"
)

// ResolverPath routes *.ddev.site past router DNS-rebind protection (Spike B2).
const ResolverPath = "/etc/resolver/ddev.site"

// ResolverContent of the resolver file.
const ResolverContent = "nameserver 1.1.1.1\nnameserver 8.8.8.8\n"

// Check is one doctor result.
type Check struct {
	Name   string
	OK     bool
	Detail string
	Fix    string
}

// Env abstracts the system for tests.
type Env struct {
	ReadFile        func(string) ([]byte, error)
	LookupHost      func(string) ([]string, error)
	Output          func(name string, args ...string) (string, error)
	Stat            func(string) (os.FileInfo, error)
	MailguardSource string
	MailguardOrigin string // shown in doctor: env or bundled copy
}

// SystemEnv uses the real system.
func SystemEnv(mailguardSource string) Env {
	return Env{
		ReadFile:   os.ReadFile,
		LookupHost: net.LookupHost,
		Output: func(name string, args ...string) (string, error) {
			out, err := exec.Command(name, args...).Output()
			return string(out), err
		},
		Stat:            os.Stat,
		MailguardSource: mailguardSource,
	}
}

// DockerVersionOK requires Docker ≥ 25 (DDEV, Spike B3).
func DockerVersionOK(version string) bool {
	major, err := strconv.Atoi(strings.SplitN(strings.TrimSpace(version), ".", 2)[0])
	return err == nil && major >= 25
}

// Doctor runs all checks without sudo.
func Doctor(e Env) []Check {
	var checks []Check

	content, err := e.ReadFile(ResolverPath)
	checks = append(checks, Check{
		Name:   "DNS-Resolver für *.ddev.site",
		OK:     err == nil && strings.Contains(string(content), "nameserver"),
		Detail: ResolverPath,
		Fix:    "wpsync setup ausführen",
	})

	addrs, err := e.LookupHost("wpsync-doctor.ddev.site")
	resolves := err == nil && len(addrs) > 0 && addrs[0] == "127.0.0.1"
	checks = append(checks, Check{
		Name:   "Namensauflösung *.ddev.site → 127.0.0.1",
		OK:     resolves,
		Detail: fmt.Sprintf("%v %v", addrs, err),
		Fix:    "wpsync setup ausführen; bleibt es rot, blockiert das Netz externes DNS",
	})

	docker, err := e.Output("docker", "version", "--format", "{{.Server.Version}}")
	checks = append(checks, Check{
		Name:   "Docker ≥ 25 läuft",
		OK:     err == nil && DockerVersionOK(docker),
		Detail: strings.TrimSpace(docker),
		Fix:    "OrbStack installieren und starten (brew install --cask orbstack)",
	})

	ddevVersion, err := e.Output("ddev", "--version")
	checks = append(checks, Check{
		Name:   "DDEV installiert",
		OK:     err == nil,
		Detail: strings.TrimSpace(ddevVersion),
		Fix:    "DDEV installieren: https://ddev.com/get-started/",
	})

	_, err = e.Stat(e.MailguardSource)
	detail := e.MailguardSource
	if e.MailguardOrigin != "" {
		detail = strings.TrimSpace(detail + " (" + e.MailguardOrigin + ")")
	}
	checks = append(checks, Check{
		Name:   "local-mailguard vorhanden",
		OK:     err == nil,
		Detail: detail,
		Fix:    "WPSYNC_MAILGUARD auf eine vorhandene Datei setzen oder die Variable entfernen (dann nutzt wpsync den mitgelieferten Riegel)",
	})
	return checks
}

// ResolverCommand is the one sudo command of setup.
func ResolverCommand() string {
	return fmt.Sprintf("mkdir -p /etc/resolver && printf '%s' > %s", strings.ReplaceAll(ResolverContent, "\n", `\n`), ResolverPath)
}

// Run performs the one-time setup interactively (sudo asks for the password in the terminal).
func Run() error {
	steps := [][]string{
		{"sudo", "sh", "-c", ResolverCommand()},
		{"mkcert", "-install"},
	}
	if port80Busy() {
		// Local by Flywheel's router holds 80/443 until it is retired (Konzept E15).
		steps = append(steps, []string{"ddev", "config", "global", "--router-http-port=8480", "--router-https-port=8443"})
	}
	for _, s := range steps {
		fmt.Printf("→ %s\n", strings.Join(s, " "))
		cmd := exec.Command(s[0], s[1:]...)
		cmd.Stdin, cmd.Stdout, cmd.Stderr = os.Stdin, os.Stdout, os.Stderr
		if err := cmd.Run(); err != nil {
			return fmt.Errorf("%s: %w", s[0], err)
		}
	}
	return nil
}

func port80Busy() bool {
	conn, err := net.DialTimeout("tcp", "127.0.0.1:80", 500*time.Millisecond)
	if err != nil {
		return false
	}
	conn.Close()
	return true
}
