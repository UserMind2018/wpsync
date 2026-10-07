package push

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/localgit"
)

const secondID = "p_20261007_bbbbbbbbbbbb"

// copiedSite is stagingSite with a copy whose files the fake agent knows: made from live at the
// state of the last pull, stamps kept (StagingFiles copies with the mtime of live).
func copiedSite(t *testing.T) (*fakeSite, Options, string, *bytes.Buffer) {
	t.Helper()
	f, o, siteDir, out := stagingSite(t)
	base, err := baseline.Load(siteDir)
	if err != nil {
		t.Fatal(err)
	}
	f.copy = map[string]map[string]agentapi.PushStamp{}
	for path, stamp := range base.Files {
		unit, rel, ok := UnitOf(path)
		if !ok {
			t.Fatalf("baseline path %s", path)
		}
		if f.copy[unit] == nil {
			f.copy[unit] = map[string]agentapi.PushStamp{}
		}
		f.copy[unit][rel] = agentapi.PushStamp{Size: stamp.Size, MTime: stamp.MTime}
	}
	return f, o, siteDir, out
}

// editAgain changes the plugin once more, as after a test on staging.
func editAgain(t *testing.T, siteDir string) {
	t.Helper()
	write(t, filepath.Join(siteDir, "public"), "plugins/x/main.php", "<?php\n/* Plugin Name: X\n * Version: 1.0 */\n// edited twice", 1800000100)
}

func stampsPath(siteDir string) string {
	return filepath.Join(siteDir, ".wpsync", "staging-base.json")
}

func remembered(t *testing.T, f *fakeSite, siteDir string) *stagingBase {
	t.Helper()
	s, err := loadStagingBase(siteDir, "secret", f.srv.URL)
	if err != nil {
		t.Fatalf("staging stamps: %v", err)
	}
	return s
}

// mustPush runs a push that must succeed.
func mustPush(t *testing.T, o Options, out *bytes.Buffer) {
	t.Helper()
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
}

// The reason for the stamps: push, test, fix, push again – without --force.
func TestSecondPushToStagingNeedsNoForce(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	mustPush(t, o, out)
	editAgain(t, siteDir)
	f.ids = []string{secondID}
	if err := Run(o); err != nil {
		t.Fatalf("second push to staging: %v\n%s", err, out)
	}
	second := f.begins[len(f.begins)-1]
	if second.Force || second.Dry || second.Target != TargetStaging {
		t.Fatalf("second begin = %+v", second)
	}
	// compared with what the first push left in the copy, for every file of the unit
	if got := second.Units[0].Base["main.php"].MTime; got != 1800000000 {
		t.Errorf("base of main.php = %d, want the stamp of the first push to staging", got)
	}
	if got := second.Units[0].Base["inc/same.php"].MTime; got != 1700000000 {
		t.Errorf("base of inc/same.php = %d", got)
	}
	if !strings.HasSuffix(f.uploaded["main.php"], "// edited twice") {
		t.Errorf("uploaded = %v", f.uploaded)
	}
	if got := remembered(t, f, siteDir).Units["plugins/x"]; got == nil || got.PushID != secondID || got.Files["main.php"].MTime != 1800000100 {
		t.Errorf("stamps after the second push = %+v", got)
	}
}

