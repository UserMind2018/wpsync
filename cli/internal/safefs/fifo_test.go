package safefs

import (
	"errors"
	"os"
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

// Security-Audit F-1: Open blockiert nicht an einer FIFO und gibt nur reguläre Dateien heraus.
func TestOpenRefusesFifoWithoutBlocking(t *testing.T) {
	dir := t.TempDir()
	if err := syscall.Mkfifo(filepath.Join(dir, "p"), 0o644); err != nil {
		t.Fatal(err)
	}
	r := openRoot(t, dir)
	done := make(chan error, 1)
	go func() {
		f, err := Open(r, "p")
		if f != nil {
			f.Close()
		}
		done <- err
	}()
	select {
	case err := <-done:
		if !errors.Is(err, ErrNotRegular) {
			t.Fatalf("Open(fifo) = %v, want ErrNotRegular", err)
		}
	case <-time.After(10 * time.Second):
		if f, err := os.OpenFile(filepath.Join(dir, "p"), os.O_WRONLY|syscall.O_NONBLOCK, 0); err == nil {
			f.Close()
		}
		t.Fatal("Open hangs on a FIFO")
	}
}
