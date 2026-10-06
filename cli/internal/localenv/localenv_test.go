package localenv

import (
	"errors"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/sites"
)

// fakeDriver records Stop calls like the former `ddev stop a b`.
type fakeDriver struct {
	stopped [][]string
	n       int
	err     error
}

func (f *fakeDriver) Exists(string) (bool, error)     { return true, nil }
func (f *fakeDriver) Configure(agentapi.Env) error    { return nil }
func (f *fakeDriver) Setup(string) error              { return nil }
func (f *fakeDriver) Start(string) error              { return nil }
func (f *fakeDriver) Runner(string) Runner            { return nil }
func (f *fakeDriver) LocalURL(string) (string, error) { return "", nil }
func (f *fakeDriver) List() ([]Env, error)            { return nil, nil }
func (f *fakeDriver) Stop(refs ...string) (int, error) {
	f.stopped = append(f.stopped, append([]string{"stop"}, refs...))
	if f.err != nil {
		return f.n, f.err
	}
	return len(refs), nil
}

// Former List test: same projects, same result – Merge now gets what the driver found.
func TestMergeAddsPairedSites(t *testing.T) {
	found := []Env{
		{Name: "kunde-a", Status: StatusRunning, LocalURL: "https://kunde-a.ddev.site:8443", Ref: "kunde-a"},
		{Name: "alt", Status: StatusPaused, LocalURL: "https://alt.ddev.site:8443", Ref: "alt"},
	}
	paired := []sites.Site{{Name: "kunde-a", URL: "https://www.kunde-a.de"}, {Name: "neu", URL: "https://neu.de"}}
	want := []Env{
		{Name: "alt", Status: StatusPaused, LocalURL: "https://alt.ddev.site:8443", Ref: "alt"},
		{Name: "kunde-a", Status: StatusRunning, LocalURL: "https://kunde-a.ddev.site:8443", LiveURL: "https://www.kunde-a.de", Ref: "kunde-a"},
		{Name: "neu", Status: StatusMissing, LiveURL: "https://neu.de"},
	}
	if got := Merge(found, paired); !reflect.DeepEqual(got, want) {
		t.Fatalf("Merge =\n%+v\nwant\n%+v", got, want)
	}
}

func TestStopSelected(t *testing.T) {
	envs := []Env{{Name: "a", Status: StatusRunning}, {Name: "b", Status: StatusPaused}, {Name: "c", Status: StatusStopped}}
	d := &fakeDriver{}
	n, err := Stop(d, envs, []string{"a", "b", "c"}, false)
	if err != nil || n != 2 {
		t.Fatalf("Stop = %d, %v", n, err)
	}
	if !reflect.DeepEqual(d.stopped, [][]string{{"stop", "a", "b"}}) {
		t.Fatalf("stop calls = %v", d.stopped)
	}
}

func TestStopUsesRef(t *testing.T) {
	d := &fakeDriver{}
	if _, err := Stop(d, []Env{{Name: "vorlage", Status: StatusRunning, Ref: "ws-dev-vorlage"}}, []string{"vorlage"}, false); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(d.stopped, [][]string{{"stop", "ws-dev-vorlage"}}) {
		t.Fatalf("stop calls = %v", d.stopped)
	}
}

func TestStopAllOnlyRunning(t *testing.T) {
	envs := []Env{{Name: "a", Status: StatusRunning}, {Name: "b", Status: StatusPaused}, {Name: "c", Status: StatusStopped}, {Name: "d", Status: StatusMissing}}
	d := &fakeDriver{}
	n, err := Stop(d, envs, nil, true)
	if err != nil || n != 1 {
		t.Fatalf("Stop = %d, %v", n, err)
	}
	if !reflect.DeepEqual(d.stopped, [][]string{{"stop", "a"}}) {
		t.Fatalf("stop calls = %v", d.stopped)
	}
}

func TestStopUnknownSiteFailsBeforeStopping(t *testing.T) {
	d := &fakeDriver{}
	_, err := Stop(d, []Env{{Name: "a", Status: StatusRunning}}, []string{"a", "fremd"}, false)
	if err == nil || !strings.Contains(err.Error(), "fremd") || len(d.stopped) != 0 {
		t.Fatalf("err = %v, calls = %v", err, d.stopped)
	}
}

// A driver that stopped some sites and failed for others reports both.
func TestStopPassesPartialCount(t *testing.T) {
	d := &fakeDriver{n: 1, err: errors.New("boese wurde ohne ddev angehalten")}
	n, err := Stop(d, []Env{{Name: "a", Status: StatusRunning}, {Name: "boese", Status: StatusRunning}}, nil, true)
	if n != 1 || err == nil {
		t.Fatalf("Stop = %d, %v", n, err)
	}
}

func TestStatusLabel(t *testing.T) {
	cases := map[string]string{StatusRunning: "läuft", StatusPaused: "pausiert", StatusStopped: "gestoppt", StatusMissing: "nicht angelegt", "starting": "starting"}
	for in, want := range cases {
		if got := Label(in); got != want {
			t.Errorf("Label(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestWrapMarksLocalEnvErrors(t *testing.T) {
	if Wrap("x", nil) != nil {
		t.Fatal("Wrap(nil) must stay nil")
	}
	err := Wrap("container", errors.New("boom"))
	var le *Error
	if !errors.As(err, &le) || le.Op != "container" || err.Error() != "container: boom" {
		t.Fatalf("err = %v", err)
	}
}
