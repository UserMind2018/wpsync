package push

import (
	"bytes"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localgit"
	"github.com/usermind/wpsync/internal/sites"
)

const (
	testID   = "p_20261005_0123456789ab"
	testSalt = "00112233445566778899aabbccddeeff"
	// testStaging is the folder of the staging copy on the fake site.
	testStaging = "/wpsync-staging-0123456789ab"
)

var stubPath = regexp.MustCompile(`^/wpsync-rescue-[a-f0-9]{32}\.php$`)

func isRescue(p string) bool { return p == "/rescue.php" || stubPath.MatchString(p) }

// fakeSite plays agent, frontend and rescue.php of one site.
type fakeSite struct {
	t        *testing.T
	srv      *httptest.Server
	routes   []string
	begins   []agentapi.PushBeginRequest
	uploaded map[string]string // rel → content, pieces joined

	version   string
	versions  map[string]string // unit → version the server reports
	unpulled  map[string]bool   // units the server has although the client never pulled them
	window    bool
	pending   bool
	conflicts []string
	list      string // answer of /push/list; empty: one confirmed push
	readonly  bool
	broken    bool     // the frontend fails after the swap
	confirm   int      // HTTP status of /push/confirm
	rollback  int      // HTTP status of /push/rollback
	rescue    int      // HTTP status of rescue.php for a rollback
	rescueErr string   // error of rescue.php when rescue != 200; empty: "restore failed"
	rbCode    string   // error code of /push/rollback when rollback != 200; empty: wpsync_push_state
	health    []string // extra pages the agent names for the health check
	stub      string   // path of the rescue stub the agent names when asked; empty: /rescue.php
	realStub  string   // path only the real begin names (the stub of the dry run was tidied away)
	blocked   bool     // /rescue.php (the plugin folder) answers 403, as with iThemes Security
	hardening []string // rescue.hardening
	pings     []string // paths of every rescue ping that reached the script

	committed  bool
	rolledBack bool
	rescueKey  string
	chunks     int
	maxRaw     int // upper bound for the raw bytes of one upload request; 0: unchecked

	stagingHits int      // pages of the staging copy answered with the access cookie
	logins      int      // login links handed out
	rbID        string   // push_id of the last /push/rollback
	answerFor   string   // target /push/begin answers for; empty: the one asked for (agent 0.5.0)
	realFor     string   // like answerFor, but only for the real begin
	beginCode   string   // error code of /push/begin; empty: it answers
	redirect    string   // where the front page of the copy redirects to; empty: it answers
	cookies     []string // "path cookie-header" of every frontend request
	stgHealth   []string // extra pages the agent names for a push to staging
	leftOut     []string // "unit/file" the commit does not place (staging: a .htaccess with rewrite rules)

	// The staging copy as /staging/status describes it and, when copy is set, its files: then a
	// push to staging is checked against them like PushManifest::conflicts does.
	copy        map[string]map[string]agentapi.PushStamp // unit → file → stamp; nil: conflicts as scripted
	copyOld     map[string]map[string]agentapi.PushStamp // the units of the last commit before it
	copyDir     string                                   // folder of the copy; empty: testStaging
	copyMade    int64                                    // created
	copyCopied  int64                                    // copied_at
	copyCode    int64                                    // code_copied_at; 0: an agent before the field
	noStatus    bool                                     // /staging/status fails
	statuses    int                                      // calls of /staging/status
	ids         []string                                 // push ids of the next real begins; empty: testID
	onUpload    func()                                   // runs when an upload request arrives (SIGTERM tests)
	onCommit    func(r *http.Request)                    // runs when /push/commit arrives, before it is applied
	stall       bool                                     // frontend pages after the swap answer only when the request ends
	pushID      string                                   // id of the last real begin
	forStaging  bool                                     // the last real begin went to staging
	uploadsHave map[string]string                        // files below wp-content/uploads on the site: rel → content
	acceptOld   bool                                     // an agent before 0.6.0 that takes the unit uploads anyway
	upNeed      []string                                 // need of the unit uploads in the last begin
	upUnit      map[string]int                           // rel → unit index of the upload request that carried it
	rescueBody  string                                   // answer of rescue.php to a rollback; empty: {"ok":true,"status":"rolled_back"}
	rbPlain     bool                                     // /push/rollback answers with a page that is not the agent's
	confirmBody string                                   // answer of /push/confirm on 200; empty: {"ok":true}
	rbBody      string                                   // answer of /push/rollback on 200; empty: {"ok":true}

	// The content channel (agent 0.7.0, content_run_test.go).
	staged         map[string][]byte           // sha256 → what /content/stage holds
	noContent      bool                        // an agent without the channel: no route, no answer for content
	contentFail    *agentapi.ContentFailure    // the check refuses the package: in the dry run's answer, as an error of the real begin
	contentHealth  []string                    // published pages the package changes
	unchecked      []agentapi.ContentUnchecked // what a dry run without a window leaves unchecked
	uncheckedTotal int
	commitCode     string                         // /push/commit refuses with this code and swaps nothing
	noApply        bool                           // /push/commit answers without content
	actions        []agentapi.PostAction          // post actions of the commit
	tamper         func(*agentapi.ContentApplied) // changes what the commit answers about the content

	// The content rollback of rescue.php (agent 0.8.0, rescuedb_test.go).
	rescueDB     *agentapi.RescueDBState // rescue.db of /push/begin for a push with content; nil: an agent 0.7.x
	rescueDBReal *agentapi.RescueDBState // rescue.db of the real begin only (the probe failed)
	rescueForms  []url.Values            // the form of every rollback request to rescue.php
	rescueBusy   int                     // so many rollback requests rescue.php answers with 423 busy first
	rbBusy       int                     // so many /push/rollback requests the agent answers with 423 wpsync_push_busy first
	cacheStatus  int                     // HTTP status of action=cache; 0: 200
	cacheBody    string                  // answer of action=cache; empty: {"ok":true,"cache":"flushed"}
}

