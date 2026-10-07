package push

import (
	"bytes"
	"errors"
	"net/http"
	"net/http/cookiejar"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/staging"
)

const stagingID = "p_20261006_aaaaaaaaaaaa"

// stagingSite is a fake site with agent 0.5.0 and options for a push to its staging copy.
func stagingSite(t *testing.T) (*fakeSite, Options, string, *bytes.Buffer) {
	t.Helper()
	f := newFakeSite(t)
	f.version = "0.5.0"
	o, siteDir, out := localSite(t, f)
	o.Target = TargetStaging
	return f, o, siteDir, out
}

// outsideCopy lists the frontend requests that did not go into the staging copy.
func outsideCopy(f *fakeSite) []string {
	var out []string
	for _, c := range f.cookies {
		if !strings.HasPrefix(c, testStaging+"/") {
			out = append(out, c)
		}
	}
	return out
}

// Spec 2b 6.2, AC-97, AC-98, V20
func TestRunToStagingKeepsTheBaselineAndUsesTheAccessCookie(t *testing.T) {
	f, o, siteDir, out := stagingSite(t)
	commits := 0
	o.Commit = func(string, string) error { commits++; return nil }
	var res Result
	o.Report = &res
	before, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json"))

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if f.begins[0].Target != TargetStaging || f.begins[1].Target != TargetStaging {
		t.Errorf("begins = %+v", f.begins)
	}
	after, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if !bytes.Equal(before, after) || commits != 0 {
		t.Errorf("AC-98: baseline changed or committed (%d) by a push to staging", commits)
	}
	if f.stagingHits == 0 {
		t.Error("the health check never reached the copy with the access cookie")
	}
	if f.logins != 1 {
		t.Errorf("%d login links used, a push redeems exactly one", f.logins)
	}
	j, err := LoadJournal(siteDir, testID)
	if err != nil || j.Target != TargetStaging || j.Applied {
		t.Errorf("journal = %+v, %v", j, err)
	}
	if res.PushID != testID || res.Target != TargetStaging || res.Status != "confirmed" || strings.Join(res.Units, ",") != "plugins/x" {
		t.Errorf("result = %+v", res)
	}
	if !strings.Contains(out.String(), "wpsync push kunde code plugins/x") {
		t.Errorf("no hint for the live push:\n%s", out)
	}
	// V20: nothing but the access cookie, and never outside the copy
	if got := outsideCopy(f); len(got) != 0 {
		t.Errorf("health check of a staging push left the copy: %q", got)
	}
	for _, c := range f.cookies {
		if strings.Contains(c, "wordpress") {
			t.Errorf("an admin session cookie travelled: %q", c)
		}
	}
}

// The copy keeps no .htaccess with rewrite directives (it would lift the access gate of its folder):
// the agent leaves it out of a push to staging, and the push says so instead of claiming the copy
// equals the local state.
func TestRunToStagingNamesWhatTheCopyLeavesOut(t *testing.T) {
	f, o, siteDir, out := stagingSite(t)
	write(t, filepath.Join(siteDir, "public"), "plugins/x/sub/.htaccess", "RewriteEngine On\n", 1800000000)
	f.leftOut = []string{"plugins/x/sub/.htaccess"}
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !strings.Contains(out.String(), "nicht in die Kopie übernommen: plugins/x/sub/.htaccess") || !strings.Contains(out.String(), "Zugangssperre") {
		t.Errorf("no note about the file left out:\n%s", out)
	}
	if strings.Contains(out.String(), "main.php (") {
		t.Errorf("a placed file is named as left out:\n%s", out)
	}

	// Nothing to say when the agent placed every file.
	f, o, _, out = stagingSite(t)
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if strings.Contains(out.String(), "nicht in die Kopie übernommen") {
		t.Errorf("note without a file left out:\n%s", out)
	}
}

