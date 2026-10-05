package localenv

import (
	"errors"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/ddev"
)

type fakeGuard struct {
	deviate map[string]error
	haltErr error
	checked []string
	halted  []string
}

func (g *fakeGuard) Check(name string) error {
	g.checked = append(g.checked, name)
	return g.deviate[name]
}

func (g *fakeGuard) Halt(name string) error {
	g.halted = append(g.halted, name)
	return g.haltErr
}

// AC-6/AC-10: `wpsync stop` ruft ddev stop nur für geprüfte Sites; eine abweichende Site wird
// ohne ddev angehalten, gemeldet (Fehler) und blockiert die übrigen nicht.
func TestStopChecksEverySiteBeforeDDEV(t *testing.T) {
	envs := []Env{
		{Name: "a", Status: StatusRunning}, {Name: "boese", Status: StatusRunning},
		{Name: "c", Status: StatusRunning}, {Name: "vorher", Status: StatusRunning},
	}
	g := &fakeGuard{deviate: map[string]error{
		"boese":  &ddev.DeviationError{Site: "boese", Changes: []ddev.Change{{Path: "config.audit.yaml", Kind: ddev.ChangeAdded}}},
		"vorher": ddev.ErrNotAdopted,
	}}
	d := &fakeDDEV{}
	n, err := Stop(d, envs, nil, true, g)
	if !reflect.DeepEqual(d.stopped, [][]string{{"stop", "a", "c"}}) {
		t.Fatalf("ddev calls = %v", d.stopped)
	}
	if !reflect.DeepEqual(g.checked, []string{"a", "boese", "c", "vorher"}) || !reflect.DeepEqual(g.halted, []string{"boese", "vorher"}) {
		t.Fatalf("checked = %v, halted = %v", g.checked, g.halted)
	}
	if n != 4 {
		t.Fatalf("stopped = %d", n)
	}
	if err == nil || !strings.Contains(err.Error(), "config.audit.yaml") || !strings.Contains(err.Error(), "boese wurde ohne ddev angehalten") {
		t.Fatalf("err = %v", err)
	}
	if strings.Contains(err.Error(), "vorher") {
		t.Fatalf("site without state reported as deviation: %v", err)
	}
}

func TestStopSingleDeviatingSiteRunsNoDDEV(t *testing.T) {
	g := &fakeGuard{deviate: map[string]error{"boese": &ddev.DeviationError{Site: "boese", Changes: []ddev.Change{{Path: "commands/host/x", Kind: ddev.ChangeAdded}}}}}
	d := &fakeDDEV{}
	_, err := Stop(d, []Env{{Name: "boese", Status: StatusPaused}}, []string{"boese"}, false, g)
	if err == nil || len(d.stopped) != 0 || !strings.Contains(err.Error(), "commands/host/x") || len(g.halted) != 1 {
		t.Fatalf("err = %v, ddev calls = %v, halted = %v", err, d.stopped, g.halted)
	}

	g.haltErr = errors.New("docker down")
	g.halted = nil
	_, err = Stop(d, []Env{{Name: "boese", Status: StatusRunning}}, []string{"boese"}, false, g)
	if err == nil || !strings.Contains(err.Error(), "boese nicht gestoppt: docker down") || len(d.stopped) != 0 {
		t.Fatalf("err = %v, ddev calls = %v", err, d.stopped)
	}
}

// D1: ddev list führt pre-describe-Hooks laufender Projekte aus – nur mit --skip-hooks.
func TestListSkipsHooks(t *testing.T) {
	d := &recordingDDEV{out: `{"raw":[]}`}
	if _, err := List(d, "/r", nil); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(d.args, []string{"list", "-j", "--skip-hooks"}) {
		t.Fatalf("args = %v", d.args)
	}
}

type recordingDDEV struct {
	out  string
	args []string
}

func (r *recordingDDEV) Output(args ...string) (string, error) { r.args = args; return r.out, nil }
func (r *recordingDDEV) Run(args ...string) error              { return nil }