func newFakeSite(t *testing.T) *fakeSite {
	f := &fakeSite{t: t, uploaded: map[string]string{}, upUnit: map[string]int{}, version: "0.4.0", window: true, confirm: 200, rollback: 200, rescue: 200}
	f.copyMade, f.copyCopied = 1790000000, 1790000100
	f.versions = map[string]string{"plugins/x": "1.0"}
	f.srv = httptest.NewServer(http.HandlerFunc(f.handle))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeSite) handle(w http.ResponseWriter, r *http.Request) {
	if r.URL.Query().Get("rest_route") == "" && !isRescue(r.URL.Path) {
		f.cookies = append(f.cookies, r.URL.Path+" "+r.Header.Get("Cookie"))
	}
	if strings.HasPrefix(r.URL.Path, testStaging) { // the staging copy: access cookie or 403
		if r.URL.Query().Get("wpsync_login") == "tok" {
			http.SetCookie(w, &http.Cookie{Name: "wpsync_stg", Value: strings.Repeat("c", 64), Path: testStaging + "/"})
			http.SetCookie(w, &http.Cookie{Name: "wordpress_logged_in_x", Value: "admin", Path: testStaging + "/"})
			http.Redirect(w, r, testStaging+"/wp-admin/", http.StatusFound)
			return
		}
		if c, err := r.Cookie("wpsync_stg"); err != nil || c.Value != strings.Repeat("c", 64) {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		f.stagingHits++
		if f.redirect != "" && r.URL.Path == testStaging+"/" {
			http.SetCookie(w, &http.Cookie{Name: "wordpress_logged_in_x", Value: "admin", Path: "/"})
			http.Redirect(w, r, f.redirect, http.StatusFound)
			return
		}
		if f.broken && f.committed && !f.rolledBack {
			w.WriteHeader(http.StatusInternalServerError)
			w.Write([]byte("Fatal error"))
			return
		}
		w.Write([]byte("<html>staging</html>"))
		return
	}
	if isRescue(r.URL.Path) {
		if f.blocked && r.URL.Path == "/rescue.php" {
			w.WriteHeader(http.StatusForbidden)
			w.Write([]byte("<html>Forbidden</html>"))
			return
		}
		r.ParseForm()
		if r.PostForm.Get("action") == "ping" {
			f.pings = append(f.pings, r.URL.Path)
			w.Write([]byte(`{"ok":true}`))
			return
		}
		if r.PostForm.Get("action") == "cache" {
			f.routes = append(f.routes, "cache")
			f.rescueKey = r.PostForm.Get("key")
			status, body := f.cacheStatus, f.cacheBody
			if status == 0 {
				status = 200
			}
			if body == "" {
				body = `{"ok":true,"cache":"flushed"}`
			}
			w.WriteHeader(status)
			w.Write([]byte(body))
			return
		}
		f.routes = append(f.routes, "rescue")
		f.rescueKey = r.PostForm.Get("key")
		f.rescueForms = append(f.rescueForms, r.PostForm)
		if f.rescueBusy > 0 {
			f.rescueBusy--
			w.WriteHeader(http.StatusLocked)
			w.Write([]byte(`{"ok":false,"error":"busy"}`))
			return
		}
		if f.rescue != 200 {
			w.WriteHeader(f.rescue)
			why := f.rescueErr
			if why == "" {
				why = "restore failed"
			}
			w.Write([]byte(`{"ok":false,"error":"` + why + `"}`))
			return
		}
		f.rolledBack = true
		f.restoreCopy()
		if f.rescueBody != "" {
			w.Write([]byte(f.rescueBody))
			return
		}
		w.Write([]byte(`{"ok":true,"status":"rolled_back"}`))
		return
	}
	route := r.URL.Query().Get("rest_route")
	if route == "" { // frontend page for the health check
		if f.stall && f.committed && !f.rolledBack {
			<-r.Context().Done()
			return
		}
		if f.broken && f.committed && !f.rolledBack {
			w.WriteHeader(http.StatusInternalServerError)
			w.Write([]byte("Fatal error"))
			return
		}
		w.Write([]byte("<html>ok</html>"))
		return
	}
	if route == "/wpsync/v1/staging/status" { // read-only and not part of the push protocol: counted apart
		f.statuses++
		if f.noStatus || !AtLeast(f.version, "0.5.0") {
			w.WriteHeader(http.StatusNotFound)
			w.Write([]byte(`{"code":"rest_no_route","message":"no route"}`))
			return
		}
		dir := f.copyDir
		if dir == "" {
			dir = testStaging
		}
		json.NewEncoder(w).Encode(agentapi.StagingStatus{Exists: true, Status: "ready", URL: f.srv.URL + dir, Created: f.copyMade, CopiedAt: f.copyCopied, CodeCopiedAt: f.copyCode})
		return
	}
	if route == "/wpsync/v1/content/stage" {
		f.stage(w, r)
		return
	}
	f.routes = append(f.routes, strings.TrimPrefix(route, "/wpsync/v1/push/"))
	switch route {
	case "/wpsync/v1/staging/login":
		f.logins++
		w.Write([]byte(`{"url":"` + f.srv.URL + testStaging + `/?wpsync_login=tok","expires":1}`))
	case "/wpsync/v1/push/begin":
		var req agentapi.PushBeginRequest
		json.NewDecoder(r.Body).Decode(&req)
		f.begins = append(f.begins, req)
		if f.beginCode != "" {
			w.WriteHeader(http.StatusConflict)
			w.Write([]byte(`{"code":"` + f.beginCode + `","message":"abgelehnt"}`))
			return
		}
		res := agentapi.PushBegin{
			AgentVersion: f.version, WindowOpen: f.window,
			HealthURLs: append([]string{f.srv.URL + "/", f.srv.URL + "/wp-login.php"}, f.health...),
			Rescue:     agentapi.PushRescue{URL: f.srv.URL + f.rescuePath(req), Hardening: f.hardening},
		}
		if AtLeast(f.version, "0.5.0") {
			res.Target = req.Target
		}
		if f.answerFor != "" {
			res.Target = f.answerFor
		}
		if f.realFor != "" && !req.Dry {
			res.Target = f.realFor
		}
		if req.Target == "staging" {
			res.HealthURLs = append([]string{f.srv.URL + testStaging + "/", f.srv.URL + testStaging + "/wp-login.php"}, f.stgHealth...)
		}
		if f.pending {
			res.Pending = &agentapi.PushPending{PushID: "p_20261004_ba9876543210", Device: "anderer-mac"}
		}
		for _, u := range req.Units {
			if u.Path == UploadsUnit {
				plan, refused := f.uploadsPlan(w, req, u)
				if refused {
					return
				}
				res.Units = append(res.Units, plan)
				continue
			}
			plan := agentapi.PushUnitPlan{Path: u.Path, Exists: len(u.Base) > 0 || f.unpulled[u.Path], Version: f.versions[u.Path], Conflicts: []string{}, Writable: !f.readonly}
			if u.Path == "plugins/x" {
				plan.Conflicts = append(plan.Conflicts, f.conflicts...)
			}
			if f.copy != nil && req.Target == "staging" {
				plan.Exists = f.copy[u.Path] != nil
				plan.Conflicts = append(plan.Conflicts, copyConflicts(f.copy[u.Path], u.Base)...)
			}
			if f.unpulled[u.Path] {
				for rel := range u.Files {
					plan.Conflicts = append(plan.Conflicts, rel) // unknown to the client's baseline
				}
			}
			for rel := range u.Files {
				if b, ok := u.Base[rel]; !ok || b.MTime != u.Files[rel].MTime {
					plan.Need = append(plan.Need, rel)
				}
			}
			res.Units = append(res.Units, plan)
		}
		if req.Content != nil && !f.noContent {
			res.Rescue.DB = f.rescueDB
			if !req.Dry && f.rescueDBReal != nil {
				res.Rescue.DB = f.rescueDBReal
			}
			res.Content = f.contentPlan(req)
			if !req.Dry && f.contentFail != nil {
				w.WriteHeader(http.StatusConflict)
				json.NewEncoder(w).Encode(map[string]any{"code": "wpsync_content_" + f.contentFail.Code, "message": f.contentFail.Message,
					"data": map[string]any{"status": 409, "keys": f.contentFail.Keys}})
				return
			}
		}
		if !req.Dry {
			res.PushID, res.Rescue.Salt = testID, testSalt
			if len(f.ids) > 0 {
				res.PushID, f.ids = f.ids[0], f.ids[1:]
			}
			f.pushID, f.forStaging = res.PushID, req.Target == "staging"
			f.uploaded, f.committed, f.rolledBack = map[string]string{}, false, false
		}
		json.NewEncoder(w).Encode(res)
	case "/wpsync/v1/push/upload":
		if f.onUpload != nil {
			f.onUpload()
		}
		var req struct {
			PushID string               `json:"push_id"`
			Unit   int                  `json:"unit"`
			Files  []agentapi.PushChunk `json:"files"`
		}
		json.NewDecoder(r.Body).Decode(&req)
		raw := 0
		for _, c := range req.Files {
			raw += len(c.Data)
		}
		if f.maxRaw > 0 && raw > f.maxRaw {
			f.t.Errorf("one upload request carries %d raw bytes, limit %d", raw, f.maxRaw)
		}
		for _, c := range req.Files {
			if int64(len(f.uploaded[c.Path])) != c.Offset {
				f.t.Errorf("chunk of %s at offset %d, have %d bytes", c.Path, c.Offset, len(f.uploaded[c.Path]))
			}
			f.uploaded[c.Path] += string(c.Data)
			f.upUnit[c.Path] = req.Unit
			f.chunks++
		}
		w.Write([]byte(`{"received":1}`))
	case "/wpsync/v1/push/commit":
		if f.onCommit != nil {
			f.onCommit(r)
		}
		if f.commitCode != "" {
			w.WriteHeader(http.StatusConflict)
			w.Write([]byte(`{"code":"` + f.commitCode + `","message":"abgelehnt","data":{"status":409,"keys":[{"table":"posts","key":"219"}]}}`))
			return
		}
		f.committed = true
		stamps := map[string]map[string]agentapi.PushStamp{}
		for _, u := range f.begins[len(f.begins)-1].Units {
			stamps[u.Path] = map[string]agentapi.PushStamp{}
			if u.Path == UploadsUnit { // only what the commit added
				for _, rel := range f.upNeed {
					stamps[u.Path][rel] = agentapi.PushStamp{Size: u.Files[rel].Size, MTime: u.Files[rel].MTime}
				}
				continue
			}
			for rel, file := range u.Files {
				if !slices.Contains(f.leftOut, u.Path+"/"+rel) {
					stamps[u.Path][rel] = agentapi.PushStamp{Size: file.Size, MTime: file.MTime}
				}
			}
		}
		if f.copy != nil && f.forStaging {
			f.copyOld = map[string]map[string]agentapi.PushStamp{}
			for unit, files := range stamps {
				f.copyOld[unit], f.copy[unit] = f.copy[unit], files
			}
		}
		answer := map[string]any{"next": nil, "stamps": stamps}
		if ref := f.begins[len(f.begins)-1].Content; ref != nil && !f.noContent && !f.noApply {
			applied := f.applied(ref.SHA256)
			if f.tamper != nil {
				f.tamper(applied)
			}
			answer["content"] = applied
		}
		json.NewEncoder(w).Encode(answer)
	case "/wpsync/v1/push/confirm":
		if f.confirm == 200 && f.confirmBody != "" {
			w.Write([]byte(f.confirmBody))
			return
		}
		f.status(w, f.confirm)
	case "/wpsync/v1/push/rollback":
		var rb struct {
			PushID string `json:"push_id"`
		}
		json.NewDecoder(r.Body).Decode(&rb)
		f.rbID = rb.PushID
		if f.rbBusy > 0 {
			f.rbBusy--
			w.WriteHeader(http.StatusLocked)
			w.Write([]byte(`{"code":"wpsync_push_busy","message":"Für diesen Push läuft gerade eine Rücknahme","data":{"status":423}}`))
			return
		}
		if f.rollback == 200 {
			f.rolledBack = true
			f.restoreCopy()
			if f.rbBody != "" {
				w.Write([]byte(f.rbBody))
				return
			}
		}
		if f.rbPlain {
			w.WriteHeader(f.rollback)
			w.Write([]byte("<html><body>Access denied</body></html>"))
			return
		}
		if f.rbCode != "" {
			w.WriteHeader(f.rollback)
			w.Write([]byte(`{"code":"` + f.rbCode + `","message":"Fenster zu"}`))
			return
		}
		f.status(w, f.rollback)
	case "/wpsync/v1/push/list":
		if f.list != "" {
			w.Write([]byte(f.list))
			return
		}
		w.Write([]byte(`{"pushes":[{"push_id":"` + testID + `","device":"mac","target":"live","status":"confirmed","units":[{"path":"plugins/x","files":2,"uploaded":1}],"created":1791158400}]}`))
	default:
		f.t.Errorf("unexpected route %s", route)
	}
}

// rescuePath is where the agent answers rescue.php for this begin (Spec Stufe 2, 12).
func (f *fakeSite) rescuePath(req agentapi.PushBeginRequest) string {
	switch {
	case req.RescueStub && !req.Dry && f.realStub != "":
		return f.realStub
	case req.RescueStub && f.stub != "":
		return f.stub
	}
	return "/rescue.php"
}

// restoreCopy puts the units of the last commit back as they were: a rollback renames the
// snapshot into place, the files keep their stamps.
func (f *fakeSite) restoreCopy() {
	for unit, files := range f.copyOld {
		if files == nil {
			delete(f.copy, unit)
		} else {
			f.copy[unit] = files
		}
	}
	f.copyOld = nil
}

// copyConflicts mirrors PushManifest::conflicts: every file on the server whose stamp the client
// does not know, and every file the client knows that the server no longer has.
func copyConflicts(server, base map[string]agentapi.PushStamp) []string {
	var out []string
	for rel, stamp := range server {
		if known, ok := base[rel]; !ok || known != stamp {
			out = append(out, rel)
		}
	}
	for rel := range base {
		if _, ok := server[rel]; !ok {
			out = append(out, rel)
		}
	}
	sort.Strings(out)
	return out
}

func (f *fakeSite) status(w http.ResponseWriter, status int) {
	if status != 200 {
		w.WriteHeader(status)
		if status >= 500 {
			w.Write([]byte("<html>Fatal error</html>"))
			return
		}
		w.Write([]byte(`{"code":"wpsync_push_state","message":"abgelehnt"}`))
		return
	}
	w.Write([]byte(`{"ok":true}`))
}

// localSite prepares a pulled site with one edited plugin and returns options for it.
func localSite(t *testing.T, f *fakeSite) (Options, string, *bytes.Buffer) {
	t.Helper()
	root := t.TempDir()
	siteDir := filepath.Join(root, "kunde")
	docroot := filepath.Join(siteDir, "public")
	base := baseline.New(f.srv.URL)
	pulled(t, docroot, base, "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */")
	pulled(t, docroot, base, "plugins/x/inc/same.php", "<?php // same")
	pulled(t, docroot, base, "themes/t/style.css", "/* Theme Name: T */")
	base.PulledAt = time.Now()
	if err := baseline.Save(siteDir, base); err != nil {
		t.Fatal(err)
	}
	write(t, docroot, "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */\n// edited", 1800000000)

	client := agentapi.New(f.srv.URL, "0123456789abcdef", "secret", 1000)
	client.Sleep = func(time.Duration) {}
	out := &bytes.Buffer{}
	return Options{
		Site:      sites.Site{Name: "kunde", URL: f.srv.URL},
		Secret:    "secret",
		SitesRoot: root,
		Yes:       true,
		Out:       out,
		HTTP:      f.srv.Client(),
		Client:    client,
		Sleep:     func(time.Duration) {},
		Commit:    func(string, string) error { return nil },
	}, siteDir, out
}

// AC-54, AC-56
func TestRunPushesChangedUnitAndUpdatesTheBaseline(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	var commits []string
	o.Commit = func(_, msg string) error { commits = append(commits, msg); return nil }

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload commit confirm" {
		t.Errorf("routes = %s", got)
	}
	if !f.begins[0].Dry || f.begins[1].Dry || len(f.begins[1].Units) != 1 || f.begins[1].Units[0].Path != "plugins/x" {
		t.Errorf("begins = %+v", f.begins)
	}
	if len(f.uploaded) != 1 || !strings.HasSuffix(f.uploaded["main.php"], "// edited") {
		t.Errorf("uploaded = %v", f.uploaded)
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1800000000 || base.Files["wp-content/themes/t/style.css"].MTime != 1700000000 {
		t.Errorf("baseline = %v", base.Files)
	}
	if units, _, _ := Scan(filepath.Join(siteDir, "public"), base); len(units) != 0 {
		t.Errorf("still changed after the push: %v", units)
	}
	j, err := LoadJournal(siteDir, testID)
	if err != nil || !j.Applied || j.Salt != testSalt || j.Units["plugins/x"]["wp-content/plugins/x/main.php"].MTime != 1700000000 {
		t.Errorf("journal = %+v, %v", j, err)
	}
	if len(commits) != 1 || !strings.Contains(commits[0], testID) {
		t.Errorf("commits = %v", commits)
	}
	if !strings.Contains(out.String(), "plugins/x") || !strings.Contains(out.String(), testID) {
		t.Errorf("output:\n%s", out)
	}
}

func TestRunWithNothingChanged(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */", 1700000000)

	if err := Run(o); !errors.Is(err, ErrNothing) {
		t.Fatalf("err = %v", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("nothing to push must not cost a request: %v", f.routes)
	}
}

func TestRunWithoutPullFails(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	o.SitesRoot = t.TempDir()
	if err := Run(o); !errors.Is(err, ErrNoBaseline) {
		t.Fatalf("err = %v", err)
	}
}

func TestRunPushesOnlyNamedUnits(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "themes/t/style.css", "/* Theme Name: T */ body{}", 1800000000)
	o.Units = []string{"wp-content/themes/t/"}

	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if len(f.begins[1].Units) != 1 || f.begins[1].Units[0].Path != "themes/t" {
		t.Errorf("units = %+v", f.begins[1].Units)
	}
	o.Units = []string{"plugins/wpsync-agent"}
	if err := Run(o); err == nil || !strings.Contains(err.Error(), "plugins/wpsync-agent") {
		t.Errorf("err = %v", err)
	}
}

// U14: eine lokale Kopie ausserhalb des Pull-Profils ist kein Push-Kandidat.
func TestRunSkipsUnitsThatWereNeverPulled(t *testing.T) {
	f := newFakeSite(t)
	f.unpulled = map[string]bool{"plugins/stale": true}
	o, siteDir, out := localSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "plugins/stale/stale.php", "<?php // old copy", 1600000000)

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !strings.Contains(out.String(), "übersprungen: plugins/stale") {
		t.Errorf("no hint about the skipped unit:\n%s", out)
	}
	if got := f.begins[1].Units; len(got) != 1 || got[0].Path != "plugins/x" {
		t.Errorf("pushed units = %+v", got)
	}

	o.Units = []string{"plugins/stale"}
	if err := Run(o); !errors.Is(err, ErrConflict) {
		t.Fatalf("naming the unit must surface the conflict: %v", err)
	}
}