// AC-98: the push to live after the test on staging uploads the same state and is checked against live.
func TestPushToLiveAfterStagingUploadsEverythingAgain(t *testing.T) {
	f, o, siteDir, out := stagingSite(t)
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	o.Target = ""
	var commits []string
	o.Commit = func(_, msg string) error { commits = append(commits, msg); return nil }
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	live := f.begins[3]
	if live.Target != TargetLive || live.Dry || len(live.Units) != 1 || live.Units[0].Path != "plugins/x" {
		t.Fatalf("live begin = %+v", live)
	}
	if got := live.Units[0].Base["main.php"].MTime; got != 1700000000 {
		t.Errorf("conflict base of the live push = %d, want the stamp of the last pull", got)
	}
	if !strings.HasSuffix(f.uploaded["main.php"], "// edited") || len(commits) != 1 {
		t.Errorf("uploaded = %v, commits = %v", f.uploaded, commits)
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1800000000 {
		t.Errorf("baseline after the live push = %v", base.Files)
	}
}

// AC-99
func TestBrokenStagingPushIsRolledBack(t *testing.T) {
	f, o, _, out := stagingSite(t)
	f.broken = true
	var res Result
	o.Report = &res
	var rolled *RolledBackError
	if err := Run(o); !errors.As(err, &rolled) || !f.rolledBack {
		t.Fatalf("err = %v, rolled back = %v\n%s", err, f.rolledBack, out)
	}
	if f.rescueKey != RescueKey("secret", testID, testSalt) {
		t.Error("rescue.php got another key than for a push to live")
	}
	if res.Status != "rolled_back" || res.Target != TargetStaging || res.PushID != testID {
		t.Errorf("result = %+v", res)
	}
	if got := outsideCopy(f); len(got) != 0 {
		t.Errorf("health check left the copy: %q", got)
	}
}

func TestFailedRollbackIsNotReportedAsRolledBack(t *testing.T) {
	f, o, _, _ := stagingSite(t)
	f.broken, f.rescue = true, 500
	var res Result
	o.Report = &res
	err := Run(o)
	if err == nil || !strings.Contains(err.Error(), "ROLLBACK FEHLGESCHLAGEN") || !strings.Contains(err.Error(), "Staging") {
		t.Fatalf("err = %v", err)
	}
	if res.Status != "committed" {
		t.Errorf("status = %q, the push is still swapped in", res.Status)
	}
}

func TestRunToStagingNeedsAgent050(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	o.Target = TargetStaging
	if err := Run(o); !errors.Is(err, ErrAgentNoStaging) {
		t.Errorf("err = %v", err)
	}
	f.beginCode = "wpsync_push_target" // what agent 0.4.0 really answers
	if err := Run(o); !errors.Is(err, ErrAgentNoStaging) {
		t.Errorf("err = %v", err)
	}
	if f.logins != 0 || strings.Contains(strings.Join(f.routes, " "), "upload") {
		t.Errorf("routes = %v", f.routes)
	}
}

func TestRefusalsOfAStagingPushKeepTheirReason(t *testing.T) {
	for code, want := range map[string]error{
		"wpsync_staging_missing": staging.ErrMissing,
		"wpsync_staging_locked":  staging.ErrLocked,
		"wpsync_staging_busy":    staging.ErrBusy,
	} {
		f, o, _, _ := stagingSite(t)
		f.beginCode = code
		err := Run(o)
		var apiErr *agentapi.APIError
		if !errors.Is(err, want) || !errors.As(err, &apiErr) {
			t.Errorf("%s: err = %v", code, err)
		}
		if f.logins != 0 {
			t.Errorf("%s: a refused push must not unlock the copy", code)
		}
	}
}

// The agent decides where the files land. An answer for another target stops the push.
func TestRunStopsWhenTheAgentAnswersForAnotherTarget(t *testing.T) {
	f, o, siteDir, _ := stagingSite(t)
	f.answerFor = TargetLive
	if err := Run(o); !errors.Is(err, ErrTargetMismatch) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}

	f, o, siteDir, _ = stagingSite(t)
	f.realFor = TargetLive // the plan was for staging, the push is not
	var res Result
	o.Report = &res
	if err := Run(o); !errors.Is(err, ErrTargetMismatch) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); strings.Contains(got, "upload") || strings.Contains(got, "commit") {
		t.Errorf("routes = %s", got)
	}
	if _, err := LoadJournal(siteDir, testID); err == nil || res.Status != "" {
		t.Errorf("journal written or status %q for a push that never started", res.Status)
	}

	f = newFakeSite(t)
	f.version, f.answerFor = "0.5.0", TargetStaging
	o, _, _ = localSite(t, f)
	if err := Run(o); !errors.Is(err, ErrTargetMismatch) {
		t.Fatalf("live push answered for staging: err = %v", err)
	}
}

// A typo in --to must never mean live.
func TestUnknownTargetIsRefused(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	for _, target := range []string{"stagin", "Staging", "LIVE", " "} {
		o.Target = target
		if err := Run(o); !errors.Is(err, ErrTarget) {
			t.Errorf("Run with %q: %v", target, err)
		}
		if err := Rollback(o, testID); !errors.Is(err, ErrTarget) {
			t.Errorf("Rollback with %q: %v", target, err)
		}
	}
	if len(f.routes) != 0 {
		t.Errorf("routes = %v", f.routes)
	}
}

