package pull

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/ddev"
	"github.com/usermind/wpsync/internal/sites"
)

const envTables = `[{"name":"wp_users","checksum":"1","anonymized":true}]`

// SEC-132: die drei Fälle aus AC-3 als /delta-env.
var invalidEnvCases = map[string]struct{ env, field string }{
	"table_prefix": {`{"table_prefix":"--path=/x","home":"https://kunde.example","anon":"1.abcd1234"}`, "table_prefix"},
	"home":         {`{"table_prefix":"wp_","home":"--exec=x","anon":"1.abcd1234"}`, "home"},
	"siteurl":      {`{"table_prefix":"wp_","home":"https://kunde.example","siteurl":"--exec=x","anon":"1.abcd1234"}`, "siteurl"},
	"php_version":  {`{"table_prefix":"wp_","home":"https://kunde.example","php_version":"8.3@sha256:x","anon":"1.abcd1234"}`, "php_version"},
}

func statusRoot(t *testing.T, url string) string {
	t.Helper()
	root := t.TempDir()
	base := baseline.New(url)
	base.Tables = map[string]string{"wp_users": "1"}
	if err := baseline.Save(filepath.Join(root, "kunde"), base); err != nil {
		t.Fatal(err)
	}
	return root
}

// SEC-132 AC-3: ungültiger Präfix, home oder siteurl bricht pull (ask=true) und status (ask=false)
// nach /delta ab; der Fake-Agent sieht nur /infosheet und /delta.
func TestPrepareRefusesInvalidEnv(t *testing.T) {
	for name, tc := range invalidEnvCases {
		for _, ask := range []bool{true, false} {
			t.Run(fmt.Sprintf("%s/ask=%v", name, ask), func(t *testing.T) {
				var data int
				srv := anonServer(t, tc.env, envTables, nil, &data)
				defer srv.Close()
				o := Options{Site: sites.Site{Name: "kunde", URL: srv.URL, Profile: anonProfile(t)}, Out: &bytes.Buffer{}}

				_, err := prepare(quickClient(srv.URL), &o, ask)
				if !errors.Is(err, agentapi.ErrInvalidEnv) {
					t.Fatalf("err = %v, want ErrInvalidEnv", err)
				}
				if errors.Is(err, ErrInvalidTableName) {
					t.Errorf("err also matches ErrInvalidTableName: %v", err)
				}
				if data != 0 {
					t.Errorf("%d requests besides infosheet and delta", data)
				}
				if !strings.Contains(err.Error(), tc.field+" = ") {
					t.Errorf("message misses field %s:\n%s", tc.field, err)
				}
			})
		}
	}
}

// SEC-132 AC-3 (status): dieselbe Ablehnung über den öffentlichen Einstieg.
func TestStatusRefusesInvalidEnv(t *testing.T) {
	for name, tc := range invalidEnvCases {
		t.Run(name, func(t *testing.T) {
			var data int
			srv := anonServer(t, tc.env, envTables, nil, &data)
			defer srv.Close()
			err := Status(Options{
				Site:      sites.Site{Name: "kunde", URL: srv.URL, KeyID: "0123456789abcdef", RPS: 1000, Profile: anonProfile(t)},
				Secret:    "secret",
				SitesRoot: statusRoot(t, srv.URL),
				Out:       &bytes.Buffer{},
			})
			if !errors.Is(err, agentapi.ErrInvalidEnv) {
				t.Fatalf("err = %v, want ErrInvalidEnv", err)
			}
			if data != 0 {
				t.Errorf("%d requests besides infosheet and delta", data)
			}
		})
	}
}

