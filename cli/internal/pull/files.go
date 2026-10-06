package pull

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/safefs"
)

// DownloadFiles fetches files in bundles of at most bundleBytes and writes them below docroot.
// progress (may be nil) gets the number of finished files after every bundle.
func DownloadFiles(c *agentapi.Client, docroot string, files []agentapi.File, bundleBytes int64, out io.Writer, progress func(done, total int)) error {
	if len(files) == 0 {
		return nil
	}
	// The site folder belongs to wpsync (the site only reaches the docroot, on the Mac its content).
	if err := os.MkdirAll(filepath.Dir(filepath.Clean(docroot)), 0o755); err != nil {
		return err
	}
	root, base, err := openDocroot(docroot)
	if err != nil {
		return err
	}
	defer root.Close()
	done := 0
	for _, group := range bundles(files, bundleBytes) {
		paths := make([]string, len(group))
		for i, f := range group {
			paths[i] = f.Path
		}
		err := c.Files(paths, func(path string, size, mtime int64, body io.Reader) error {
			return writeFileIn(root, base, path, body, size, mtime)
		}, func(path string) {
			fmt.Fprintf(out, "  ! übersprungen (fehlt oder ungültig): %s\n", path)
		})
		if err != nil {
			return fmt.Errorf("bundle with %d files (first: %s): %w", len(paths), paths[0], err)
		}
		done += len(group)
		fmt.Fprintf(out, "  Dateien %d/%d\n", done, len(files))
		if progress != nil {
			progress(done, len(files))
		}
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

// openDocroot opens the folder that holds docroot as the root of every file operation of a pull,
// so the docroot itself is checked like every folder below it (Mac: public/ lies in the DDEV
// mount, the site could swap it for a symlink). base is the docroot's name in that root.
func openDocroot(docroot string) (*os.Root, string, error) {
	root, err := os.OpenRoot(filepath.Dir(filepath.Clean(docroot)))
	if err != nil {
		return nil, "", err
	}
	return root, filepath.Base(filepath.Clean(docroot)), nil
}

// docrootRel checks rel like SafeJoin and returns it relative to the root of openDocroot.
func docrootRel(base, rel string) (string, error) {
	if _, err := SafeJoin("/", rel); err != nil {
		return "", err
	}
	return filepath.Join(base, filepath.Clean(rel)), nil
}

// RemoveFiles deletes files that disappeared on the source. A symlink is removed itself; a path
// through a symlinked folder is skipped (SEC-113).
func RemoveFiles(docroot string, paths []string) {
	root, base, err := openDocroot(docroot)
	if err != nil {
		return
	}
	defer root.Close()
	for _, rel := range paths {
		if target, err := docrootRel(base, rel); err == nil {
			safefs.Remove(root, target)
		}
	}
}

// PresentLocally reports whether a regular file already exists with identical size and mtime.
// Symlinks never count: neither the file nor a folder on the way is followed.
func PresentLocally(docroot string) func(agentapi.File) bool {
	return func(f agentapi.File) bool {
		root, base, err := openDocroot(docroot)
		if err != nil {
			return false
		}
		defer root.Close()
		target, err := docrootRel(base, f.Path)
		if err != nil {
			return false
		}
		info, err := safefs.Lstat(root, target)
		return err == nil && info.Mode().IsRegular() && info.Size() == f.Size && info.ModTime().Unix() == f.MTime
	}
}

// SafeJoin rejects paths that would leave docroot/wp-content and VCS paths (W11): a pull never
// writes or deletes .git/.svn/.hg, not even when the agent sends one unasked.
func SafeJoin(docroot, rel string) (string, error) {
	clean := filepath.Clean(rel)
	if !strings.HasPrefix(clean, "wp-content"+string(filepath.Separator)) || strings.Contains(clean, "..") {
		return "", fmt.Errorf("refusing path outside wp-content: %q", rel)
	}
	if isVCSPath(clean) {
		return "", fmt.Errorf("refusing VCS path: %q", rel)
	}
	return filepath.Join(docroot, clean), nil
}

// isVCSPath reports whether a segment is .git, .svn or .hg in any case – a folder or a gitfile
// of a submodule/worktree. .github, .gitignore or a folder git are no VCS paths.
func isVCSPath(rel string) bool {
	for _, seg := range strings.FieldsFunc(rel, func(r rune) bool { return r == '/' || r == '\\' }) {
		switch strings.ToLower(seg) {
		case ".git", ".svn", ".hg":
			return true
		}
	}
	return false
}

// dropVCSPaths removes VCS paths from the delta's file list and returns how many it dropped.
func dropVCSPaths(files []agentapi.File) ([]agentapi.File, int) {
	kept := files[:0:0]
	for _, f := range files {
		if !isVCSPath(f.Path) {
			kept = append(kept, f)
		}
	}
	return kept, len(files) - len(kept)
}

// writeFile writes atomically and sets the source mtime (needed for resume).
func writeFile(docroot, rel string, src io.Reader, size, mtime int64) error {
	root, base, err := openDocroot(docroot)
	if err != nil {
		return err
	}
	defer root.Close()
	return writeFileIn(root, base, rel, src, size, mtime)
}

// writeFileIn writes below the docroot base of root. A symlink on the way – the docroot, a folder
// below it – fails the write instead of following it (SEC-113: a link to .wpsync/history.git would
// let the source replace its config); a file that is a symlink is replaced, not its target.
func writeFileIn(root *os.Root, base, rel string, src io.Reader, size, mtime int64) error {
	target, err := docrootRel(base, rel)
	if err != nil {
		return err
	}
	if err := safefs.WriteFile(root, target, src, size, time.Unix(mtime, 0), 0o644); err != nil {
		return fmt.Errorf("%s: %w", rel, err)
	}
	return nil
}