// T4
func TestRunReportsEvents(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)
	var names []string
	o.Event = func(name string, _ any) { names = append(names, name) }
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(names, " "); got != "plan upload commit health" {
		t.Errorf("events = %s", got)
	}
}

func TestDryRunReportsThePlan(t *testing.T) {
	_, o, _, _ := stagingSite(t)
	o.DryRun = true
	var res Result
	var plan map[string]any
	o.Report = &res
	o.Event = func(name string, data any) {
		if name != "plan" {
			t.Errorf("event %s in a dry run", name)
		}
		plan, _ = data.(map[string]any)
	}
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if res.Status != "dry_run" || res.PushID != "" || res.Target != TargetStaging || len(res.Units) != 1 {
		t.Errorf("result = %+v", res)
	}
	units, _ := plan["units"].([]map[string]any)
	if plan["target"] != TargetStaging || len(units) != 1 || units[0]["path"] != "plugins/x" || units[0]["upload"] != 1 {
		t.Errorf("plan = %v", plan)
	}
}

// AC-104: without a terminal (and with --json) nobody is asked – for live as for staging.
func TestRunWithoutTerminalNeedsYes(t *testing.T) {
	for _, target := range []string{"", TargetStaging} {
		f := newFakeSite(t)
		f.version = "0.5.0"
		o, _, _ := localSite(t, f)
		o.Yes, o.Target = false, target
		if err := Run(o); !errors.Is(err, ErrNeedsYes) {
			t.Errorf("target %q: err = %v", target, err)
		}
		if got := strings.Join(f.routes, " "); got != "begin" || f.logins != 0 {
			t.Errorf("target %q: routes = %s", target, got)
		}
	}
}

func TestRunToStagingAsksLikeLive(t *testing.T) {
	f, o, _, _ := stagingSite(t)
	o.Yes = false
	var asked []string
	o.Confirm = func(q string) bool { asked = append(asked, q); return false }
	if err := Run(o); !errors.Is(err, ErrAborted) {
		t.Fatalf("err = %v", err)
	}
	if len(asked) != 1 || !strings.Contains(asked[0], "Staging-Kopie") || f.logins != 0 {
		t.Errorf("asked = %q, logins = %d", asked, f.logins)
	}
}

// V20: the cookie of the copy reaches neither live nor another host, whatever the copy answers
// and whatever the agent names.
func TestStagingHealthCheckNeverLeavesTheCopy(t *testing.T) {
	f, o, _, out := stagingSite(t)
	f.redirect = f.srv.URL + "/wp-admin/"
	f.stgHealth = []string{f.srv.URL + "/", f.srv.URL + "/wp-admin/", "https://evil.example" + testStaging + "/",
		f.srv.URL + testStaging + "/../wp-admin/", f.srv.URL + testStaging + "/%2e%2e/wp-admin/", f.srv.URL + testStaging + "-other/"}
	jar, _ := cookiejar.New(nil)
	withJar := *f.srv.Client()
	withJar.Jar = jar
	o.HTTP = &withJar

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := outsideCopy(f); len(got) != 0 {
		t.Errorf("requests outside the copy: %q", got)
	}
	if strings.Count(out.String(), "verworfen") != len(f.stgHealth) {
		t.Errorf("dropped pages:\n%s", out)
	}
	site, _ := url.Parse(f.srv.URL + testStaging + "/")
	if got := jar.Cookies(site); len(got) != 0 {
		t.Errorf("the cookie jar of the caller was filled: %v", got)
	}
}

func TestStagingHealthCheckFollowsRedirectsInsideTheCopy(t *testing.T) {
	f, o, _, out := stagingSite(t)
	f.redirect = f.srv.URL + testStaging + "/de/"
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	want := testStaging + "/de/ wpsync_stg=" + strings.Repeat("c", 64)
	found := false
	for _, c := range f.cookies {
		found = found || c == want
	}
	if !found {
		t.Errorf("no request %q in %q", want, f.cookies)
	}
}

// The staging URL comes from the agent. Anything but a staging folder on the paired site would
// send the cookie to pages of live.
func TestAccessOutsideAStagingFolderStopsThePush(t *testing.T) {
	cookie := &http.Cookie{Name: "wpsync_stg", Value: "v"}
	for _, base := range []string{"", "/", "/wp-content", "/wpsync-staging-0123", testStaging + "/..", testStaging + "?x=1", "https://evil.example" + testStaging} {
		f, o, _, _ := stagingSite(t)
		if strings.HasPrefix(base, "/") || base == "" {
			base = f.srv.URL + base
		}
		o.Access = func(*agentapi.Client, *http.Client, string) (string, *http.Cookie, error) { return base, cookie, nil }
		if err := Run(o); !errors.Is(err, agentapi.ErrForeignURL) {
			t.Errorf("%s: err = %v", base, err)
		}
		if len(f.begins) != 1 || len(f.cookies) != 0 {
			t.Errorf("%s: begins = %d, requests = %q", base, len(f.begins), f.cookies)
		}
	}
}

