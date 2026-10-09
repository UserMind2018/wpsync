package push

import (
	"fmt"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

func TestReadUploadList(t *testing.T) {
	dir := t.TempDir()
	file := filepath.Join(dir, "uploads.txt")
	if err := os.WriteFile(file, []byte("# vom Studio\r\n2026/10/a.png\r\n\n  2026/10/b.png  \n2026/10/a.png\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	list, err := ReadUploadList(file)
	if err != nil || !reflect.DeepEqual(list, []string{"2026/10/a.png", "2026/10/b.png"}) {
		t.Fatalf("list = %v, %v", list, err)
	}
	for name, content := range map[string]string{
		"leer":          "# nur ein Kommentar\n\n",
		"ausbruch":      "2026/10/a.png\n../wp-config.php\n",
		"absolut":       "/etc/passwd\n",
		"staging":       "wpsync-staging-0123456789ab/a.png\n",
		"steuerzeichen": "2026/10/a\x1b[2J.png\n",
	} {
		f := filepath.Join(dir, name)
		if err := os.WriteFile(f, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
		if _, err := ReadUploadList(f); err == nil {
			t.Errorf("%s: want an error", name)
		}
	}
	if _, err := ReadUploadList(filepath.Join(dir, "fehlt.txt")); err == nil {
		t.Error("a missing list must fail")
	}
	var many strings.Builder
	for i := 0; i <= MaxUploads; i++ {
		fmt.Fprintf(&many, "2026/10/%d.png\n", i)
	}
	if err := os.WriteFile(filepath.Join(dir, "viele"), []byte(many.String()), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := ReadUploadList(filepath.Join(dir, "viele")); err == nil || !strings.Contains(err.Error(), "5000") {
		t.Errorf("err = %v", err)
	}
}

func TestReadUploadListNamesTheLine(t *testing.T) {
	file := filepath.Join(t.TempDir(), "uploads.txt")
	if err := os.WriteFile(file, []byte("a.png\n# x\n../b.png\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := ReadUploadList(file); err == nil || !strings.Contains(err.Error(), "Zeile 3") {
		t.Fatalf("err = %v", err)
	}
}

// Wie PushUploads::validFile und PushUploads::blockedName im Agent.
func TestUploadRulesMirrorTheAgent(t *testing.T) {
	for _, rel := range []string{"2026/10/bild.jpg", "elementor/css/post-12.css", "größe-ä.png"} {
		if !ValidUploadPath(rel) {
			t.Errorf("%q should be valid", rel)
		}
	}
	for _, rel := range []string{"", "../x.jpg", "/2026/x.jpg", "2026//x.jpg", "./x.jpg", `a\b.jpg`, ".git/x.jpg", "wpsync-staging-0123456789ab/x.jpg", "2026/WPSYNC-PUSH-0123456789abcdef/x.jpg", "x‮gpj.exe"} {
		if ValidUploadPath(rel) {
			t.Errorf("%q should be invalid", rel)
		}
	}
	for _, rel := range []string{"x.php", "2026/10/X.PHP", "x.php7", "x.phtml", "x.phar", "x.pht", "x.phps", "bild.php.jpg", ".htaccess", "2026/.htaccess", ".user.ini", "2026/10/.versteckt.jpg", "debug.log", "dump.sql", "dump.sql.gz", ".env", "2026/.env.local", ".htpasswd"} {
		if !BlockedUpload(rel) {
			t.Errorf("%q should be blocked", rel)
		}
	}
	for _, rel := range []string{"bild.jpg", "bericht.pdf", "php-handbuch.pdf", "alphabet.png", "x.phpx", "archiv.zip", "bild-300x200.jpg", "Foto_2026.JPG", "foto.jpg.png", "scan.2026.pdf", "html-kurs.pdf"} {
		if BlockedUpload(rel) {
			t.Errorf("%q should not be blocked", rel)
		}
	}
}

// Aktive Typen sind immer gesperrt, egal was upload_mimes auf der Site erlaubt (Finding 2).
func TestActiveUploadTypesAreBlocked(t *testing.T) {
	for _, rel := range []string{"x.svg", "x.SVG", "x.svgz", "x.htm", "x.html", "x.xhtml", "x.xml", "x.js", "x.mjs", "x.shtml", "2026/10/bild.svg.png"} {
		if !BlockedUpload(rel) {
			t.Errorf("%q should be blocked", rel)
		}
	}
}

// Versteckte mittlere Endungen, die WordPress' sanitize_file_name() umbenennen würde (Finding 1).
func TestHiddenMiddleExtensionsAreBlocked(t *testing.T) {
	for _, rel := range []string{"bild.html.jpg", "bild.shtml.jpg", "bild.cgi.png", "2026/10/bild.pl.gif", "foto.final.v2.jpg", "style.min.css"} {
		if !BlockedUpload(rel) {
			t.Errorf("%q should be blocked", rel)
		}
	}
}

// Apply ersetzt Einheiten ganz – uploads nie; ApplyUploads und RevertUploads fassen nur die Dateien des Pushs an.
func TestApplyAndRevertUploadsTouchOnlyTheirFiles(t *testing.T) {
	b := baseline.New("https://kunde.de")
	b.Files["wp-content/uploads/2020/01/alt.jpg"] = baseline.FileStamp{Size: 1, MTime: 1}
	b.Files["wp-content/uploads/2026/10/b.png"] = baseline.FileStamp{Size: 2, MTime: 2} // in der Baseline, auf der Site gelöscht
	j := NewJournal(testID, "https://kunde.de/rescue.php", testSalt, b, nil)
	j.NoteUploads(b, []string{"2026/10/a.png", "2026/10/b.png"})
	stamps := map[string]map[string]agentapi.PushStamp{UploadsUnit: {"2026/10/a.png": {Size: 3, MTime: 3}, "2026/10/b.png": {Size: 4, MTime: 4}}}

	Apply(b, stamps)
	if len(b.Files) != 2 {
		t.Fatalf("Apply must leave uploads to ApplyUploads: %v", b.Files)
	}
	ApplyUploads(b, stamps[UploadsUnit])
	if b.Files["wp-content/uploads/2026/10/a.png"] != (baseline.FileStamp{Size: 3, MTime: 3}) || len(b.Files) != 3 {
		t.Fatalf("after ApplyUploads: %v", b.Files)
	}
	RevertUploads(b, j, nil)
	want := map[string]baseline.FileStamp{
		"wp-content/uploads/2020/01/alt.jpg": {Size: 1, MTime: 1},
		"wp-content/uploads/2026/10/b.png":   {Size: 2, MTime: 2},
	}
	if !reflect.DeepEqual(b.Files, want) {
		t.Fatalf("after RevertUploads: %v", b.Files)
	}
	ApplyUploads(b, stamps[UploadsUnit])
	RevertUploads(b, j, []string{"2026/10/a.png"})
	if b.Files["wp-content/uploads/2026/10/a.png"].Size != 3 {
		t.Error("a file the rollback left on the site stays in the baseline")
	}
}