// U14: eine lokal neue Einheit geht nur mit ausdrücklicher Nennung auf die Site,
// auch wenn es sie dort noch nicht gibt.
func TestRunPushesNewUnitsOnlyWhenNamed(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "plugins/neu/neu.php", "<?php // new", 1800000000)

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !strings.Contains(out.String(), "übersprungen: plugins/neu") {
		t.Errorf("no hint about the skipped new unit:\n%s", out)
	}
	for i, b := range f.begins {
		if len(b.Units) != 1 || b.Units[0].Path != "plugins/x" {
			t.Errorf("begin %d sent units %+v – a new unit must not even reach the dry run", i, b.Units)
		}
	}

	f.begins, f.routes = nil, nil
	o.Units = []string{"plugins/neu"}
	out.Reset()
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := f.begins[1].Units; len(got) != 1 || got[0].Path != "plugins/neu" {
		t.Errorf("pushed units = %+v", got)
	}
	if !strings.Contains(f.uploaded["neu.php"], "// new") {
		t.Errorf("uploaded = %v", f.uploaded)
	}
}

func TestRunWithOnlyNewUnitsSaysToNameThem(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	docroot := filepath.Join(siteDir, "public")
	write(t, docroot, "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */", 1700000000)
	write(t, docroot, "plugins/neu/neu.php", "<?php // new", 1800000000)
	write(t, docroot, "themes/neu/style.css", "/* Theme Name: Neu */", 1800000000)

	err := Run(o)
	if !errors.Is(err, ErrNothing) {
		t.Fatalf("err = %v", err)
	}
	var skipped *SkippedNewError
	if !errors.As(err, &skipped) || strings.Join(skipped.Units, " ") != "plugins/neu themes/neu" {
		t.Fatalf("err = %#v – it must name the skipped new units", err)
	}
	if len(f.routes) != 0 {
		t.Errorf("skipped new units must not cost a request: %v", f.routes)
	}
	if !strings.Contains(out.String(), "übersprungen: themes/neu") {
		t.Errorf("output:\n%s", out)
	}
}