func TestStagingPagesMapsConfiguredPagesIntoTheCopy(t *testing.T) {
	got := stagingPages("https://kunde.example/", "https://kunde.example"+testStaging,
		[]string{"https://kunde.example/shop/", "https://other.example/x", "https://kunde.example.evil/"})
	if len(got) != 1 || got[0] != "https://kunde.example"+testStaging+"/shop/" {
		t.Errorf("pages = %v", got)
	}
}

func pushList(entries ...string) string {
	return `{"pushes":[` + strings.Join(entries, ",") + `]}`
}

func pushEntry(id, target string, created int) string {
	return `{"push_id":"` + id + `","device":"mac","target":"` + target + `","status":"confirmed","units":[{"path":"plugins/x"}],"created":` + string(rune('0'+created)) + `}`
}

// V10
func TestRollbackWithoutIDTakesTheNewestPushOfTheTarget(t *testing.T) {
	f := newFakeSite(t)
	f.list = pushList(pushEntry(stagingID, "staging", 2), pushEntry(testID, "live", 1))
	o, _, out := localSite(t, f)
	var res Result
	o.Report = &res
	if err := Rollback(o, ""); err != nil || f.rbID != testID {
		t.Fatalf("live: rolled back %q, %v\n%s", f.rbID, err, out)
	}
	if res.Target != TargetLive || res.PushID != testID || res.Status != "rolled_back" {
		t.Errorf("result = %+v", res)
	}
	o.Target = TargetStaging
	if err := Rollback(o, ""); err != nil || f.rbID != stagingID {
		t.Errorf("staging: rolled back %q, %v", f.rbID, err)
	}
	if res.Target != TargetStaging || res.PushID != stagingID {
		t.Errorf("result = %+v", res)
	}

	f.list, f.rbID = pushList(pushEntry(stagingID, "staging", 2)), ""
	o.Target = ""
	if err := Rollback(o, ""); err == nil || f.rbID != "" {
		t.Errorf("only a staging push exists: rolled back %q, %v", f.rbID, err)
	}
}

// V10: when the agent does not answer, the journals decide – per target.
func TestRollbackWithoutAgentTakesTheNewestJournalOfTheTarget(t *testing.T) {
	f := newFakeSite(t)
	f.list, f.rollback = "<html>Fatal error</html>", 500
	o, siteDir, out := localSite(t, f)
	base := baseline.New(f.srv.URL)
	live := NewJournal(testID, f.srv.URL+"/rescue.php", testSalt, base, nil)
	stg := NewJournal(stagingID, f.srv.URL+"/rescue.php", testSalt, base, nil)
	stg.Target = TargetStaging
	for _, j := range []*Journal{live, stg} { // the staging push is the newer one
		if err := SaveJournal(siteDir, j); err != nil {
			t.Fatal(err)
		}
	}
	if got := LatestJournal(siteDir, TargetLive) + " " + LatestJournal(siteDir, TargetStaging); got != testID+" "+stagingID {
		t.Fatalf("latest journals = %s", got)
	}
	if err := Rollback(o, ""); err != nil || f.rescueKey != RescueKey("secret", testID, testSalt) {
		t.Fatalf("live: %v\n%s", err, out)
	}
	o.Target = TargetStaging
	if err := Rollback(o, ""); err != nil || f.rescueKey != RescueKey("secret", stagingID, testSalt) {
		t.Fatalf("staging: %v\n%s", err, out)
	}
}

