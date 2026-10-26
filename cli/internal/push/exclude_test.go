package push

import (
	"errors"
	"maps"
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/pathmatch"
	"github.com/usermind/wpsync/internal/profile"
)

// Spec 0.10.0 E3: an excluded file is neither new nor deleted – the baseline is not changed.
func TestScanLeavesOutExcludePaths(t *testing.T) {
	docroot := t.TempDir()
	base := baseline.New("https://kunde.de")
	pulled(t, docroot, base, "plugins/foo/foo.php", "<?php // v1")
	pulled(t, docroot, base, "plugins/foo/cache/x.bin", "cache")
	write(t, docroot, "plugins/foo/foo.php", "<?php // v2", 1800000000)
	write(t, docroot, "plugins/foo/cache/new.bin", "new cache", 1800000000)
	os.Remove(filepath.Join(docroot, "wp-content/plugins/foo/cache/x.bin"))
	before := maps.Clone(base.Files)

	units, _, err := Scan(docroot, base, pathmatch.MustCompile([]string{"plugins/foo/cache/"}))
	if err != nil {
		t.Fatal(err)
	}
	if len(units) != 1 || !slices.Equal(units[0].Changed, []string{"foo.php"}) {
		t.Fatalf("units = %+v", units)
	}
	if _, ok := units[0].Base["cache/x.bin"]; ok {
		t.Error("excluded file in the base sent to the agent")
	}
	if _, ok := units[0].Files["cache/new.bin"]; ok {
		t.Error("excluded file would be pushed")
	}
	if units[0].New {
		t.Error("unit counts as new")
	}
	if !reflect.DeepEqual(base.Files, before) {
		t.Error("Scan changed the baseline")
	}

	// Ohne Ausschluss: gelöscht und neu wie bisher.
	units, _, err = Scan(docroot, base, nil)
	if err != nil {
		t.Fatal(err)
	}
	if !slices.Equal(units[0].Changed, []string{"cache/new.bin", "cache/x.bin", "foo.php"}) {
		t.Errorf("without exclusions: %v", units[0].Changed)
	}
}

func excludeProfile(paths ...string) *profile.Profile {
	return &profile.Profile{Paths: profile.Paths{Exclude: paths}}
}

// AC-268: a file of --uploads below an exclude path stops the push before any request.
func TestRunRefusesExcludedUploads(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := uploadsSite(t, f)
	f.version = "0.10.0"
	write(t, filepath.Join(o.SitesRoot, "kunde", "public"), "uploads/videos/a.mp4", "video", 1800000000)
	o.Site.Profile = excludeProfile("uploads/videos/")
	o.Uploads = []string{"2026/10/neu.png", "videos/a.mp4"}
	err := Run(o)
	if !errors.Is(err, ErrUploadExcluded) || !strings.Contains(err.Error(), "wp-content/uploads/videos/a.mp4") {
		t.Fatalf("err = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("routes = %v", f.routes)
	}
}

// Abweichung 6: an agent before 0.10.0 would drop excluded files of a unit it replaces.
func TestRunRefusesOldAgentWhenExcludePathsTouchAUnit(t *testing.T) {
	cases := []struct {
		version string
		paths   []string
		wantErr bool
	}{
		{"0.9.1", []string{"*.mp4"}, true},
		{"0.9.1", []string{"plugins/x/cache/"}, true},
		{"0.9.1", []string{"uploads/videos/"}, false},
		{"0.10.0", []string{"*.mp4"}, false},
	}
	for _, c := range cases {
		t.Run(c.version+" "+c.paths[0], func(t *testing.T) {
			f := newFakeSite(t)
			f.version = c.version
			o, _, out := localSite(t, f)
			o.Site.Profile = excludeProfile(c.paths...)
			err := Run(o)
			if c.wantErr {
				if !errors.Is(err, ErrAgentNoExcludePaths) {
					t.Fatalf("err = %v\n%s", err, out)
				}
				if got := strings.Join(f.routes, " "); got != "begin" || f.committed {
					t.Errorf("routes = %s", got)
				}
				return
			}
			if err != nil {
				t.Fatalf("%v\n%s", err, out)
			}
			for _, b := range f.begins {
				if !slices.Equal(b.ExcludePaths, c.paths) {
					t.Errorf("begin exclude_paths = %q, want %q", b.ExcludePaths, c.paths)
				}
			}
		})
	}
}

// A broken stored list stops a push: it could otherwise delete what the profile protects.
func TestRunRefusesAnInvalidStoredList(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	o.Site.Profile = excludeProfile("plugins/x/")
	if err := Run(o); err == nil || !strings.Contains(err.Error(), "Ausschlüsse ungültig") {
		t.Fatalf("err = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("routes = %v", f.routes)
	}
}
