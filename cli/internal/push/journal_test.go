package push

import (
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

	j := NewJournal("p_20261005_0123456789ab", "https://kunde.de/rescue.php", "salt", b, []string{"plugins/x", "plugins/neu"})
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
	j := NewJournal("p_20261005_0123456789ab", "https://kunde.de/rescue.php", "salt", b, []string{"themes/t"})
	j.Applied = true
	if err := SaveJournal(dir, j); err != nil {
		t.Fatal(err)
	}
	got, err := LoadJournal(dir, "p_20261005_0123456789ab")
	if err != nil || !got.Applied || got.Salt != "salt" || got.Units["themes/t"]["wp-content/themes/t/style.css"].Size != 1 {
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
