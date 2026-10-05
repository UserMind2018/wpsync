package ddev

import (
	"errors"
	"strings"
	"testing"
)

// AC-5 / D4: nur ein Container, der .ddev nirgends beschreiben kann, gilt als gehärtet.
func TestHardened(t *testing.T) {
	const site = "/Users/u/wpsync-sites/kunde"
	ddevDir := site + "/.ddev"
	cases := []struct {
		name    string
		service string
		mounts  []Mount
		want    bool
	}{
		{"web gehärtet", "web", hardenedWeb(site), true},
		{"db gehärtet", "db", hardenedDB(site), true},
		{"web ohne Overlay (alter Stand)", "web", []Mount{
			{Source: site, Destination: "/var/www/html", RW: true},
			{Source: ddevDir, Destination: "/mnt/ddev_config", RW: false},
		}, false},
		{"web mit rw-Overlay", "web", []Mount{
			{Source: site, Destination: "/var/www/html", RW: true},
			{Source: ddevDir, Destination: "/var/www/html/.ddev", RW: true},
			{Source: ddevDir, Destination: "/mnt/ddev_config", RW: false},
		}, false},
		{"db mit rw /mnt/ddev_config (alter Stand)", "db", []Mount{
			{Source: ddevDir, Destination: "/mnt/ddev_config", RW: true},
			{Source: ddevDir + "/db_snapshots", Destination: "/mnt/snapshots", RW: true},
		}, false},
		{"Add-on mit rw .ddev/commands", "addon", []Mount{
			{Source: ddevDir + "/commands", Destination: "/cmds", RW: true},
		}, false},
		{"Add-on mit rw .ddev", "addon", []Mount{
			{Source: ddevDir, Destination: "/cfg", RW: true},
		}, false},
		{"Add-on mit rw Site-Ordner", "addon", []Mount{
			{Source: site, Destination: "/app", RW: true},
		}, false},
		{"Add-on mit rw Elternordner der Site", "addon", []Mount{
			{Source: "/Users/u/wpsync-sites", Destination: "/sites", RW: true},
		}, false},
		{"Add-on mit rw Site-Ordner und ro-Overlay", "addon", []Mount{
			{Source: site, Destination: "/app", RW: true},
			{Source: ddevDir, Destination: "/app/.ddev", RW: false},
		}, true},
		{"Add-on mit rw Site-Ordner, Overlay an falscher Stelle", "addon", []Mount{
			{Source: site, Destination: "/app", RW: true},
			{Source: ddevDir, Destination: "/var/www/html/.ddev", RW: false},
		}, false},
		{"Add-on mit ro .ddev", "addon", []Mount{
			{Source: ddevDir, Destination: "/cfg", RW: false},
		}, true},
		{"Add-on ohne Bezug zur Site", "addon", []Mount{
			{Source: "/Users/u/other", Destination: "/data", RW: true},
		}, true},
		{"db_snapshots rw ist erlaubt", "addon", []Mount{
			{Source: ddevDir + "/db_snapshots", Destination: "/snap", RW: true},
		}, true},
		{"Unterordner von db_snapshots ist nicht dasselbe", "addon", []Mount{
			{Source: ddevDir + "/db_snapshots/../commands", Destination: "/snap", RW: true},
		}, false},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := Hardened(Container{Service: c.service, Mounts: c.mounts}, site); got != c.want {
				t.Fatalf("Hardened = %v, want %v", got, c.want)
			}
		})
	}
}

// AC-5: laufende Container mit beschreibbarem .ddev werden per docker gestoppt und entfernt –
// ohne ddev, also ohne pre-stop-Hook.
func TestStopUnhardened(t *testing.T) {
	const site = "/Users/u/wpsync-sites/kunde"
	old := &fakeDocker{containers: []Container{
		{ID: "web1", Service: "web", Running: true, Mounts: []Mount{{Source: site, Destination: "/var/www/html", RW: true}}},
		{ID: "db1", Service: "db", Running: true, Mounts: []Mount{{Source: site + "/.ddev", Destination: "/mnt/ddev_config", RW: true}}},
		{ID: "x1", Service: "addon", Running: false},
	}}
	stopped, err := StopUnhardened(old, "kunde", site)
	if err != nil || !stopped {
		t.Fatalf("StopUnhardened = %v, %v", stopped, err)
	}
	want := []string{"ps -aq --filter label=com.ddev.site-name=kunde", "ps -aq --filter label=com.docker.compose.project=ddev-kunde", "inspect web1 db1 x1", "stop -t 30 web1 db1", "rm web1 db1 x1"}
	if got := argsOf(old.calls); strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("docker calls = %v", got)
	}

	hardened := hardenedDocker(site)
	if stopped, err := StopUnhardened(hardened, "kunde", site); err != nil || stopped {
		t.Fatalf("hardened: %v, %v", stopped, err)
	}
	if v := strings.Join(hardened.verbs(), " "); v != "ps ps inspect" {
		t.Fatalf("hardened docker calls = %s", v)
	}

	none := &fakeDocker{}
	if stopped, err := StopUnhardened(none, "kunde", site); err != nil || stopped {
		t.Fatalf("no containers: %v, %v", stopped, err)
	}
	if v := strings.Join(none.verbs(), " "); v != "ps ps" {
		t.Fatalf("no containers docker calls = %s", v)
	}
}

func TestVerifyHardened(t *testing.T) {
	const site = "/Users/u/wpsync-sites/kunde"
	if err := VerifyHardened(hardenedDocker(site), "kunde", site); err != nil {
		t.Fatal(err)
	}
	if err := VerifyHardened(&fakeDocker{}, "kunde", site); !errors.Is(err, ErrNotHardened) {
		t.Fatalf("no running containers: %v", err)
	}
	addon := hardenedDocker(site)
	addon.containers = append(addon.containers, Container{ID: "a1", Service: "addon", Running: true,
		Mounts: []Mount{{Source: site + "/.ddev", Destination: "/cfg", RW: true}}})
	err := VerifyHardened(addon, "kunde", site)
	if !errors.Is(err, ErrNotHardened) || !strings.Contains(err.Error(), "addon") {
		t.Fatalf("err = %v", err)
	}
	if v := strings.Join(addon.verbs(), " "); v != "ps ps inspect stop rm" {
		t.Fatalf("docker calls = %s", v)
	}
}

// StopContainers (wpsync stop für eine abweichende Site) hält nur laufende Container an.
func TestStopContainers(t *testing.T) {
	d := hardenedDocker("/s")
	d.containers[1].Running = false
	n, err := StopContainers(d, "kunde")
	if err != nil || n != 1 {
		t.Fatalf("StopContainers = %d, %v", n, err)
	}
	if got := argsOf(d.calls); got[len(got)-1] != "stop -t 30 web1" {
		t.Fatalf("docker calls = %v", got)
	}
}
