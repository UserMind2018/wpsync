package pull

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
)

// Spec Server-Modus §4: Fortschritt je Phase, am Ende eine Zusammenfassung.
func TestRunReportsPhasesAndResult(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(false))
	var events []string
	o.Progress = func(p Progress) { events = append(events, progressLine(p)) }
	var res Result
	o.Report = &res

	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	// W2: files and db_download carry bytes (10 = the file, 40 = the site's estimate of
	// wp_options, whatever its SQL weighs); the other phases stay as they were.
	want := "delta 1/1,setup 1/1,files 0/1 bytes 0/10,files 1/1 bytes 10/10,db_download 1/1 bytes 40/40 wp_options,db_import 1/1,postsetup 1/1,mailguard 1/1"
	if got := strings.Join(events, ","); got != want {
		t.Errorf("events = %s\nwant     %s", got, want)
	}
	if !res.FirstPull || res.FilesChanged != 1 || res.TablesLoaded != 1 || res.TablesTotal != 1 ||
		res.LocalURL != "http://kunde.local" || res.AgentVersion != "0.3.1" || res.LocalAdminUser != LocalAdminUser || res.Requests == 0 {
		t.Errorf("result = %+v", res)
	}
}

// progressLine renders a phase event for comparisons: "<phase> <done>/<total>[ bytes <done>/<total>][ <table>]".
func progressLine(p Progress) string {
	line := fmt.Sprintf("%s %d/%d", p.Phase, p.Done, p.Total)
	if p.Bytes {
		line += fmt.Sprintf(" bytes %d/%d", p.BytesDone, p.BytesTotal)
	}
	if p.Table != "" {
		line += " " + p.Table
	}
	return line
}

// W2: ein Folge-Pull ohne Änderung lädt nichts – die Datei-Phase meldet 0 von 0 Bytes, eine
// db_download-Phase gibt es nicht (unveränderte Tabellen zählen in keinem der beiden Felder).
func TestRunFollowUpPullReportsNoBytes(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	o := pullOptions(t, srv.URL, newFakeDriver(true))
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	var events []string
	o.Progress = func(p Progress) { events = append(events, progressLine(p)) }
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	want := "delta 1/1,setup 1/1,files 0/0 bytes 0/0,mailguard 1/1"
	if got := strings.Join(events, ","); got != want {
		t.Errorf("events = %s\nwant     %s", got, want)
	}
}

// SIGTERM: der Pull endet mit ErrInterrupted; was schon da ist, bleibt, der nächste Pull läuft durch.
func TestRunInterruptedAndResumed(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	srv := agentServer(t, func(route string) {
		if route == "/wpsync/v1/files" {
			cancel()
		}
	})
	defer srv.Close()
	drv := newFakeDriver(false)
	o := pullOptions(t, srv.URL, drv)
	o.Ctx = ctx

	err := Run(o)
	if !errors.Is(err, ErrInterrupted) {
		t.Fatalf("err = %v, want ErrInterrupted", err)
	}
	if strings.Contains(drv.runner.joined(), "mysql") {
		t.Fatal("an interrupted pull must not import the database")
	}

	o.Ctx = context.Background()
	if err := Run(o); err != nil {
		t.Fatalf("resumed pull: %v", err)
	}
}

func TestRunMarksPostSetupFailures(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	drv := newFakeDriver(false)
	drv.runner.output = "missing"
	o := pullOptions(t, srv.URL, drv)

	err := Run(o)
	var ps *PostSetupError
	if !errors.As(err, &ps) || !errors.Is(err, ErrMailguardMissing) {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(strings.Join(drv.calls, ","), "stop kunde") {
		t.Errorf("site must be stopped without mailguard, calls = %v", drv.calls)
	}
}

// Fehler des Laufzeittreibers sind local_env, nicht unknown.
func TestRunMarksDriverFailures(t *testing.T) {
	srv := agentServer(t, nil)
	defer srv.Close()
	drv := &failingDriver{fakeDriver: newFakeDriver(false)}
	o := pullOptions(t, srv.URL, drv)

	err := Run(o)
	var le *localenv.Error
	if !errors.As(err, &le) || le.Op != "exists" {
		t.Fatalf("err = %v", err)
	}
}

type failingDriver struct{ *fakeDriver }

func (f *failingDriver) Exists(string) (bool, error) {
	return false, errors.New("container ws-x fehlt")
}

// AC-36 mit Versionen: der veraltete Agent nennt installierte und nötige Version.
func TestPrepareNamesAgentVersions(t *testing.T) {
	var data int
	srv := anonServer(t, `{"table_prefix":"wp_","home":"https://kunde.example","agent_version":"0.2.0"}`, `[{"name":"wp_users","checksum":"1"}]`, nil, &data)
	defer srv.Close()
	o := Options{Site: pullOptions(t, srv.URL, nil).Site, Out: &strings.Builder{}}
	o.Site.Profile = anonProfile(t)

	_, err := prepare(quickClient(srv.URL), &o, true)
	var out *agentapi.OutdatedError
	if !errors.As(err, &out) || out.Installed != "0.2.0" || out.Required != agentapi.MinAgentVersion {
		t.Fatalf("err = %v", err)
	}
}

func TestPrepareWithoutTerminalNeedsConfirmation(t *testing.T) {
	srv := prepareServer(t, "wp_new_log", nil)
	defer srv.Close()
	o := Options{Site: pullOptions(t, srv.URL, nil).Site, Out: &strings.Builder{}}
	o.Site.Profile = prepareProfile(t)
	if _, err := prepare(quickClient(srv.URL), &o, true); !errors.Is(err, ErrNeedsConfirmation) {
		t.Fatalf("err = %v", err)
	}
}

// Container-Modus: ohne Uploads-Proxy darf das Profil keine Upload-Jahre auslassen (Spec §3).
func TestRunRefusesUploadsSinceWithoutProxy(t *testing.T) {
	o := pullOptions(t, "http://127.0.0.1:1", newFakeDriver(false))
	o.Site.Profile.Uploads.Since = "2024"
	if err := Run(o); !errors.Is(err, ErrUploadsWithoutProxy) || !strings.Contains(err.Error(), "--uploads-since alle") {
		t.Fatalf("err = %v", err)
	}
}
