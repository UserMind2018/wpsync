package localgit

import (
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

func write(t *testing.T, root, rel string) {
	t.Helper()
	p := filepath.Join(root, rel)
	os.MkdirAll(filepath.Dir(p), 0o755)
	os.WriteFile(p, []byte(rel), 0o644)
}

func TestCommitTracksOnlyCodeAndBaseline(t *testing.T) {
	dir := t.TempDir()
	for _, f := range []string{
		"public/wp-content/plugins/a/a.php",
		"public/wp-content/themes/t/style.css",
		"public/wp-content/uploads/2026/x.jpg",
		"public/wp-content/cache/borlabs-cookie/1/x.css",
		"public/wp-config.php",
		"public/wp-includes/version.php",
		".wpsync/baseline.json",
		".wpsync/db/tables/wp_posts.sql",
		".ddev/config.yaml",
	} {
		write(t, dir, f)
	}

	if err := Commit(dir, "pull 1"); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command("git", "-C", dir, "ls-files").Output()
	if err != nil {
		t.Fatal(err)
	}
	got := strings.Fields(string(out))
	want := []string{".gitignore", ".wpsync/baseline.json", "public/wp-content/plugins/a/a.php", "public/wp-content/themes/t/style.css"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("tracked = %v, want %v", got, want)
	}

	if err := Commit(dir, "pull 2"); err != nil {
		t.Fatalf("second commit without changes must succeed: %v", err)
	}
	log, _ := exec.Command("git", "-C", dir, "log", "--format=%s").Output()
	if strings.Count(string(log), "pull") != 2 {
		t.Fatalf("log = %s", log)
	}
}