// SEC-132 AC-4: Feld, entschärfter und gekürzter Wert, deutscher Hinweis, alle Felder in einer
// Meldung, zusammen mit ungültigen Tabellennamen.
func TestInvalidEnvMessage(t *testing.T) {
	long := strings.Repeat("a", 100) + "-"
	cases := map[string]struct {
		env, tables string
		tableErr    bool
		want        []string
		notWant     []string
	}{
		"control chars and non-ascii": {
			env:     `{"table_prefix":"wp_\u001b[2J","home":"https://müller.example/\u0000","siteurl":"-x","anon":"1.abcd1234"}`,
			tables:  envTables,
			want:    []string{`table_prefix = "wp_\x1b[2J"`, `home = "https://m\u00fcller.example/\x00"`, `siteurl = "-x"`},
			notWant: []string{"müller"},
		},
		"truncated": {
			env:     `{"table_prefix":"` + long + `","home":"https://kunde.example","anon":"1.abcd1234"}`,
			tables:  envTables,
			want:    []string{`table_prefix = "` + strings.Repeat("a", 80) + `"…`},
			notWant: []string{strings.Repeat("a", 81)},
		},
		"with invalid table names": {
			env:      `{"table_prefix":"--path=/x","home":"--exec=x","anon":"1.abcd1234"}`,
			tables:   `[{"name":"wp_users","checksum":"1","anonymized":true},{"name":"../x","checksum":"2"}]`,
			tableErr: true,
			want:     []string{`table_prefix = "--path=/x"`, `home = "--exec=x"`, "die Site liefert Tabellennamen", `"../x"`},
		},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			var data int
			srv := anonServer(t, tc.env, tc.tables, nil, &data)
			defer srv.Close()
			o := Options{Site: sites.Site{Name: "kunde", URL: srv.URL, Profile: anonProfile(t)}, Out: &bytes.Buffer{}}

			_, err := prepare(quickClient(srv.URL), &o, true)
			if !errors.Is(err, agentapi.ErrInvalidEnv) {
				t.Fatalf("err = %v, want ErrInvalidEnv", err)
			}
			if errors.Is(err, ErrInvalidTableName) != tc.tableErr {
				t.Errorf("errors.Is(ErrInvalidTableName) = %v, want %v", !tc.tableErr, tc.tableErr)
			}
			msg := err.Error()
			for _, w := range append(tc.want, "die Site meldet Angaben in unzulässiger Form", "stammen von der Site", "läuft der Pull nicht") {
				if !strings.Contains(msg, w) {
					t.Errorf("message misses %q:\n%s", w, msg)
				}
			}
			for _, w := range tc.notWant {
				if strings.Contains(msg, w) {
					t.Errorf("message contains %q:\n%s", w, msg)
				}
			}
			for _, b := range []byte(msg) {
				if (b < 0x20 && b != '\n') || b == 0x7f {
					t.Fatalf("control byte %#x in message %q", b, msg)
				}
			}
		})
	}
}

// SEC-132 AC-5: gültiges home ohne bzw. mit leerer siteurl läuft durch.
func TestPrepareAllowsEmptySiteURL(t *testing.T) {
	for name, env := range map[string]string{
		"missing": `{"table_prefix":"wp_","home":"https://kunde.example","anon":"1.abcd1234"}`,
		"empty":   `{"table_prefix":"wp_","home":"https://kunde.example","siteurl":"","anon":"1.abcd1234"}`,
	} {
		t.Run(name, func(t *testing.T) {
			var data int
			srv := anonServer(t, env, envTables, nil, &data)
			defer srv.Close()
			o := Options{Site: sites.Site{Name: "kunde", URL: srv.URL, Profile: anonProfile(t)}, Out: &bytes.Buffer{}}
			if _, err := prepare(quickClient(srv.URL), &o, true); err != nil {
				t.Fatal(err)
			}
		})
	}
}

// snapshotTree records content and mtime of every file below root.
func snapshotTree(t *testing.T, root string) map[string]string {
	t.Helper()
	files := map[string]string{}
	err := filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		entry := info.Mode().String() + " " + info.ModTime().String()
		if d.Type().IsRegular() {
			data, err := os.ReadFile(p)
			if err != nil {
				return err
			}
			entry += " " + string(data)
		}
		files[p] = entry
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return files
}

// SEC-132 AC-6 (erster Pull): ddev- und docker-Skript im PATH protokollieren jeden Aufruf; nach dem
// Abbruch gibt es weder Aufrufe noch Dateien unter SitesRoot. Deshalb kein t.Parallel.
func TestRunWithInvalidEnvLeavesNoTrace(t *testing.T) {
	for name, tc := range invalidEnvCases {
		t.Run(name, func(t *testing.T) {
			ddevLog, dockerLog := fakeBin(t)
			var data int
			srv := anonServer(t, tc.env, envTables, nil, &data)
			defer srv.Close()
			root := t.TempDir()
			store, err := ddev.NewStore(t.TempDir(), root)
			if err != nil {
				t.Fatal(err)
			}
			err = Run(Options{
				Site:      sites.Site{Name: "kunde", URL: srv.URL, KeyID: "0123456789abcdef", RPS: 1000, Profile: anonProfile(t)},
				Secret:    "secret",
				SitesRoot: root,
				Driver:    &ddev.Driver{SitesRoot: root, State: store, Out: io.Discard},
				Yes:       true,
				Out:       &bytes.Buffer{},
			})
			if !errors.Is(err, agentapi.ErrInvalidEnv) {
				t.Fatalf("err = %v, want ErrInvalidEnv", err)
			}
			if calls := readCalls(ddevLog); len(calls) != 0 {
				t.Errorf("ddev called: %q", calls)
			}
			if calls := readCalls(dockerLog); len(calls) != 0 {
				t.Errorf("docker called: %q", calls)
			}
			if entries := sitesRootTrace(root, "kunde"); len(entries) != 0 {
				t.Errorf("SitesRoot not empty: %v", entries)
			}
			if _, err := os.Stat(store.Dir); err == nil {
				t.Error("state dir created")
			}
			if data != 0 {
				t.Errorf("%d requests besides infosheet and delta", data)
			}
		})
	}
}

