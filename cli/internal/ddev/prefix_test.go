package ddev

import (
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

// SEC-132 AC-7: Start reicht einen ungültigen Präfix nie weiter – kein ddev-, kein docker-Aufruf,
// weder public/ noch .ddev entstehen.
func TestStartRefusesInvalidTablePrefix(t *testing.T) {
	for _, prefix := range []string{"--path=/x", "--exec=phpinfo();//", ""} {
		t.Run(prefix, func(t *testing.T) {
			site := t.TempDir()
			docker := hardenedDocker(site)
			p, err := OpenProject("kunde", site, Store{Dir: filepath.Join(t.TempDir(), "ddev-state")}, docker)
			if err != nil {
				t.Fatal(err)
			}
			docker.calls = nil
			inner := &fakeDDEV{dir: site}
			env := testEnv
			env.TablePrefix = prefix

			fresh, err := Start(p.Runner(inner), p, env, mailguardFile(t), io.Discard)
			if !errors.Is(err, agentapi.ErrInvalidEnv) || fresh {
				t.Fatalf("Start = %v, %v; want ErrInvalidEnv", fresh, err)
			}
			if !strings.Contains(err.Error(), "table_prefix") {
				t.Errorf("message misses field: %v", err)
			}
			if inner.n != 0 {
				t.Errorf("ddev called: %v", argsOf(inner.calls))
			}
			if len(docker.calls) != 0 {
				t.Errorf("docker called: %v", docker.calls)
			}
			for _, rel := range []string{"public", ".ddev"} {
				if _, err := os.Stat(filepath.Join(site, rel)); err == nil {
					t.Errorf("%s created", rel)
				}
			}
		})
	}
}

// SEC-132 AC-7 (Folge-Pull): auch bei bestehendem Projekt kommt kein Aufruf zustande.
func TestStartRefusesInvalidTablePrefixOnExistingProject(t *testing.T) {
	p, inner, docker, store, mg := newSite(t)
	p2, err := OpenProject("kunde", p.Dir, store, docker)
	if err != nil {
		t.Fatal(err)
	}
	inner.calls, inner.n = nil, 0
	env := testEnv
	env.TablePrefix = "--path=/x"
	if _, err := Start(p2.Runner(inner), p2, env, mg, io.Discard); !errors.Is(err, agentapi.ErrInvalidEnv) {
		t.Fatalf("Start = %v, want ErrInvalidEnv", err)
	}
	if inner.n != 0 {
		t.Errorf("ddev called: %v", argsOf(inner.calls))
	}
}

// SEC-132 AC-9: gültige Präfixe ergeben die bisherige Argumentliste; wp_ setzt nichts.
func TestStartSetsTablePrefixUnchanged(t *testing.T) {
	for prefix, want := range map[string]int{"djTui5D_": 1, "wp_": 0} {
		t.Run(prefix, func(t *testing.T) {
			site := t.TempDir()
			p, err := OpenProject("kunde", site, Store{Dir: filepath.Join(t.TempDir(), "ddev-state")}, hardenedDocker(site))
			if err != nil {
				t.Fatal(err)
			}
			inner := &fakeDDEV{dir: site}
			env := testEnv
			env.TablePrefix = prefix
			if _, err := Start(p.Runner(inner), p, env, mailguardFile(t), io.Discard); err != nil {
				t.Fatal(err)
			}
			exact, total := 0, 0
			for _, c := range argsOf(inner.calls) {
				if c == "wp config set table_prefix "+prefix+" --type=variable" {
					exact++
				}
				if strings.HasPrefix(c, "wp config set table_prefix") {
					total++
				}
			}
			if exact != want || total != want {
				t.Errorf("table_prefix calls: exact %d, total %d, want %d\n%s", exact, total, want, strings.Join(argsOf(inner.calls), "\n"))
			}
		})
	}
}