// AC-98: the stamps live next to the baseline, never in it, and not in the internal git.
func TestStagingStampsLeaveBaselineAndGitAlone(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	gitDir := localgit.GitDir(o.SitesRoot, "kunde")
	real := Options{SitesRoot: o.SitesRoot, Site: o.Site, Out: &bytes.Buffer{}}.defaults().Commit
	if err := real(siteDir, "pull"); err != nil {
		t.Fatal(err)
	}
	head := func() string {
		rev, err := exec.Command("git", "--git-dir", gitDir, "rev-parse", "HEAD").Output()
		if err != nil {
			t.Fatal(err)
		}
		return string(rev)
	}
	before, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json"))
	commit := head()
	commits := 0
	o.Commit = func(string, string) error { commits++; return nil }

	mustPush(t, o, out)

	after, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if !bytes.Equal(before, after) || commits != 0 || head() != commit {
		t.Errorf("AC-98: baseline or internal git changed by a push to staging (%d commits)", commits)
	}
	info, err := os.Lstat(stampsPath(siteDir))
	if err != nil || !info.Mode().IsRegular() {
		t.Fatalf("no stamps file: %v", err)
	}
	base, _ := os.Lstat(filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if info.Mode().Perm() != base.Mode().Perm() {
		t.Errorf("mode %v, baseline has %v", info.Mode().Perm(), base.Mode().Perm())
	}
	entries, _ := os.ReadDir(filepath.Join(siteDir, ".wpsync"))
	for _, e := range entries {
		if strings.HasSuffix(e.Name(), ".wpsync-tmp") {
			t.Errorf("temporary file left: %s", e.Name())
		}
	}
	if s := remembered(t, f, siteDir); s.Copy.URL != f.srv.URL+testStaging || s.Copy.Created != f.copyMade || s.Copy.CopiedAt != f.copyCopied || s.Copy.CodeCopiedAt != 0 {
		t.Errorf("copy = %+v", s.Copy)
	}

	// A later commit of the site (pull, push to live) does not pick the file up.
	if err := real(siteDir, "later"); err != nil {
		t.Fatal(err)
	}
	files, err := exec.Command("git", "--git-dir", gitDir, "ls-tree", "-r", "--name-only", "HEAD").Output()
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(files), "staging-base") || !strings.Contains(string(files), ".wpsync/baseline.json") {
		t.Errorf("internal git holds:\n%s", files)
	}
}

// A dry run and a refused push remember nothing.
func TestStagingStampsAreWrittenOnlyAfterAConfirmedPush(t *testing.T) {
	_, o, siteDir, out := copiedSite(t)
	o.DryRun = true
	mustPush(t, o, out)
	if _, err := os.Lstat(stampsPath(siteDir)); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("a dry run wrote stamps: %v", err)
	}

	f, o, siteDir, _ := copiedSite(t)
	f.confirm = 500 // the agent no longer answers: rolled back through rescue.php
	var rolled *RolledBackError
	if err := Run(o); !errors.As(err, &rolled) {
		t.Fatalf("err = %v", err)
	}
	if _, err := os.Lstat(stampsPath(siteDir)); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("an unconfirmed push wrote stamps: %v", err)
	}
}

// Requirement 5: a change made in the copy itself still stops the next push.
func TestChangeInTheCopyStillConflicts(t *testing.T) {
	for name, change := range map[string]func(f *fakeSite){
		"pushed file edited on the server": func(f *fakeSite) {
			f.copy["plugins/x"]["main.php"] = agentapi.PushStamp{Size: 99, MTime: 1800000050}
		},
		"other file of the unit edited": func(f *fakeSite) {
			f.copy["plugins/x"]["inc/same.php"] = agentapi.PushStamp{Size: 12, MTime: 1800000050}
		},
		"plugin update added a file": func(f *fakeSite) {
			f.copy["plugins/x"]["inc/new.php"] = agentapi.PushStamp{Size: 5, MTime: 1800000050}
		},
		"file removed on the server": func(f *fakeSite) {
			delete(f.copy["plugins/x"], "inc/same.php")
		},
	} {
		f, o, siteDir, out := copiedSite(t)
		mustPush(t, o, out)
		editAgain(t, siteDir)
		change(f)
		f.routes = nil
		kept, _ := os.ReadFile(stampsPath(siteDir))
		if err := Run(o); !errors.Is(err, ErrConflict) {
			t.Errorf("%s: err = %v, want a conflict", name, err)
		}
		if got := strings.Join(f.routes, " "); got != "begin" {
			t.Errorf("%s: routes = %s", name, got)
		}
		if !strings.Contains(out.String(), "ein Pull löst das nicht") {
			t.Errorf("%s: no hint that a pull does not help:\n%s", name, out)
		}
		if now, _ := os.ReadFile(stampsPath(siteDir)); !bytes.Equal(kept, now) {
			t.Errorf("%s: a refused push changed the stamps", name)
		}
	}
}