func TestRunDryRunStopsAfterThePlan(t *testing.T) {
	f := newFakeSite(t)
	f.window = false
	o, _, out := localSite(t, f)
	o.DryRun = true
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
	if !strings.Contains(out.String(), "main.php") || !strings.Contains(out.String(), "geschlossen") {
		t.Errorf("plan should list changed files and the closed window:\n%s", out)
	}
}

// AC-50
func TestRunNeedsAnOpenWindow(t *testing.T) {
	f := newFakeSite(t)
	f.window = false
	o, _, _ := localSite(t, f)
	if err := Run(o); !errors.Is(err, ErrWindowClosed) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
}

// AC-57
func TestRunStopsOnConflictUnlessForced(t *testing.T) {
	f := newFakeSite(t)
	f.conflicts = []string{"inc/same.php"}
	o, _, out := localSite(t, f)
	if err := Run(o); !errors.Is(err, ErrConflict) {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(out.String(), "inc/same.php") || strings.Join(f.routes, " ") != "begin" {
		t.Errorf("routes = %v\n%s", f.routes, out)
	}

	o.Force = true
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if !f.begins[len(f.begins)-1].Force {
		t.Error("force not sent")
	}
}

// AC-73
func TestRunStopsWhenTheServerCannotReplaceTheDirectory(t *testing.T) {
	f := newFakeSite(t)
	f.readonly = true
	o, _, _ := localSite(t, f)
	if err := Run(o); !errors.Is(err, ErrNotWritable) {
		t.Fatalf("err = %v", err)
	}
}

// AC-68
func TestRunReportsAnUnconfirmedPush(t *testing.T) {
	f := newFakeSite(t)
	f.pending = true
	o, _, _ := localSite(t, f)
	err := Run(o)
	var pending *PendingError
	if !errors.As(err, &pending) || pending.PushID != "p_20261004_ba9876543210" {
		t.Fatalf("err = %v", err)
	}
}

func TestRunRefusesOldAgents(t *testing.T) {
	f := newFakeSite(t)
	f.version = "0.3.1"
	o, _, _ := localSite(t, f)
	if err := Run(o); !errors.Is(err, ErrAgentTooOld) {
		t.Fatalf("err = %v", err)
	}
}

// AC-62
func TestRunAsksSeparatelyForAVersionChange(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	write(t, filepath.Join(siteDir, "public"), "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.1 */", 1800000000)

	if err := Run(o); !errors.Is(err, ErrVersionChange) {
		t.Fatalf("--yes alone must not be enough: %v", err)
	}
	if !strings.Contains(out.String(), `"1.0" → "1.1"`) {
		t.Errorf("version change not shown:\n%s", out)
	}

	o.Yes = false
	var asked []string
	o.Confirm = func(q string) bool { asked = append(asked, q); return true }
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if len(asked) != 2 || !strings.Contains(asked[0], "Datenbank") {
		t.Errorf("asked = %v", asked)
	}

	o.Yes, o.AllowVersionChange, o.Confirm = true, true, nil
	write(t, filepath.Join(siteDir, "public"), "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.2 */", 1800000001)
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
}

func TestRunAbortsWhenTheUserDeclines(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	o.Yes = false
	o.Confirm = func(string) bool { return false }
	if err := Run(o); !errors.Is(err, ErrAborted) {
		t.Fatalf("err = %v", err)
	}
	o.Confirm = nil
	if err := Run(o); err == nil || !strings.Contains(err.Error(), "--yes") {
		t.Errorf("without a terminal the error must name --yes: %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin" {
		t.Errorf("routes = %s", got)
	}
}

// AC-63, AC-64
func TestRunRollsBackWhenTheSiteGetsWorse(t *testing.T) {
	f := newFakeSite(t)
	f.broken = true
	o, siteDir, _ := localSite(t, f)

	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || rolled.PushID != testID || len(rolled.Reasons) == 0 || len(rolled.StillWorse) != 0 {
		t.Fatalf("err = %#v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload commit rescue" {
		t.Errorf("routes = %s", got)
	}
	if f.rescueKey != RescueKey("secret", testID, testSalt) {
		t.Errorf("rescue key = %q", f.rescueKey)
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1700000000 {
		t.Error("baseline must stay at the pulled state after a rollback")
	}
}

func TestRunRollsBackWhenTheAgentNoLongerAnswers(t *testing.T) {
	f := newFakeSite(t)
	f.confirm = 500
	o, _, _ := localSite(t, f)
	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload commit confirm rescue" {
		t.Errorf("routes = %s", got)
	}
}

// confirm went through on the server, only its answer was lost: rescue.php then refuses with
// "confirmed", and the push counts as live – no "ROLLBACK FEHLGESCHLAGEN" on a healthy site.
func TestRunTreatsALostConfirmAnswerAsConfirmed(t *testing.T) {
	f := newFakeSite(t)
	f.confirm = 500
	f.rescue = 409
	f.rescueErr = "confirmed"
	o, siteDir, out := localSite(t, f)
	if err := Run(o); err != nil {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(out.String(), "ist live") || !strings.Contains(out.String(), "bereits bestätigt") {
		t.Errorf("out = %s", out.String())
	}
	base, _ := baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime == 1700000000 {
		t.Error("baseline must move on: the push is live")
	}
}

func TestRunReportsAFailedRollbackLoudly(t *testing.T) {
	f := newFakeSite(t)
	f.broken = true
	f.rescue = 500
	o, _, _ := localSite(t, f)
	err := Run(o)
	if err == nil || !strings.Contains(err.Error(), "ROLLBACK FEHLGESCHLAGEN") || !strings.Contains(err.Error(), testID) {
		t.Fatalf("err = %v", err)
	}
}

// U13
func TestRunUploadsLargeFilesInPieces(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, _ := localSite(t, f)
	big := strings.Repeat("0123456789", 250) // 2500 Bytes
	write(t, filepath.Join(siteDir, "public"), "plugins/x/big.js", big, 1800000000)
	write(t, filepath.Join(siteDir, "public"), "plugins/x/empty.php", "", 1800000000)
	o.ChunkBytes = 1000
	f.maxRaw = 1000

	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if f.uploaded["big.js"] != big {
		t.Errorf("big.js arrived with %d bytes", len(f.uploaded["big.js"]))
	}
	if _, ok := f.uploaded["empty.php"]; !ok {
		t.Error("empty file not uploaded")
	}
	uploads := 0
	for _, r := range f.routes {
		if r == "upload" {
			uploads++
		}
	}
	if uploads < 3 {
		t.Errorf("uploads = %d, want at least 3 requests of at most 1000 bytes", uploads)
	}
}

func TestAtLeast(t *testing.T) {
	for _, v := range []string{"0.4.0", "0.4.1", "0.10.0", "1.0", "0.4.0-beta"} {
		if !AtLeast(v, "0.4.0") {
			t.Errorf("%s should satisfy 0.4.0", v)
		}
	}
	for _, v := range []string{"0.3.1", "0.3.99", "", "abc"} {
		if AtLeast(v, "0.4.0") {
			t.Errorf("%s should not satisfy 0.4.0", v)
		}
	}
}

func TestErrorsAreDistinct(t *testing.T) {
	all := []error{ErrNoBaseline, ErrNothing, ErrAborted, ErrConflict, ErrWindowClosed, ErrNotWritable, ErrAgentTooOld, ErrVersionChange, ErrRescueGone}
	for i, a := range all {
		for j, b := range all {
			if i != j && errors.Is(a, b) {
				t.Errorf("%v is %v", a, b)
			}
		}
	}
}

// Health-Check und rescue.php brauchen kurze Antworten; der Agent-Client wartet bis zu 10 Minuten.
func TestDefaultsUseAShortClientForHealthAndRescue(t *testing.T) {
	o := Options{Site: sites.Site{Name: "kunde", URL: "https://kunde.de", KeyID: "0123456789abcdef", RPS: 1}}.defaults()
	if o.HTTP == nil || o.HTTP.Timeout <= 0 || o.HTTP.Timeout > time.Minute {
		t.Errorf("health client timeout = %v", o.HTTP.Timeout)
	}
	if o.HTTP == o.Client.HTTP {
		t.Error("health check must not share the agent client")
	}
	if o.ChunkBytes != 3<<20 {
		t.Errorf("ChunkBytes = %d, want 3 MiB (U13)", o.ChunkBytes)
	}
}

// The default commit uses the snapshot repo next to the site folder and never a .git inside it (SEC-131).
func TestDefaultCommitUsesSnapshotRepoOutsideTheSite(t *testing.T) {
	root := t.TempDir()
	siteDir := filepath.Join(root, "kunde")
	if err := os.MkdirAll(filepath.Join(siteDir, "public", "wp-content", "plugins", "a"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(siteDir, "public", "wp-content", "plugins", "a", "a.php"), []byte("<?php\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	o := Options{SitesRoot: root, Site: sites.Site{Name: "kunde", URL: "https://kunde.example"}, Out: &bytes.Buffer{}}.defaults()
	if err := o.Commit(siteDir, "push "+testID); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(siteDir, ".git")); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("site folder got a .git: %v", err)
	}
	if _, err := os.Stat(filepath.Join(localgit.GitDir(root, "kunde"), "HEAD")); err != nil {
		t.Fatalf("snapshot repo missing: %v", err)
	}
}

// Strings the site chooses reach the terminal only through agentapi.Printable (as on main for
// table names and paths): no escape sequence from a conflict path or version gets through.
func TestPlanQuotesServerStrings(t *testing.T) {
	f := newFakeSite(t)
	f.conflicts = []string{"inc/\x1b[2Jevil.php"}
	f.versions = map[string]string{"plugins/x": "1.0\x1b]0;title\x07"}
	o, _, out := localSite(t, f)
	o.DryRun = true
	if err := Run(o); !errors.Is(err, ErrConflict) {
		t.Fatalf("err = %v", err)
	}
	if strings.ContainsAny(out.String(), "\x1b\x07") {
		t.Errorf("raw control characters in the plan:\n%q", out)
	}
	for _, want := range []string{`"inc/\x1b[2Jevil.php"`, `"1.0\x1b]0;title\a"`} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("plan misses %s:\n%s", want, out)
		}
	}
}

func TestPendingErrorQuotesServerStrings(t *testing.T) {
	msg := (&PendingError{PushID: "p_\x1b[2J", Device: "mac\x1b[31m"}).Error()
	if strings.Contains(msg, "\x1b") {
		t.Errorf("raw escape in %q", msg)
	}
	if ok := (&PendingError{PushID: testID, Device: "mac"}).Error(); !strings.Contains(ok, testID) {
		t.Errorf("valid push id not shown as is: %q", ok)
	}
}

func TestShowID(t *testing.T) {
	if got := ShowID(testID); got != testID {
		t.Errorf("ShowID(valid) = %q", got)
	}
	if got := ShowID("p_1\nx"); got != `"p_1\nx"` {
		t.Errorf("ShowID(invalid) = %q", got)
	}
}

// Die Site ist nicht vertrauenswürdig: eine Seite auf einem fremden Host ruft der Push nie ab.
func TestRunSkipsHealthURLsOutsideTheSite(t *testing.T) {
	hits := 0
	elsewhere := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		w.Write([]byte("<html>fremd</html>"))
	}))
	defer elsewhere.Close()
	f := newFakeSite(t)
	f.health = []string{elsewhere.URL + "/intern"}
	o, _, out := localSite(t, f)

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if hits != 0 {
		t.Errorf("the push requested a page on another host %d times", hits)
	}
	if !strings.Contains(out.String(), elsewhere.URL+"/intern") || !strings.Contains(out.String(), "verworfen") {
		t.Errorf("no hint about the dropped page:\n%s", out)
	}
}

// N2: file names in the plan reach the terminal only when they are safe to show.
func TestRunKeepsFileNamesWithControlsOutOfThePlan(t *testing.T) {
	f := newFakeSite(t)
	o, siteDir, out := localSite(t, f)
	o.DryRun = true
	write(t, filepath.Join(siteDir, "public"), "plugins/x/inc/\u202egnp.php", "<?php", 1800000000)
	write(t, filepath.Join(siteDir, "public"), "plugins/x/inc/größe.php", "<?php", 1800000000)

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if strings.Contains(out.String(), "\u202e") {
		t.Errorf("Bidi control in the plan:\n%q", out)
	}
	if !strings.Contains(out.String(), "    inc/größe.php\n") {
		t.Errorf("umlauts must stay readable:\n%s", out)
	}
	if _, ok := f.begins[0].Units[0].Files["inc/\u202egnp.php"]; ok {
		t.Error("an ignored file was sent to the agent")
	}
}

func TestShowPath(t *testing.T) {
	for in, want := range map[string]string{
		"inc/größe.php":    "inc/größe.php",
		"wp_\u202egnp.php": `"wp_\u202egnp.php"`,
		"a\u009bb.php":     `"a\u009bb.php"`,
	} {
		if got := showPath(in); got != want {
			t.Errorf("showPath(%q) = %s, want %s", in, got, want)
		}
	}
}

// Spec Stufe 2, 12 (B1): der Probelauf fordert den Stub an; gepingt und ins Journal geschrieben wird er.
func TestRunUsesTheRescueStubWhenPluginsAreBlocked(t *testing.T) {
	f := newFakeSite(t)
	f.blocked = true
	f.stub = "/wpsync-rescue-" + strings.Repeat("a", 32) + ".php"
	o, siteDir, out := localSite(t, f)
	var res Result
	o.Report = &res

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !f.begins[0].RescueStub || !f.begins[1].RescueStub {
		t.Errorf("begins must ask for the stub: %+v", f.begins)
	}
	if got := strings.Join(f.pings, " "); got != f.stub {
		t.Errorf("pings = %s", got)
	}
	j, err := LoadJournal(siteDir, testID)
	if err != nil || j.RescueURL != f.srv.URL+f.stub {
		t.Errorf("journal = %+v, %v", j, err)
	}
	if res.RescueURL != f.srv.URL+f.stub {
		t.Errorf("rescue_url = %s", res.RescueURL)
	}
}

// R3: --dry-run legt auf dem Server nichts an, auch keinen Stub.
func TestRunDryRunDoesNotAskForTheStub(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	o.DryRun = true
	if err := Run(o); err != nil {
		t.Fatal(err)
	}
	if f.begins[0].RescueStub {
		t.Error("a dry run must not ask for a stub")
	}
}

// AC-137: der echte Begin nennt einen neuen Stub – geprüft vor dem ersten Upload, im Journal.
func TestRunPingsANewStubOfTheRealBegin(t *testing.T) {
	f := newFakeSite(t)
	f.stub = "/wpsync-rescue-" + strings.Repeat("a", 32) + ".php"
	f.realStub = "/wpsync-rescue-" + strings.Repeat("b", 32) + ".php"
	o, siteDir, out := localSite(t, f)

	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.pings, " "); got != f.stub+" "+f.realStub {
		t.Errorf("pings = %s", got)
	}
	if j, err := LoadJournal(siteDir, testID); err != nil || j.RescueURL != f.srv.URL+f.realStub {
		t.Errorf("journal = %+v, %v", j, err)
	}
}

// AC-137: ist der Rückweg des echten Begin gesperrt, geht kein Byte auf die Site.
func TestRunStopsBeforeTheUploadWhenTheNewRescueIsBlocked(t *testing.T) {
	f := newFakeSite(t)
	f.stub = "/wpsync-rescue-" + strings.Repeat("a", 32) + ".php"
	f.realStub = "/rescue.php"
	f.blocked = true
	o, _, _ := localSite(t, f)

	if err := Run(o); !errors.Is(err, ErrRescueUnreachable) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin" {
		t.Errorf("routes = %s", got)
	}
}

// AC-136
func TestRunNamesHardeningPluginsWhenRescueIsBlocked(t *testing.T) {
	f := newFakeSite(t)
	f.blocked = true
	f.hardening = []string{"better-wp-security"}
	o, _, _ := localSite(t, f)

	err := Run(o)
	var blocked *RescueBlockedError
	if !errors.As(err, &blocked) || !errors.Is(err, ErrRescueUnreachable) || len(blocked.Plugins) != 1 || blocked.Plugins[0] != "better-wp-security" {
		t.Fatalf("err = %#v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" {
		t.Errorf("routes = %s", got)
	}
}
