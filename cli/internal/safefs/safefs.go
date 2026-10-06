// Package safefs writes into folders that someone else can write to as well: the docroot (site
// code as www-data, later an AI) and, on the Mac, the whole site folder in the DDEV mount. Every
// operation runs through an os.Root, so nothing leaves the root even if a folder is swapped for a
// symlink in between, and every folder on the way is checked with Lstat: a symlink is refused
// even if it stays inside the root (SEC-113). The final element is never followed: a file that is
// a symlink is replaced or removed itself.
package safefs

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// ErrSymlink: a folder on the way is a symlink (or no folder); wpsync neither writes nor deletes there.
var ErrSymlink = errors.New("symbolischer Link (oder Datei statt Ordner) im Pfad – wpsync schreibt, liest und löscht dort nicht")

// TmpSuffix marks the temporary file of WriteFile; the snapshot ignores it.
const TmpSuffix = ".wpsync-tmp"

// parents returns the folders on the way to rel: "a/b/c" → "a", "a/b".
func parents(rel string) []string {
	parts := strings.Split(filepath.ToSlash(filepath.Clean(rel)), "/")
	out := make([]string, 0, len(parts))
	for i := 1; i < len(parts); i++ {
		out = append(out, filepath.Join(parts[:i]...))
	}
	return out
}

func checkDir(r *os.Root, rel string) error {
	info, err := r.Lstat(rel)
	if err != nil {
		return err
	}
	if !info.IsDir() {
		return fmt.Errorf("%s: %w", rel, ErrSymlink)
	}
	return nil
}

// CheckParents verifies that every folder on the way to rel exists and is a real folder.
func CheckParents(r *os.Root, rel string) error {
	for _, dir := range parents(rel) {
		if err := checkDir(r, dir); err != nil {
			return err
		}
	}
	return nil
}

// MkdirAll creates rel and the folders on the way; an existing symlink is refused.
func MkdirAll(r *os.Root, rel string, perm fs.FileMode) error {
	clean := filepath.Clean(rel)
	if clean == "." {
		return nil
	}
	for _, dir := range append(parents(clean), clean) {
		err := r.Mkdir(dir, perm)
		if err != nil && !errors.Is(err, fs.ErrExist) {
			return err
		}
		if err := checkDir(r, dir); err != nil {
			return err
		}
	}
	return nil
}

// OpenTree opens base/rel as a root, creating it first; no folder below base may be a symlink.
func OpenTree(base, rel string) (*os.Root, error) {
	r, err := os.OpenRoot(base)
	if err != nil {
		return nil, err
	}
	defer r.Close()
	if err := MkdirAll(r, rel, 0o755); err != nil {
		return nil, err
	}
	return r.OpenRoot(rel)
}

// Lstat describes rel without following it; a symlinked folder on the way is ErrSymlink.
func Lstat(r *os.Root, rel string) (fs.FileInfo, error) {
	if err := CheckParents(r, rel); err != nil {
		return nil, err
	}
	return r.Lstat(rel)
}

// Remove deletes rel itself (a symlink, not its target); a symlinked folder on the way is ErrSymlink.
func Remove(r *os.Root, rel string) error {
	if err := CheckParents(r, rel); err != nil {
		return err
	}
	return r.Remove(rel)
}

// WriteFile writes src to rel atomically: into a fresh rel+TmpSuffix (O_EXCL, never through a
// symlink), then renamed over rel. size < 0 accepts any length; a zero mtime keeps the current time.
func WriteFile(r *os.Root, rel string, src io.Reader, size int64, mtime time.Time, perm fs.FileMode) error {
	if dir := filepath.Dir(rel); dir != "." {
		if err := MkdirAll(r, dir, 0o755); err != nil {
			return err
		}
	}
	tmp := rel + TmpSuffix
	if err := r.Remove(tmp); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	f, err := r.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, perm)
	if err != nil {
		return err
	}
	n, copyErr := io.Copy(f, src)
	closeErr := f.Close()
	if copyErr != nil || closeErr != nil || (size >= 0 && n != size) {
		r.Remove(tmp)
		// %w keeps ENOSPC & co. for the exit code (disk_full); a short body has neither error.
		if err := errors.Join(copyErr, closeErr); err != nil {
			return fmt.Errorf("write %s: %d/%d bytes: %w", rel, n, size, err)
		}
		return fmt.Errorf("write %s: %d/%d bytes", rel, n, size)
	}
	if !mtime.IsZero() {
		if err := r.Chtimes(tmp, mtime, mtime); err != nil {
			r.Remove(tmp)
			return err
		}
	}
	// The folders may have changed while the body was copied; the root still keeps the rename inside.
	if err := CheckParents(r, rel); err != nil {
		r.Remove(tmp)
		return err
	}
	return r.Rename(tmp, rel)
}

// ReadFile reads rel without following a symlink at rel or on the way.
func ReadFile(r *os.Root, rel string) ([]byte, error) {
	f, err := Open(r, rel)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	return io.ReadAll(f)
}

// Open opens rel for reading; rel and the folders on the way must not be symlinks.
func Open(r *os.Root, rel string) (*os.File, error) {
	info, err := Lstat(r, rel)
	if err != nil {
		return nil, err
	}
	if info.Mode()&fs.ModeSymlink != 0 {
		return nil, fmt.Errorf("%s: %w", rel, ErrSymlink)
	}
	f, err := r.Open(rel)
	if err != nil {
		return nil, err
	}
	// Swapped between Lstat and Open: the open file must be the one Lstat saw.
	if now, err := f.Stat(); err != nil || !os.SameFile(info, now) {
		f.Close()
		return nil, fmt.Errorf("%s changed while opening: %w", rel, ErrSymlink)
	}
	return f, nil
}