// Requirement 5 and 7: live never sees the staging stamps – not as base, not in the scan.
func TestPushToLiveNeverUsesStagingStamps(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	mustPush(t, o, out)
	kept, _ := os.ReadFile(stampsPath(siteDir))
	statuses := f.statuses

	// the scan still sees the unit as changed: the baseline decides, not the stamps
	base, _ := baseline.Load(siteDir)
	units, _, err := Scan(filepath.Join(siteDir, "public"), base)
	if err != nil || find(units, "plugins/x") == nil {
		t.Fatalf("scan after the push to staging = %+v, %v", units, err)
	}

	o.Target = TargetLive
	mustPush(t, o, out)
	live := f.begins[len(f.begins)-1]
	if live.Target != TargetLive || live.Dry || len(live.Units) != 1 {
		t.Fatalf("live begin = %+v", live)
	}
	want := map[string]int64{"main.php": 1700000000, "inc/same.php": 1700000000}
	for rel, mtime := range want {
		if got := live.Units[0].Base[rel].MTime; got != mtime {
			t.Errorf("live base of %s = %d, want the stamp of the last pull", rel, got)
		}
	}
	if len(live.Units[0].Base) != len(want) || !strings.HasSuffix(f.uploaded["main.php"], "// edited") {
		t.Errorf("live base = %v, uploaded = %v", live.Units[0].Base, f.uploaded)
	}
	if f.statuses != statuses {
		t.Error("a push to live asked for the staging copy")
	}
	if now, _ := os.ReadFile(stampsPath(siteDir)); !bytes.Equal(kept, now) {
		t.Error("a push to live changed the staging stamps")
	}
	base, _ = baseline.Load(siteDir)
	if base.Files["wp-content/plugins/x/main.php"].MTime != 1800000000 {
		t.Errorf("baseline after the live push = %v", base.Files)
	}
}

// A refresh of the database alone leaves the code of the copy: the stamps stay. A refresh with
// code or a new copy replaces it: they go.
func TestStampsSurviveADatabaseRefresh(t *testing.T) {
	site := func() (*fakeSite, Options, string, *bytes.Buffer) {
		f, o, siteDir, out := copiedSite(t)
		f.copyCode = f.copyCopied // as after create
		mustPush(t, o, out)
		editAgain(t, siteDir)
		f.ids = []string{secondID}
		return f, o, siteDir, out
	}
	f, o, siteDir, out := site()
	if s := remembered(t, f, siteDir); s.Copy.CodeCopiedAt != f.copyCode || s.Copy.CopiedAt != 0 {
		t.Fatalf("copy = %+v, want it bound to code_copied_at", s.Copy)
	}
	f.copyCopied += 5000 // staging refresh without --code
	if err := Run(o); err != nil {
		t.Fatalf("push after a refresh of the database: %v\n%s", err, out)
	}
	if got := f.begins[len(f.begins)-1].Units[0].Base["main.php"].MTime; got != 1800000000 {
		t.Errorf("base = %d, want the stamps of the first push", got)
	}

	for name, other := range map[string]func(f *fakeSite){
		"refresh --code": func(f *fakeSite) { f.copyCopied, f.copyCode = f.copyCopied+5000, f.copyCopied+5000 },
		"created anew": func(f *fakeSite) {
			f.copyDir, f.copyMade, f.copyCopied, f.copyCode = "/wpsync-staging-ba9876543210", 1790009000, 1790009100, 1790009100
		},
		// The field vanished (agent downgraded): copied_at with the very same number is not the same copy.
		"field gone": func(f *fakeSite) { f.copyCopied, f.copyCode = f.copyCode, 0 },
	} {
		f, o, siteDir, _ := site()
		other(f)
		if err := Run(o); !errors.Is(err, ErrConflict) {
			t.Errorf("%s: err = %v, want the check against the live baseline", name, err)
		}
		if _, err := os.Lstat(stampsPath(siteDir)); !errors.Is(err, os.ErrNotExist) {
			t.Errorf("%s: stamps of another code copy stayed: %v", name, err)
		}
	}

	// An agent before the field: bound to copied_at as before, so every refresh drops the stamps;
	// once the agent names the field, stamps bound to copied_at are not carried over.
	f, o, siteDir, out = copiedSite(t)
	mustPush(t, o, out)
	if s := remembered(t, f, siteDir); s.Copy.CopiedAt != f.copyCopied || s.Copy.CodeCopiedAt != 0 {
		t.Fatalf("copy = %+v, want it bound to copied_at", s.Copy)
	}
	editAgain(t, siteDir)
	f.copyCode = f.copyCopied
	if err := Run(o); !errors.Is(err, ErrConflict) {
		t.Errorf("agent updated: err = %v, want the check against the live baseline", err)
	}
}

