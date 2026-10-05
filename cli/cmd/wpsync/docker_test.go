package main

import (
	"encoding/json"
	"errors"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/ddev"
)

// cmdDocker stands in for the docker CLI in trust and stop.
type cmdDocker struct {
	containers []ddev.Container
	calls      []string
}

func (d *cmdDocker) Output(args ...string) (string, error) {
	d.calls = append(d.calls, args[0])
	switch args[0] {
	case "ps":
		var ids []string
		for _, c := range d.containers {
			ids = append(ids, c.ID)
		}
		return strings.Join(ids, "\n"), nil
	case "inspect":
		var out []map[string]any
		for _, c := range d.containers {
			out = append(out, map[string]any{"Id": c.ID, "State": map[string]any{"Running": c.Running},
				"Config": map[string]any{"Labels": map[string]string{"com.docker.compose.service": c.Service}}, "Mounts": c.Mounts})
		}
		data, err := json.Marshal(out)
		return string(data), err
	case "stop":
		for i := range d.containers {
			d.containers[i].Running = false
		}
	case "rm":
		d.containers = nil
	}
	return "", nil
}

func useDocker(t *testing.T, d ddev.Docker) {
	t.Helper()
	old := dockerCLI
	dockerCLI = d
	t.Cleanup(func() { dockerCLI = old })
}

// writableWeb is a web container from before the hardening: .ddev is writable through /var/www/html.
func writableWeb(site string) ddev.Container {
	return ddev.Container{ID: "web1", Service: "web", Running: true, Mounts: []ddev.Mount{
		{Source: site, Destination: "/var/www/html", RW: true},
		{Source: filepath.Join(site, ".ddev"), Destination: "/mnt/ddev_config", RW: false},
	}}
}

// M3: wpsync trust stoppt Container, die .ddev noch beschreiben können, bevor es den Stand liest
// und anzeigt – sonst könnte sich .ddev zwischen Anzeige und Freigabe ändern.
func TestTrustStopsWritableContainersFirst(t *testing.T) {
	e := newTrustEnv(t)
	d := &cmdDocker{containers: []ddev.Container{writableWeb(e.site)}}
	useDocker(t, d)
	out, _ := runTrust(t, "kunde")
	if got := strings.Join(d.calls, " "); !strings.HasPrefix(got, "ps ps inspect stop rm") {
		t.Fatalf("docker calls = %s", got)
	}
	if !strings.Contains(out, "ohne ddev gestoppt") {
		t.Fatalf("output:\n%s", out)
	}
}

// M3: wpsync stop prüft neben .ddev auch die Container; kann einer .ddev noch beschreiben, geht
// die Site über Halt (docker) statt über ddev stop.
func TestStopGuardRefusesWritableContainers(t *testing.T) {
	e := newTrustEnv(t)
	out, _ := runTrust(t, "kunde")
	fp := regexpFingerprint(t, out)
	if _, err := runTrust(t, "kunde", "--fingerprint", fp); err != nil {
		t.Fatal(err)
	}
	g := &stopGuard{root: filepath.Dir(e.site), store: e.store}

	useDocker(t, &cmdDocker{})
	if err := g.Check("kunde"); err != nil {
		t.Fatalf("no containers: %v", err)
	}
	useDocker(t, &cmdDocker{containers: []ddev.Container{writableWeb(e.site)}})
	if err := g.Check("kunde"); !errors.Is(err, ddev.ErrNotHardened) {
		t.Fatalf("writable container: %v", err)
	}
}

func regexpFingerprint(t *testing.T, out string) string {
	t.Helper()
	for _, line := range strings.Split(out, "\n") {
		if fp, ok := strings.CutPrefix(line, "Fingerprint: "); ok {
			return fp
		}
	}
	t.Fatalf("no fingerprint in:\n%s", out)
	return ""
}
