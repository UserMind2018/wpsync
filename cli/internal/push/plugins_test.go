package push

import (
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/baseline"
)

// AC-194, AC-206, Spec P4 §4.1: nur plugins/<slug>; alles andere ist ein falscher Aufruf (Exit 2).
func TestSwitchesAcceptOnlyPluginUnits(t *testing.T) {
	s, err := Options{Activate: []string{"wp-content/plugins/kunde/", "plugins/b"}, Deactivate: []string{"plugins/alt"}}.switches()
	if err != nil || !reflect.DeepEqual(s.Activate, []string{"plugins/kunde", "plugins/b"}) || !reflect.DeepEqual(s.Deactivate, []string{"plugins/alt"}) || !s.any() {
		t.Fatalf("switches = %+v, err = %v", s, err)
	}
	if s, err := (Options{}).switches(); err != nil || s.any() {
		t.Fatalf("no switches: %+v, %v", s, err)
	}
	// A20: Deaktivieren geht auch mit --no-code.
	if _, err := (Options{Deactivate: []string{"plugins/alt"}, NoCode: true}).switches(); err != nil {
		t.Errorf("--deactivate with --no-code: %v", err)
	}
	many := make([]string, 21)
	for i := range many {
		many[i] = fmt.Sprintf("plugins/p%d", i)
	}
	for name, o := range map[string]Options{
		"theme":            {Activate: []string{"themes/x"}},
		"mu-plugins":       {Deactivate: []string{"mu-plugins"}},
		"uploads":          {Activate: []string{"uploads"}},
		"agent":            {Deactivate: []string{"plugins/wpsync-agent"}},
		"agent, case":      {Activate: []string{"plugins/WPSync-Agent"}},
		"path":             {Activate: []string{"plugins/a/b"}},
		"single file":      {Deactivate: []string{"plugins/hello.php/x"}},
		"empty":            {Activate: []string{""}},
		"too many":         {Deactivate: many},
		"twice":            {Activate: []string{"plugins/a", "plugins/a"}},
		"twice, case":      {Deactivate: []string{"plugins/a", "plugins/A"}},
		"in both":          {Activate: []string{"plugins/a"}, Deactivate: []string{"wp-content/plugins/a"}},
		"activate no-code": {Activate: []string{"plugins/a"}, NoCode: true},
	} {
		if _, err := o.switches(); !errors.Is(err, ErrPluginSwitch) {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}

// A3, AC-194: eine Einheit aus --activate kommt immer in den Satz – unverändert, neu oder ohnehin geändert.
func TestWithActivatedForcesUnitsIntoTheSet(t *testing.T) {
	docroot := filepath.Join(t.TempDir(), "public")
	base := baseline.New("https://kunde.example")
	pulled(t, docroot, base, "plugins/same/same.php", "<?php\n/* Plugin Name: Same */")
	pulled(t, docroot, base, "plugins/same/inc/a.php", "<?php // a")
	pulled(t, docroot, base, "plugins/x/main.php", "<?php // x")
	write(t, docroot, "plugins/x/main.php", "<?php // x edited", 1800000000)
	write(t, docroot, "plugins/neu/neu.php", "<?php\n/* Plugin Name: Neu */", 1800000000)
	if err := os.MkdirAll(filepath.Join(docroot, "wp-content", "plugins", "leer"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(docroot, "wp-content", "plugins", "x"), filepath.Join(docroot, "wp-content", "plugins", "link")); err != nil {
		t.Fatal(err)
	}
	all, _, links, err := scan(docroot, base)
	if err != nil {
		t.Fatal(err)
	}
	named, err := selectUnits(all, []string{"plugins/x"}, io.Discard)
	if err != nil || len(named) != 1 {
		t.Fatalf("named = %+v, err = %v", named, err)
	}

	got, err := withActivated(docroot, base, named, all, []string{"plugins/same", "plugins/neu", "plugins/x"}, links)
	if err != nil {
		t.Fatal(err)
	}
	var paths []string
	for _, u := range got {
		paths = append(paths, u.Path)
	}
	if !reflect.DeepEqual(paths, []string{"plugins/neu", "plugins/same", "plugins/x"}) {
		t.Fatalf("units = %v", paths)
	}
	if same := find(got, "plugins/same"); same.New || len(same.Changed) != 0 || len(same.Files) != 2 || len(same.Base) != 2 {
		t.Errorf("the unchanged unit = %+v", same)
	}
	if neu := find(got, "plugins/neu"); !neu.New || len(neu.Files) != 1 {
		t.Errorf("the new unit = %+v", neu)
	}

	for name, unit := range map[string]string{"missing": "plugins/fehlt", "empty": "plugins/leer", "symlink": "plugins/link"} {
		_, err := withActivated(docroot, base, nil, all, []string{unit}, links)
		if !errors.Is(err, ErrPluginSwitch) || !strings.Contains(err.Error(), unit) {
			t.Errorf("%s: err = %v", name, err)
		}
	}
	if _, err := withActivated(docroot, base, nil, all, []string{"plugins/link"}, links); !errors.Is(err, ErrSymlink) {
		t.Errorf("a symlink is named as one: %v", err)
	}
	// Ohne --activate ändert sich nichts.
	if got, err := withActivated(docroot, base, named, all, nil, links); err != nil || len(got) != 1 {
		t.Errorf("without switches: %+v, %v", got, err)
	}
}

// A10, §11 „Köpfe“ und „Hinweise“: die ersten 8192 Bytes jeder *.php direkt im Ordner mit „Plugin Name:“,
// höchstens fünf – und ob irgendwo eine Aktivierungsroutine registriert wird.
func TestPluginHeadsReadsWhatTheAgentChecks(t *testing.T) {
	docroot := filepath.Join(t.TempDir(), "public")
	head := "<?php\n/**\n * Plugin Name: Kunde\n * Version: 1.2.0\n */\n"
	write(t, docroot, "plugins/kunde/kunde.php", head+strings.Repeat("// x\n", 4000), 1800000000)
	write(t, docroot, "plugins/kunde/uninstall.php", "<?php // nichts", 1800000000)
	write(t, docroot, "plugins/kunde/late.php", "<?php\n"+strings.Repeat("\n", 9000)+"/* Plugin Name: Zu spät */", 1800000000)
	write(t, docroot, "plugins/kunde/inc/setup.php", "<?php\n/* Plugin Name: Im Unterordner */\nregister_activation_hook(__FILE__, 'kunde_on');", 1800000000)
	write(t, docroot, "plugins/kunde/readme.txt", "Plugin Name: Kunde", 1800000000)
	u, err := scanUnit(docroot, "plugins/kunde", nil)
	if err != nil {
		t.Fatal(err)
	}
	heads, hook, err := pluginHeads(docroot, &u)
	if err != nil {
		t.Fatal(err)
	}
	if len(heads) != 1 || len(heads["kunde.php"]) != 8192 || !strings.HasPrefix(string(heads["kunde.php"]), head) {
		t.Errorf("heads = %v", keysOf(heads))
	}
	if !hook {
		t.Error("register_activation_hook in inc/setup.php was not seen")
	}

	// Kein Kopf: eine leere Menge (der Agent sagt dann schon im Probelauf no_plugin_file), kein Hinweis.
	write(t, docroot, "plugins/ohne/ohne.php", "<?php // kein Kopf", 1800000000)
	u, _ = scanUnit(docroot, "plugins/ohne", nil)
	if heads, hook, err := pluginHeads(docroot, &u); err != nil || heads == nil || len(heads) != 0 || hook {
		t.Errorf("without a header: %v, %v, %v", heads, hook, err)
	}

	// Mehr als fünf Köpfe oder ein Dateiname, den der Agent nicht annähme: keiner wird geschickt – die Prüfung läuft im Commit.
	for i := 0; i < 6; i++ {
		write(t, docroot, fmt.Sprintf("plugins/viele/p%d.php", i), head, 1800000000)
	}
	u, _ = scanUnit(docroot, "plugins/viele", nil)
	if heads, _, err := pluginHeads(docroot, &u); err != nil || heads != nil {
		t.Errorf("six headers: %v, %v", keysOf(heads), err)
	}
	write(t, docroot, "plugins/name/mein plugin.php", head, 1800000000)
	u, _ = scanUnit(docroot, "plugins/name", nil)
	if heads, _, err := pluginHeads(docroot, &u); err != nil || heads != nil {
		t.Errorf("an odd file name: %v, %v", keysOf(heads), err)
	}
}

func keysOf(m map[string][]byte) []string {
	var out []string
	for k := range m {
		out = append(out, k)
	}
	return out
}