// Requirement 2: the stamps belong to one copy. A copy made anew or refreshed from live is another.
func TestStampsOfAnotherCopyAreDropped(t *testing.T) {
	for name, other := range map[string]func(f *fakeSite){
		"created anew": func(f *fakeSite) {
			f.copyDir, f.copyMade, f.copyCopied = "/wpsync-staging-ba9876543210", 1790009000, 1790009100
		},
		"refreshed from live": func(f *fakeSite) { f.copyCopied = 1790009100 },
		"another folder":      func(f *fakeSite) { f.copyDir = "/wpsync-staging-ba9876543210" },
		"another creation":    func(f *fakeSite) { f.copyMade = 1790009000 },
	} {
		f, o, siteDir, out := copiedSite(t)
		mustPush(t, o, out)
		editAgain(t, siteDir)
		// The copy the stamps described is gone; the new one still carries the pushed files here,
		// the worst case: with the old stamps the push would pass.
		other(f)
		if err := Run(o); !errors.Is(err, ErrConflict) {
			t.Errorf("%s: err = %v, want the check against the live baseline", name, err)
		}
		if got := f.begins[len(f.begins)-1].Units[0].Base["main.php"].MTime; got != 1700000000 {
			t.Errorf("%s: base = %d, want the live baseline", name, got)
		}
		if _, err := os.Lstat(stampsPath(siteDir)); !errors.Is(err, os.ErrNotExist) {
			t.Errorf("%s: stamps of another copy stayed: %v", name, err)
		}
	}
}

// Requirement 6: whatever is wrong with the file, the push is checked against the live baseline.
func TestUnusableStampsFallBackToTheLiveBaseline(t *testing.T) {
	rewrite := func(change func(s *stagingBase)) func(t *testing.T, f *fakeSite, siteDir string) {
		return func(t *testing.T, f *fakeSite, siteDir string) {
			s := remembered(t, f, siteDir)
			change(s)
			if err := saveStagingBase(siteDir, "secret", f.srv.URL, s); err == nil {
				t.Fatal("stamps with such a path were written")
			}
			// written past the check, as code in the containers could – with a valid seal even
			data, _ := json.Marshal(s.sealed("secret", f.srv.URL))
			if err := os.WriteFile(stampsPath(siteDir), data, 0o644); err != nil {
				t.Fatal(err)
			}
		}
	}
	cases := map[string]func(t *testing.T, f *fakeSite, siteDir string){
		"not json": func(t *testing.T, f *fakeSite, siteDir string) {
			os.WriteFile(stampsPath(siteDir), []byte("{\"copy\":"), 0o644)
		},
		"empty": func(t *testing.T, f *fakeSite, siteDir string) {
			os.WriteFile(stampsPath(siteDir), nil, 0o644)
		},
		// Someone changed the copy and the file to match: without the seal the push would pass.
		"stamps edited": func(t *testing.T, f *fakeSite, siteDir string) {
			data, _ := os.ReadFile(stampsPath(siteDir))
			data = bytes.ReplaceAll(data, []byte("1800000000"), []byte("1800000050"))
			os.WriteFile(stampsPath(siteDir), data, 0o644)
			f.copy["plugins/x"]["main.php"] = agentapi.PushStamp{Size: f.copy["plugins/x"]["main.php"].Size, MTime: 1800000050}
		},
		"sealed with another secret": func(t *testing.T, f *fakeSite, siteDir string) {
			s := remembered(t, f, siteDir)
			if err := saveStagingBase(siteDir, "other", f.srv.URL, s); err != nil {
				t.Fatal(err)
			}
		},
		"stamps of another site": func(t *testing.T, f *fakeSite, siteDir string) {
			s := remembered(t, f, siteDir)
			if err := saveStagingBase(siteDir, "secret", "https://other.example", s); err != nil {
				t.Fatal(err)
			}
		},
		"path with ..": rewrite(func(s *stagingBase) {
			s.Units["plugins/x"].Files["../../../wp-config.php"] = baseline.FileStamp{Size: 1, MTime: 1}
		}),
		"absolute path": rewrite(func(s *stagingBase) {
			s.Units["plugins/x"].Files["/etc/passwd"] = baseline.FileStamp{Size: 1, MTime: 1}
		}),
		"unit outside wp-content": rewrite(func(s *stagingBase) {
			s.Units["plugins/../../x"] = s.Units["plugins/x"]
		}),
		"a symlink": func(t *testing.T, f *fakeSite, siteDir string) {
			elsewhere := filepath.Join(t.TempDir(), "stamps.json")
			data, _ := os.ReadFile(stampsPath(siteDir))
			os.WriteFile(elsewhere, data, 0o644)
			os.Remove(stampsPath(siteDir))
			if err := os.Symlink(elsewhere, stampsPath(siteDir)); err != nil {
				t.Fatal(err)
			}
		},
	}
	for name, spoil := range cases {
		t.Run(name, func(t *testing.T) {
			f, o, siteDir, out := copiedSite(t)
			mustPush(t, o, out)
			editAgain(t, siteDir)
			spoil(t, f, siteDir)
			if err := Run(o); !errors.Is(err, ErrConflict) {
				t.Fatalf("err = %v, want the check against the live baseline\n%s", err, out)
			}
			for _, begin := range f.begins[2:] {
				for rel, stamp := range begin.Units[0].Base {
					if stamp.MTime != 1700000000 || strings.Contains(rel, "..") || strings.HasPrefix(rel, "/") {
						t.Errorf("base %s = %+v, want only the live baseline", rel, stamp)
					}
				}
			}
			if _, err := os.Lstat(stampsPath(siteDir)); !errors.Is(err, os.ErrNotExist) {
				t.Errorf("the unusable file stayed: %v", err)
			}
		})
	}
}

