package pull

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
)

// DownloadFiles fetches files in bundles of at most bundleBytes and writes them below docroot.
func DownloadFiles(c *agentapi.Client, docroot string, files []agentapi.File, bundleBytes int64, out io.Writer) error {
	done := 0
	for _, group := range bundles(files, bundleBytes) {
		paths := make([]string, len(group))
		for i, f := range group {
			paths[i] = f.Path
		}
		err := c.Files(paths, func(path string, size, mtime int64, body io.Reader) error {
			return writeFile(docroot, path, body, size, mtime)
		}, func(path string) {
			fmt.Fprintf(out, "  ! übersprungen (fehlt oder ungültig): %s\n", path)
		})
		if err != nil {
			return fmt.Errorf("bundle with %d files (first: %s): %w", len(paths), paths[0], err)
		}
		done += len(group)
		fmt.Fprintf(out, "  Dateien %d/%d\n", done, len(files))
	}
	return nil
}

// bundles groups files so a bundle stays below limit (a single larger file gets its own bundle).
func bundles(files []agentapi.File, limit int64) [][]agentapi.File {
	var out [][]agentapi.File
	var current []agentapi.File
	var size int64
	for _, f := range files {
		if len(current) > 0 && size+f.Size > limit {
			out = append(out, current)
			current, size = nil, 0
		}
		current = append(current, f)
		size += f.Size
	}
	if len(current) > 0 {
		out = append(out, current)
	}
	return out
}

// RemoveFiles deletes files that disappeared on the source.
func RemoveFiles(docroot string, paths []string) {
	for _, rel := range paths {
		if target, err := SafeJoin(docroot, rel); err == nil {
			os.Remove(target)
		}
	}
}

// PresentLocally reports whether a file already exists with identical size and mtime.
func PresentLocally(docroot string) func(agentapi.File) bool {
	return func(f agentapi.File) bool {
		target, err := SafeJoin(docroot, f.Path)
		if err != nil {
			return false
		}
		info, err := os.Stat(target)
		return err == nil && info.Size() == f.Size && info.ModTime().Unix() == f.MTime
	}
}

// SafeJoin rejects paths that would leave docroot/wp-content.
func SafeJoin(docroot, rel string) (string, error) {
	clean := filepath.Clean(rel)
	if !strings.HasPrefix(clean, "wp-content"+string(filepath.Separator)) || strings.Contains(clean, "..") {
		return "", fmt.Errorf("refusing path outside wp-content: %q", rel)
	}
	return filepath.Join(docroot, clean), nil
}

// writeFile writes atomically and sets the source mtime (needed for resume).
func writeFile(docroot, rel string, src io.Reader, size, mtime int64) error {
	target, err := SafeJoin(docroot, rel)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
		return err
	}
	tmp := target + ".wpsync-tmp"
	f, err := os.Create(tmp)
	if err != nil {
		return err
	}
	n, copyErr := io.Copy(f, src)
	closeErr := f.Close()
	if copyErr != nil || closeErr != nil || n != size {
		os.Remove(tmp)
		return fmt.Errorf("write %s: %d/%d bytes: %v %v", rel, n, size, copyErr, closeErr)
	}
	t := time.Unix(mtime, 0)
	if err := os.Chtimes(tmp, t, t); err != nil {
		os.Remove(tmp)
		return err
	}
	return os.Rename(tmp, target)
}
