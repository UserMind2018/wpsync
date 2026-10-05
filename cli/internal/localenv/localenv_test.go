package localenv

import (
	"errors"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/sites"
)

const ddevList = `{"level":"info","msg":"","raw":[
 {"name":"kunde-a","status":"running","approot":"/home/u/wpsync-sites/kunde-a","httpsurl":"https://kunde-a.ddev.site:8443","primary_url":"http://kunde-a.ddev.site:8480"},
 {"name":"alt","status":"paused","approot":"/home/u/wpsync-sites/alt","httpsurl":"https://alt.ddev.site:8443"},
 {"name":"fremd","status":"running","approot":"/home/u/projekte/fremd","httpsurl":"https://fremd.ddev.site"},
 {"name":"praefix","status":"running","approot":"/home/u/wpsync-sites-other/praefix","httpsurl":"https://praefix.ddev.site"}
]}`

type fakeDDEV struct {
	out     string
	err     error
	stopped [][]string
}

func (f *fakeDDEV) Output(args ...string) (string, error) { return f.out, f.err }
func (f *fakeDDEV) Run(args ...string) error {
	f.stopped = append(f.stopped, args)
	return nil
}

func TestListMergesProjectsAndPairedSites(t *testing.T) {
	paired := []sites.Site{
		{Name: "kunde-a", URL: "https://www.kunde-a.de"},
		{Name: "neu", URL: "https://neu.de"},
	}
	got, err := List(&fakeDDEV{out: ddevList}, "/home/u/wpsync-sites", paired)
	if err != nil {
		t.Fatal(err)
	}
	want := []Env{
		{Name: "alt", Status: StatusPaused, LocalURL: "https://alt.ddev.site:8443"},
		{Name: "kunde-a", Status: StatusRunning, LocalURL: "https://kunde-a.ddev.site:8443", LiveURL: "https://www.kunde-a.de"},
		{Name: "neu", Status: StatusMissing, LiveURL: "https://neu.de"},
	}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("List =\n%+v\nwant\n%+v", got, want)
	}
}

func TestListWithoutProjects(t *testing.T) {
	for _, out := range []string{`{"raw":null}`, `{"raw":[]}`} {
		got, err := List(&fakeDDEV{out: out}, "/r", nil)
		if err != nil || len(got) != 0 {
			t.Fatalf("List(%s) = %v, %v", out, got, err)
		}
	}
}

func TestListReportsDDEVError(t *testing.T) {
	if _, err := List(&fakeDDEV{err: errors.New("boom")}, "/r", nil); err == nil {
		t.Fatal("ddev failure must be reported")
	}
}

func TestStopSelected(t *testing.T) {
	envs := []Env{{Name: "a", Status: StatusRunning}, {Name: "b", Status: StatusPaused}, {Name: "c", Status: StatusStopped}}
	d := &fakeDDEV{}
	n, err := Stop(d, envs, []string{"a", "b", "c"}, false, nil)
	if err != nil || n != 2 {
		t.Fatalf("Stop = %d, %v", n, err)
	}
	if !reflect.DeepEqual(d.stopped, [][]string{{"stop", "a", "b"}}) {
		t.Fatalf("ddev calls = %v", d.stopped)
	}
}

func TestStopAllOnlyRunning(t *testing.T) {
	envs := []Env{{Name: "a", Status: StatusRunning}, {Name: "b", Status: StatusPaused}, {Name: "c", Status: StatusStopped}, {Name: "d", Status: StatusMissing}}
	d := &fakeDDEV{}
	n, err := Stop(d, envs, nil, true, nil)
	if err != nil || n != 1 {
		t.Fatalf("Stop = %d, %v", n, err)
	}
	if !reflect.DeepEqual(d.stopped, [][]string{{"stop", "a"}}) {
		t.Fatalf("ddev calls = %v", d.stopped)
	}
}

func TestStopUnknownSiteFailsBeforeStopping(t *testing.T) {
	d := &fakeDDEV{}
	_, err := Stop(d, []Env{{Name: "a", Status: StatusRunning}}, []string{"a", "fremd"}, false, nil)
	if err == nil || !strings.Contains(err.Error(), "fremd") || len(d.stopped) != 0 {
		t.Fatalf("err = %v, calls = %v", err, d.stopped)
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