// Without an answer about the copy nobody knows whose stamps these are: they are not used, and
// a push that goes through anyway leaves none behind.
func TestWithoutTheCopysIdentityTheLiveBaselineDecides(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	mustPush(t, o, out)
	editAgain(t, siteDir)
	f.noStatus = true
	if err := Run(o); !errors.Is(err, ErrConflict) {
		t.Fatalf("err = %v, want the check against the live baseline", err)
	}
	if got := f.begins[len(f.begins)-1].Units[0].Base["main.php"].MTime; got != 1700000000 {
		t.Errorf("base = %d, want the live baseline", got)
	}
	o.Force = true
	mustPush(t, o, out)
	if _, err := os.Lstat(stampsPath(siteDir)); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("stamps of an unknown copy stayed: %v", err)
	}
}

// Requirement 3: only the pushed units change; a unit never pushed to staging keeps the live baseline.
func TestStampsOfOtherUnitsStay(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	write(t, filepath.Join(siteDir, "public"), "themes/t/style.css", "/* Theme Name: T */\n/* edited */", 1800000000)
	o.Units = []string{"themes/t"}
	mustPush(t, o, out)
	theme := *remembered(t, f, siteDir).Units["themes/t"]

	o.Units = []string{"plugins/x"}
	f.ids = []string{secondID}
	mustPush(t, o, out)
	begin := f.begins[len(f.begins)-1]
	if len(begin.Units) != 1 || begin.Units[0].Base["main.php"].MTime != 1700000000 {
		t.Errorf("a unit never pushed to staging is not checked against the live baseline: %+v", begin.Units)
	}
	s := remembered(t, f, siteDir)
	if got := s.Units["themes/t"]; got == nil || got.PushID != theme.PushID || got.Files["style.css"] != theme.Files["style.css"] {
		t.Errorf("stamps of themes/t = %+v, want %+v", got, theme)
	}
	if got := s.Units["plugins/x"]; got == nil || got.PushID != secondID {
		t.Errorf("stamps of plugins/x = %+v", got)
	}
}

// A unit the baseline does not know goes to staging twice as well.
func TestNewUnitGoesToStagingTwice(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	docroot := filepath.Join(siteDir, "public")
	write(t, docroot, "plugins/neu/neu.php", "<?php\n/* Plugin Name: Neu */", 1800000000)
	o.Units = []string{"plugins/neu"}
	mustPush(t, o, out)
	write(t, docroot, "plugins/neu/neu.php", "<?php\n/* Plugin Name: Neu */\n// more", 1800000100)
	f.ids = []string{secondID}
	mustPush(t, o, out)
	if got := f.begins[len(f.begins)-1].Units[0].Base["neu.php"].MTime; got != 1800000000 {
		t.Errorf("base = %d", got)
	}
}