// SEC-132 AC-6 (Folge-Pull): bei einer bereits gezogenen Site bleibt <site>/ unverändert.
func TestRunWithInvalidEnvKeepsPulledSite(t *testing.T) {
	f := pulledSite(t, "name: kunde\n", true)
	var data int
	srv := anonServer(t, invalidEnvCases["home"].env, envTables, nil, &data)
	defer srv.Close()
	f.opts.Site.URL = srv.URL
	before := snapshotTree(t, f.site)
	f.docker.calls = nil

	err := Run(f.opts)
	if !errors.Is(err, agentapi.ErrInvalidEnv) {
		t.Fatalf("err = %v, want ErrInvalidEnv", err)
	}
	if calls := readCalls(f.ddevLog); len(calls) != 0 {
		t.Errorf("ddev called: %q", calls)
	}
	if len(f.docker.calls) != 0 {
		t.Errorf("docker called: %q", f.docker.calls)
	}
	if after := snapshotTree(t, f.site); !reflect.DeepEqual(before, after) {
		t.Errorf("site changed:\nbefore %v\nafter  %v", before, after)
	}
	if data != 0 {
		t.Errorf("%d requests besides infosheet and delta", data)
	}
}

// SEC-132 AC-8: PostSetup bricht vor dem ersten Runner-Aufruf ab.
func TestPostSetupRefusesInvalidEnv(t *testing.T) {
	cases := map[string]agentapi.Env{
		"home":         {TablePrefix: "wp_", Home: "--exec=x"},
		"siteurl":      {TablePrefix: "wp_", Home: "https://kunde.example", SiteURL: "--exec=x"},
		"table_prefix": {TablePrefix: "--exec=x;//", Home: "https://kunde.example", SiteURL: "https://www.kunde.example"},
	}
	for name, env := range cases {
		t.Run(name, func(t *testing.T) {
			r := &fakeRunner{}
			var out bytes.Buffer
			err := PostSetup(r, env, "http://kunde.ddev.site", PostSetupOptions{LocalAdmin: true, ExcludedPlugins: []string{"elementor"}}, &out)
			if !errors.Is(err, agentapi.ErrInvalidEnv) {
				t.Fatalf("err = %v, want ErrInvalidEnv", err)
			}
			if !strings.Contains(err.Error(), name) {
				t.Errorf("message misses %s: %v", name, err)
			}
			if len(r.calls) != 0 {
				t.Errorf("runner called:\n%s", r.joined())
			}
		})
	}
}

// SEC-132 AC-9: gültige, verschiedene home/siteurl ergeben dieselben Aufrufe in derselben Reihenfolge
// wie vor der Prüfung (Stand 63c62f3).
func TestPostSetupCallsUnchangedForValidEnv(t *testing.T) {
	r := &fakeRunner{}
	env := agentapi.Env{TablePrefix: "djTui5D_", Home: "https://kunde.example", SiteURL: "https://kunde.example/wp"}
	if err := PostSetup(r, env, "http://kunde.ddev.site", PostSetupOptions{}, io.Discard); err != nil {
		t.Fatal(err)
	}
	const tail = " --all-tables-with-prefix --skip-columns=guid --report-changed-only --skip-plugins --skip-themes"
	want := strings.Join([]string{
		"wp search-replace https://kunde.example http://kunde.ddev.site" + tail,
		`wp search-replace https:\/\/kunde.example http:\/\/kunde.ddev.site` + tail,
		"wp search-replace https://kunde.example/wp http://kunde.ddev.site" + tail,
		`wp search-replace https:\/\/kunde.example\/wp http:\/\/kunde.ddev.site` + tail,
		"wp config set WP_ENVIRONMENT_TYPE local --type=constant",
		"wp config set DISABLE_WP_CRON true --raw --type=constant",
		"wp search-replace https://kunde.example http://kunde.ddev.site djTui5D_options --precise --report-changed-only",
		"wp search-replace https://kunde.example/wp http://kunde.ddev.site djTui5D_options --precise --report-changed-only",
	}, "\n")
	if got := r.joined(); got != want {
		t.Errorf("calls:\n%s\nwant:\n%s", got, want)
	}
}
