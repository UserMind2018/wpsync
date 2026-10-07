package push

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// AC-121, C2: ein Push nennt die Rescue-URL im Ergebnis, nie den Schlüssel oder den Salt.
func TestResultNamesTheRescueURL(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)
	var res Result
	o.Report = &res
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if res.RescueURL != f.srv.URL+"/rescue.php" {
		t.Errorf("rescue_url = %q", res.RescueURL)
	}
	data, _ := json.Marshal(res)
	if key := RescueKey("secret", testID, testSalt); strings.Contains(string(data), key) || strings.Contains(string(data), testSalt) {
		t.Errorf("key or salt in the result: %s", data)
	}
}

// Ein Probelauf hat keine Push-ID und darum keine Rescue-URL: das JSON bleibt wie in 0.4.0.
func TestDryRunHasNoRescueURL(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	o.DryRun = true
	var res Result
	o.Report = &res
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if data, _ := json.Marshal(res); strings.Contains(string(data), "rescue_url") {
		t.Errorf("result = %s", data)
	}
}

// AC-121: pushes nennt für Pushes mit Journal in diesem Site-Ordner journal:true und die URL, für
// fremde journal:false ohne URL; eine Rescue-URL ausserhalb der Site wird nicht weitergegeben.
func TestWithJournals(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	o, studio := containerLayout(t, o, siteDir)
	own := NewJournal(testID, f.srv.URL+"/rescue.php", testSalt, baseline.New(f.srv.URL), []string{"plugins/x"})
	if err := SaveJournal(studio, own); err != nil {
		t.Fatal(err)
	}
	const evilID = "p_20261007_eeeeeeeeeeee"
	if err := SaveJournal(studio, NewJournal(evilID, "https://evil.example/rescue.php", testSalt, baseline.New(f.srv.URL), nil)); err != nil {
		t.Fatal(err)
	}
	records := []agentapi.PushRecord{{PushID: testID, Target: "live"}, {PushID: "p_20261007_ffffffffffff", Target: "live"}, {PushID: evilID}, {PushID: "../x"}}
	entries, err := WithJournals(o, records)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 4 || !entries[0].Journal || entries[0].RescueURL != f.srv.URL+"/rescue.php" ||
		entries[1].Journal || entries[1].RescueURL != "" || !entries[2].Journal || entries[2].RescueURL != "" || entries[3].Journal {
		t.Fatalf("entries = %+v", entries)
	}
	data, _ := json.Marshal(entries)
	for _, want := range []string{`"push_id":"` + testID + `"`, `"journal":true`, `"journal":false`, `"target":"live"`} {
		if !strings.Contains(string(data), want) {
			t.Errorf("%s missing in %s", want, data)
		}
	}
	if strings.Contains(string(data), testSalt) || strings.Contains(string(data), "evil.example") {
		t.Errorf("salt or foreign URL in %s", data)
	}
}
