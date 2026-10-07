package push

import (
	"os"
	"path/filepath"
	"reflect"
	"testing"
)

// AC-119, C7: der Plan nennt zusätzlich lokal neue, nicht genannte Einheiten (skipped_new) und
// lokal fehlende, die auf der Site bleiben (missing_locally) – leer als [], nie null.
func TestPlanNamesSkippedAndMissingUnits(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	docroot := filepath.Join(siteDir, "public")
	write(t, docroot, "plugins/neu/neu.php", "<?php // new", 1800000000)
	if err := os.RemoveAll(filepath.Join(docroot, "wp-content", "themes", "t")); err != nil {
		t.Fatal(err)
	}
	o.DryRun = true
	var plan map[string]any
	o.Event = func(name string, data any) { plan, _ = data.(map[string]any) }
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(plan["skipped_new"], []string{"plugins/neu"}) || !reflect.DeepEqual(plan["missing_locally"], []string{"themes/t"}) {
		t.Errorf("plan = %v", plan)
	}

	f2 := newFakeSite(t)
	o2, _, _ := localSite(t, f2)
	o2.DryRun = true
	o2.Event = func(name string, data any) { plan, _ = data.(map[string]any) }
	if err := Run(o2); err != nil {
		t.Fatal(err)
	}
	if s, ok := plan["skipped_new"].([]string); !ok || s == nil || len(s) != 0 {
		t.Errorf("skipped_new = %#v", plan["skipped_new"])
	}
	if m, ok := plan["missing_locally"].([]string); !ok || m == nil || len(m) != 0 {
		t.Errorf("missing_locally = %#v", plan["missing_locally"])
	}
}