// A rollback never crosses: the ID of a staging push with --to live does nothing, and back.
func TestRollbackRefusesAPushOfTheOtherTarget(t *testing.T) {
	f := newFakeSite(t)
	f.list = pushList(pushEntry(stagingID, "staging", 2), pushEntry(testID, "live", 1))
	o, siteDir, _ := localSite(t, f)
	stg := NewJournal(stagingID, f.srv.URL+"/rescue.php", testSalt, baseline.New(f.srv.URL), nil)
	stg.Target = TargetStaging
	if err := SaveJournal(siteDir, stg); err != nil {
		t.Fatal(err)
	}
	var mismatch *TargetError
	o.Target = TargetLive
	if err := Rollback(o, stagingID); !errors.As(err, &mismatch) || !errors.Is(err, ErrTargetMismatch) || mismatch.Is != TargetStaging {
		t.Errorf("journal says staging, --to live: %v", err)
	}
	o.Target = TargetStaging
	if err := Rollback(o, testID); !errors.As(err, &mismatch) || mismatch.Is != TargetLive { // no journal: the agent's log decides
		t.Errorf("log says live, --to staging: %v", err)
	}
	if err := Rollback(o, "p_20261001_bbbbbbbbbbbb"); !errors.Is(err, ErrTargetMismatch) {
		t.Errorf("unknown push with --to: %v", err)
	}
	if f.rbID != "" || f.rescueKey != "" {
		t.Errorf("rolled back %q", f.rbID)
	}

	// without --to the push itself decides
	o.Target = ""
	var res Result
	o.Report = &res
	if err := Rollback(o, stagingID); err != nil || f.rbID != stagingID || res.Target != TargetStaging {
		t.Errorf("rolled back %q, %v, %+v", f.rbID, err, res)
	}
}

// The journal lies where local containers can write. Marked as applied or not: rolling back a
// staging push never rewrites the baseline of live.
func TestRollbackOfAStagingPushLeavesTheBaseline(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	commits := 0
	o.Commit = func(string, string) error { commits++; return nil }
	base, _ := baseline.Load(siteDir)
	j := NewJournal(stagingID, f.srv.URL+"/rescue.php", testSalt, base, []string{"plugins/x"})
	j.Units["plugins/x"]["wp-content/plugins/x/main.php"] = baseline.FileStamp{Size: 1, MTime: 1}
	j.Target, j.Applied = TargetStaging, true
	if err := SaveJournal(siteDir, j); err != nil {
		t.Fatal(err)
	}
	before, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if err := Rollback(o, stagingID); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	after, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if !bytes.Equal(before, after) || commits != 0 {
		t.Errorf("baseline rewritten or committed (%d)", commits)
	}
	if got := strings.Join(f.routes, " "); got != "rollback" {
		t.Errorf("routes = %s", got)
	}
}

func TestRollbackStopsWhenAgentAndJournalDisagree(t *testing.T) {
	f := newFakeSite(t)
	f.list = pushList(pushEntry(testID, "live", 1))
	o, siteDir, _ := localSite(t, f)
	j := NewJournal(testID, f.srv.URL+"/rescue.php", testSalt, baseline.New(f.srv.URL), nil)
	j.Target = TargetStaging
	if err := SaveJournal(siteDir, j); err != nil {
		t.Fatal(err)
	}
	if err := Rollback(o, ""); !errors.Is(err, ErrTargetMismatch) || f.rbID != "" {
		t.Errorf("rolled back %q, %v", f.rbID, err)
	}
}

func TestJournalKnowsOnlyTwoTargets(t *testing.T) {
	dir := t.TempDir()
	j := NewJournal(testID, "https://kunde.example/rescue.php", testSalt, baseline.New("https://kunde.example"), nil)
	j.Target = "../live"
	if err := SaveJournal(dir, j); err == nil {
		t.Error("a journal with an unknown target was written")
	}
	j.Target = ""
	if err := SaveJournal(dir, j); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(dir, ".wpsync", "pushes", testID+".json")
	data, _ := os.ReadFile(path)
	os.WriteFile(path, bytes.Replace(data, []byte(`"applied"`), []byte(`"target": "prod", "applied"`), 1), 0o600)
	if _, err := LoadJournal(dir, testID); err == nil {
		t.Error("a journal with an unknown target was read")
	}
	if id := LatestJournal(dir, TargetLive); id != "" {
		t.Errorf("an unreadable journal counts for live: %s", id)
	}
}

func TestPushesShowsTheTarget(t *testing.T) {
	f := newFakeSite(t)
	f.list = pushList(pushEntry(stagingID, "staging", 2), pushEntry(testID, "live", 1), pushEntry("p_20261001_bbbbbbbbbbbb", `\u001b[2Jx`, 1))
	o, _, out := localSite(t, f)
	if err := Pushes(o); err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(out.String(), "\n")
	if !strings.Contains(lines[0], "ZIEL") || !strings.Contains(lines[1], "Staging") || !strings.Contains(lines[2], "Live") || strings.Contains(out.String(), "\x1b") {
		t.Errorf("log:\n%q", out.String())
	}
	records, err := List(o)
	if err != nil || len(records) != 3 || records[0].Target != TargetStaging || records[0].PushID != stagingID {
		t.Errorf("list = %+v, %v", records, err)
	}
}