// Requirement 3: after a rollback the copy holds the state before the push again, and so do the stamps.
func TestRollbackPutsTheStampsBack(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	mustPush(t, o, out)
	editAgain(t, siteDir)
	f.ids = []string{secondID}
	mustPush(t, o, out)

	baseBefore, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json"))
	if err := Rollback(o, secondID); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	got := remembered(t, f, siteDir).Units["plugins/x"]
	if got == nil || got.PushID != testID || got.Files["main.php"].MTime != 1800000000 || got.Before != nil {
		t.Fatalf("stamps after the rollback = %+v, want those of the first push", got)
	}
	if baseAfter, _ := os.ReadFile(filepath.Join(siteDir, ".wpsync", "baseline.json")); !bytes.Equal(baseBefore, baseAfter) {
		t.Error("the rollback of a staging push changed the baseline")
	}
	// push again without --force: the copy is at the first push, and the stamps say so
	f.ids = []string{"p_20261007_cccccccccccc"}
	mustPush(t, o, out)

	// A rollback of the only push to staging: the unit is as the copy was made, the live baseline counts.
	f, o, siteDir, out = copiedSite(t)
	mustPush(t, o, out)
	if err := Rollback(o, testID); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if s := remembered(t, f, siteDir); s == nil || len(s.Units) != 0 {
		t.Errorf("stamps after the rollback of the first push = %+v", s)
	}
	mustPush(t, o, out)
	if got := f.begins[len(f.begins)-1].Units[0].Base["main.php"].MTime; got != 1700000000 {
		t.Errorf("base = %d, want the live baseline", got)
	}
}

// A rollback through rescue.php, when WordPress no longer answers, puts the stamps back as well;
// the rollback of a push this machine holds no stamps of leaves them alone.
func TestRollbackOverRescueAndOfForeignPushes(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	mustPush(t, o, out)
	editAgain(t, siteDir)
	f.ids = []string{secondID}
	mustPush(t, o, out)
	f.rollback = 500
	if err := Rollback(o, secondID); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := remembered(t, f, siteDir).Units["plugins/x"]; got == nil || got.PushID != testID {
		t.Errorf("stamps after the rollback over rescue.php = %+v", got)
	}

	f.rollback = 200
	kept, _ := os.ReadFile(stampsPath(siteDir))
	f.list = pushList(pushEntry(stagingID, "staging", 2))
	o.Target = TargetStaging
	if err := Rollback(o, stagingID); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if now, _ := os.ReadFile(stampsPath(siteDir)); !bytes.Equal(kept, now) {
		t.Error("the rollback of a push from another machine changed the stamps")
	}
}

// A push that made the copy worse is rolled back before it is confirmed: the stamps stay as they were.
func TestRolledBackPushKeepsTheStamps(t *testing.T) {
	f, o, siteDir, out := copiedSite(t)
	mustPush(t, o, out)
	kept, _ := os.ReadFile(stampsPath(siteDir))
	editAgain(t, siteDir)
	f.ids = []string{secondID}
	f.broken, f.committed = true, false // healthy before the swap, broken after it
	var rolled *RolledBackError
	if err := Run(o); !errors.As(err, &rolled) {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if now, _ := os.ReadFile(stampsPath(siteDir)); !bytes.Equal(kept, now) {
		t.Error("a push that was rolled back changed the stamps")
	}
	f.broken = false
	f.ids = []string{"p_20261007_cccccccccccc"}
	mustPush(t, o, out) // and the fixed state goes out without --force
}

// The agent's stamps are stored only with paths a baseline could hold.
func TestStampsFromTheAgentAreChecked(t *testing.T) {
	for rel, ok := range map[string]bool{
		"main.php": true, "inc/a b.php": true, "inc/ä.php": true, ".htaccess": true,
		"": false, "/abs.php": false, "../x.php": false, "inc/../../x.php": false, "inc//x.php": false,
		"./x.php": false, "inc/..": false, "inc/": false, "..\\x.php": false, "inc\\..\\..\\x.php": false,
		"a\x00b.php": false, "a\xffb.php": false,
		// legal on the server and only ever sent back to the agent as a key, never shown or opened here
		"inc\\x.php": true, "x\x1b[2J.php": true,
	} {
		if validRel(rel) != ok {
			t.Errorf("validRel(%q) = %v", rel, !ok)
		}
	}
	f, o, siteDir, out := copiedSite(t)
	mustPush(t, o, out)
	s := remembered(t, f, siteDir)
	id := s.Copy
	// an agent that answers the commit with a path outside the unit: nothing of that unit is kept
	if err := o.defaults().rememberStaging(siteDir, &id, s, secondID, []string{"plugins/x"}, map[string]map[string]agentapi.PushStamp{
		"plugins/x": {"../../wp-config.php": {Size: 1, MTime: 1}},
	}); err != nil {
		t.Fatal(err)
	}
	if got := remembered(t, f, siteDir); got == nil || got.Units["plugins/x"] != nil {
		t.Errorf("stamps = %+v", got)
	}
}
