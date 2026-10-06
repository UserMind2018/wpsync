package pull

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

type enospcReader struct{}

func (enospcReader) Read([]byte) (int, error) {
	return 0, &fs.PathError{Op: "write", Path: "/x", Err: syscall.ENOSPC}
}

// Review N1: ein voller Datenträger beim Schreiben einer Datei bleibt als ENOSPC erkennbar (Exit 21).
func TestWriteFileKeepsENOSPC(t *testing.T) {
	docroot := t.TempDir()
	os.MkdirAll(filepath.Join(docroot, "wp-content"), 0o755)
	err := writeFile(docroot, "wp-content/a.txt", enospcReader{}, 10, 1)
	if !errors.Is(err, syscall.ENOSPC) {
		t.Fatalf("ENOSPC lost in writeFile: %v", err)
	}
	if _, err := os.Lstat(filepath.Join(docroot, "wp-content", "a.txt.wpsync-tmp")); !os.IsNotExist(err) {
		t.Fatal("tmp file left behind")
	}
}
