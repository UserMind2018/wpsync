package push

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

func TestApplyAndRevertBaseline(t *testing.T) {
	b := baseline.New("https://kunde.de")
	b.Files["wp-content/plugins/x/a.php"] = baseline.FileStamp{Size: 1, MTime: 1}
	b.Files["wp-content/plugins/x/gone.php"] = baseline.FileStamp{Size: 2, MTime: 2}
	b.Files["wp-content/plugins/xy/keep.php"] = baseline.FileStamp{Size: 3, MTime: 3}
	b.Files["wp-content/uploads/a.jpg"] = baseline.FileStamp{Size: 4, MTime: 4}

	j := NewJournal("p_20261005_0123456789ab", "https://kunde.de/rescue.php", testSalt, b, []string{"plugins/x", "plugins/neu"})
	if len(j.Units["plugins/x"]) != 2 || len(j.Units["plugins/neu"]) != 0 {
		t.Fatalf("journal units = %v", j.Units)
	}

	Apply(b, map[string]map[string]agentapi.PushStamp{
		"plugins/x":   {"a.php": {Size: 10, MTime: 10}, "new.php": {Size: 11, MTime: 11}},
		"plugins/neu": {"neu.php": {Size: 12, MTime: 12}},
	})
	if len(b.Files) != 5 || b.Files["wp-content/plugins/x/a.php"].Size != 10 || b.Files["wp-content/plugins/neu/neu.php"].MTime != 12 {
		t.Errorf("after apply: %v", b.Files)
	}
	if _, ok := b.Files["wp-content/plugins/x/gone.php"]; ok {
		t.Error("file removed by the push still in the baseline")
	}
	if b.Files["wp-content/plugins/xy/keep.php"].Size != 3 {
		t.Error("plugins/xy must not be touched by plugins/x")
	}

	Revert(b, j)
	if len(b.Files) != 4 || b.Files["wp-content/plugins/x/a.php"].Size != 1 || b.Files["wp-content/plugins/x/gone.php"].Size != 2 {
		t.Errorf("after revert: %v", b.Files)
	}
	if _, ok := b.Files["wp-content/plugins/neu/neu.php"]; ok {
		t.Error("new unit still in the baseline after revert")
	}
}

func TestJournalRoundTrip(t *testing.T) {
	dir := t.TempDir()
	b := baseline.New("https://kunde.de")
	b.Files["wp-content/themes/t/style.css"] = baseline.FileStamp{Size: 1, MTime: 1}
	j := NewJournal("p_20261005_0123456789ab", "https://kunde.de/rescue.php", testSalt, b, []string{"themes/t"})
	j.Applied = true
	if err := SaveJournal(dir, j); err != nil {
		t.Fatal(err)
	}
	got, err := LoadJournal(dir, "p_20261005_0123456789ab")
	if err != nil || !got.Applied || got.Salt != testSalt || got.Units["themes/t"]["wp-content/themes/t/style.css"].Size != 1 {
		t.Fatalf("got %+v, %v", got, err)
	}
	if _, err := LoadJournal(dir, "p_20261005_ffffffffffff"); err == nil {
		t.Error("missing journal must be an error")
	}
	if _, err := LoadJournal(dir, "../../etc/passwd"); err == nil {
		t.Error("push ids are validated")
	}
	if id := LatestJournal(dir); id != "p_20261005_0123456789ab" {
		t.Errorf("LatestJournal = %q", id)
	}
}

func TestSaveJournalRefusesASaltOfAnotherFormat(t *testing.T) {
	j := NewJournal(testID, "https://kunde.de/rescue.php", "salt", baseline.New("https://kunde.de"), nil)
	if err := SaveJournal(t.TempDir(), j); err == nil {
		t.Error("a salt the agent never produces was accepted")
	}
}

// Nach-Review M-2: <site>/.wpsync/pushes liegt auf dem Mac im DDEV-Mount. Symlinks dort führen
// weder beim Schreiben noch beim Lesen aus dem Ordner hinaus.
func TestJournalNeverFollowsSymlinks(t *testing.T) {
	siteDir, outside := t.TempDir(), t.TempDir()
	victim := filepath.Join(outside, "victim")
	os.WriteFile(victim, []byte("keep"), 0o644)
	dir := filepath.Join(siteDir, ".wpsync", "pushes")
	os.MkdirAll(dir, 0o700)
	os.Symlink(victim, filepath.Join(dir, testID+".json.tmp"))
	os.Symlink(victim, filepath.Join(dir, testID+".json.wpsync-tmp"))
	os.Symlink(victim, filepath.Join(dir, testID+".json"))
	j := NewJournal(testID, "https://kunde.de/rescue.php", testSalt, baseline.New("https://kunde.de"), nil)
	if err := SaveJournal(siteDir, j); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(victim); string(b) != "keep" {
		t.Fatalf("SaveJournal wrote through a symlink: %q", b)
	}

	// a journal that is a symlink to a file outside is not read
	other := "p_20261005_aaaaaaaaaaaa"
	data, _ := os.ReadFile(filepath.Join(dir, testID+".json"))
	foreign := filepath.Join(outside, "foreign.json")
	os.WriteFile(foreign, []byte(strings.ReplaceAll(string(data), testID, other)), 0o644)
	os.Symlink(foreign, filepath.Join(dir, other+".json"))
	if _, err := LoadJournal(siteDir, other); err == nil {
		t.Fatal("LoadJournal followed a symlink")
	}

	// a symlinked pushes folder is neither written nor read
	site2 := t.TempDir()
	os.MkdirAll(filepath.Join(site2, ".wpsync"), 0o700)
	os.Symlink(dir, filepath.Join(site2, ".wpsync", "pushes"))
	if err := SaveJournal(site2, j); err == nil {
		t.Fatal("SaveJournal into a symlinked folder must fail")
	}
	if _, err := LoadJournal(site2, testID); err == nil {
		t.Fatal("LoadJournal from a symlinked folder must fail")
	}
	if id := LatestJournal(site2); id != "" {
		t.Fatalf("LatestJournal through a symlink = %s", id)
	}
}
