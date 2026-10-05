package push

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"syscall"
)

var (
	// ErrSymlink: a directory between the site folder and a unit is a symlink. Code in the local
	// containers can create one and point it at another site, whose files would then go live (M1).
	ErrSymlink = errors.New("ist ein symbolischer Link – wpsync pusht nur aus echten Verzeichnissen der Site")
	// ErrChanged: a file or directory is no longer the one the scan saw.
	ErrChanged = errors.New("hat sich während des Pushs geändert")
)

// unitSteps lists the directories from docroot down to the unit, relative to docroot.
func unitSteps(unit string) []string {
	if unit == muPlugins {
		return []string{".", "wp-content", "wp-content/" + muPlugins}
	}
	kind := filepath.Dir(unit)
	return []string{".", "wp-content", "wp-content/" + kind, "wp-content/" + unit}
}

// showDir names a directory below docroot relative to the site folder, as the user knows it.
func showDir(docroot, rel string) string {
	return filepath.ToSlash(filepath.Join(filepath.Base(docroot), rel))
}

// realDir checks one directory below docroot without following it. A symlink is an error;
// ok is false if the path is missing or not a directory.
func realDir(docroot, rel string) (info fs.FileInfo, ok bool, err error) {
	info, err = os.Lstat(filepath.Join(docroot, filepath.FromSlash(rel)))
	switch {
	case errors.Is(err, fs.ErrNotExist):
		return nil, false, nil
	case err != nil:
		return nil, false, err
	case info.Mode()&fs.ModeSymlink != 0:
		return nil, false, fmt.Errorf("%s %w, Push abgebrochen", showDir(docroot, rel), ErrSymlink)
	}
	return info, info.IsDir(), nil
}

// openUnit opens a unit as os.Root after checking that every directory from docroot down to it
// is real. The root pins the directory: later opens cannot leave it, even if a directory inside
// is swapped for a symlink. want is the unit directory as the scan saw it (nil during the scan).
func openUnit(docroot, unit string, want fs.FileInfo) (*os.Root, fs.FileInfo, error) {
	var info fs.FileInfo
	for _, rel := range unitSteps(unit) {
		var ok bool
		var err error
		if info, ok, err = realDir(docroot, rel); err != nil {
			return nil, nil, err
		} else if !ok {
			return nil, nil, fmt.Errorf("wp-content/%s %w", unit, ErrChanged)
		}
	}
	root, err := os.OpenRoot(filepath.Join(docroot, "wp-content", filepath.FromSlash(unit)))
	if err != nil {
		return nil, nil, err
	}
	opened, err := root.Stat(".")
	if err != nil || !os.SameFile(opened, info) || (want != nil && !os.SameFile(opened, want)) {
		root.Close()
		return nil, nil, fmt.Errorf("wp-content/%s %w", unit, ErrChanged)
	}
	return root, opened, nil
}

// openFile opens a file of the unit without following a symlink and checks that it is still the
// regular file the scan saw: same inode as a moment ago, same size and mtime as in the scan.
func openFile(root *os.Root, unit, rel string, want LocalFile) (*os.File, error) {
	changed := fmt.Errorf("%s/%s %w", unit, rel, ErrChanged)
	name := filepath.FromSlash(rel)
	before, err := root.Lstat(name)
	if err != nil || !before.Mode().IsRegular() {
		return nil, changed
	}
	file, err := root.OpenFile(name, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return nil, changed
	}
	info, err := file.Stat()
	if err != nil || !os.SameFile(before, info) || !info.Mode().IsRegular() ||
		info.Size() != want.Size || info.ModTime().Unix() != want.MTime {
		file.Close()
		return nil, changed
	}
	return file, nil
}
