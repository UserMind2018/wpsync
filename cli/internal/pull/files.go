package pull

import (
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/safefs"
)

// DefaultFileRetries is the number of repetitions of a cut file request (pull --retries); MaxFileRetries bounds it.
const (
	DefaultFileRetries = 3
	MaxFileRetries     = 20
)

// DownloadOptions tune DownloadFiles.
type DownloadOptions struct {
	// BundleBytes: a request carries files up to this many bytes (a single larger file gets its own).
	BundleBytes int64
	// Retries: how often a request is repeated after a transient failure (see isTransient); 0 = never.
	Retries int
	// Out receives the progress lines; Progress (may be nil) the number of finished files after every
	// bundle and their bytes: the sizes the delta names, also for a file that was skipped.
	Out      io.Writer
	Progress func(done, total int, bytesDone, bytesTotal int64)
	// OnRetry (may be nil) is told before every wait.
	OnRetry func(Retry)
}

// Retry describes one repetition of a request after a transient failure (pull --json: event "retry").
type Retry struct {
	Phase string `json:"phase"`
	// Attempt of Max repetitions; counted anew whenever a repetition brought files in.
	Attempt int `json:"attempt"`
	Max     int `json:"max"`
	// Files still to fetch with this request; Path is the first of them.
	Files  int    `json:"files"`
	Path   string `json:"path"`
	Reason string `json:"reason"`
	WaitMS int64  `json:"wait_ms"`
}

// retryBackoff is the wait before repetition n (counted from 0): 2, 4, 8 … seconds, at most 30.
func retryBackoff(n int) time.Duration {
	return min(time.Duration(2<<min(n, 4))*time.Second, 30*time.Second)
}

// DownloadFiles fetches files in bundles of at most opts.BundleBytes and writes them below docroot,
// each one atomically (<name>.wpsync-tmp, then rename): an aborted transfer leaves no half file.
//
// A transient failure (a cut connection, a timeout, HTTP 502/503/504) repeats the request for the
// files still missing, up to opts.Retries times with a growing wait; files finished before the cut
// are kept. A bundle that is cut again after its first repetition is split and fetched file by file
// (every file with its own repetitions). The agent has no range request, so a cut file starts over.
// Every other failure (403, forbidden path, no space, a suspected ban) ends at once.
//
// A file below a symlinked folder is skipped, reported on out and returned in skipped (Nach-Review
// N-c): nothing is written through the link, and the pull of everything else goes on.
func DownloadFiles(c *agentapi.Client, docroot string, files []agentapi.File, opts DownloadOptions) (skipped []string, err error) {
	if len(files) == 0 {
		return nil, nil
	}
	// The site folder belongs to wpsync (the site only reaches the docroot, on the Mac its content).
	if err := os.MkdirAll(filepath.Dir(filepath.Clean(docroot)), 0o755); err != nil {
		return nil, err
	}
	root, err := openDocroot(docroot, true)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	d := &downloader{c: c, root: root, opts: opts, handled: map[string]bool{}}
	done, bytesDone, bytesTotal := 0, int64(0), fileBytes(files)
	for _, group := range bundles(files, opts.BundleBytes) {
		paths := make([]string, len(group))
		for i, f := range group {
			paths[i] = f.Path
		}
		if err := d.fetch(paths); err != nil {
			return d.skipped, err
		}
		done += len(group)
		bytesDone += fileBytes(group)
		fmt.Fprintf(opts.Out, "  Dateien %d/%d\n", done, len(files))
		if opts.Progress != nil {
			opts.Progress(done, len(files), bytesDone, bytesTotal)
		}
	}
	return d.skipped, nil
}

type downloader struct {
	c    *agentapi.Client
	root *os.Root
	opts DownloadOptions
	// handled: paths the agent answered (written, skipped below a symlink, or reported missing).
	handled map[string]bool
	skipped []string
}

// fetch gets paths, repeating after transient failures; see DownloadFiles.
func (d *downloader) fetch(paths []string) error {
	out := d.opts.Out
	attempt, last := 0, len(paths)+1
	for {
		remaining := d.pending(paths)
		if len(remaining) == 0 {
			return nil
		}
		if len(remaining) < last {
			// A repetition that brought files in is no failed one: the count starts anew.
			attempt, last = 0, len(remaining)
		}
		err := d.request(remaining)
		if err == nil {
			return nil
		}
		// What the failure left open – the first of these is the file that was cut.
		left := d.pending(remaining)
		wrapped := func(err error) error {
			return fmt.Errorf("bundle with %d files (first: %s): %w", len(left), left[0], err)
		}
		if !isTransient(err) || len(left) == 0 {
			return wrapped(err)
		}
		if len(left) > 1 && attempt >= 1 {
			fmt.Fprintf(out, "  ! Bündel mit %d Dateien reisst wiederholt ab – die Dateien werden einzeln geholt\n", len(left))
			for _, p := range left {
				if err := d.fetch([]string{p}); err != nil {
					return err
				}
			}
			return nil
		}
		if attempt >= d.opts.Retries {
			if d.opts.Retries == 0 {
				return wrapped(err)
			}
			return wrapped(fmt.Errorf("nach %d Wiederholungen aufgegeben: %w", d.opts.Retries, err))
		}
		wait := retryBackoff(attempt)
		attempt++
		reason := agentapi.CleanText(err.Error())
		fmt.Fprintf(out, "  ↻ Wiederholung %d/%d in %s (%d Dateien offen, erste: %s): %s\n",
			attempt, d.opts.Retries, wait, len(left), agentapi.Printable(left[0]), reason)
		if d.opts.OnRetry != nil {
			d.opts.OnRetry(Retry{Phase: PhaseFiles, Attempt: attempt, Max: d.opts.Retries, Files: len(left),
				Path: left[0], Reason: reason, WaitMS: wait.Milliseconds()})
		}
		if err := d.c.Pause(wait); err != nil {
			return err
		}
	}
}

// pending returns the paths the agent has not answered yet.
func (d *downloader) pending(paths []string) []string {
	return slices.DeleteFunc(slices.Clone(paths), func(p string) bool { return d.handled[p] })
}

// request asks for paths once and writes what arrives.
func (d *downloader) request(paths []string) error {
	return d.c.Files(paths, func(path string, size, mtime int64, body io.Reader) error {
		err := writeFileIn(d.root, path, body, size, mtime)
		if errors.Is(err, safefs.ErrSymlink) {
			fmt.Fprintf(d.opts.Out, "  ! übersprungen (symbolischer Link im Pfad, wpsync schreibt nicht hindurch): %s\n", agentapi.Printable(path))
			d.skipped = append(d.skipped, path)
			d.handled[path] = true
			return nil
		}
		if err != nil {
			return err
		}
		d.handled[path] = true
		return nil
	}, func(path string) {
		fmt.Fprintf(d.opts.Out, "  ! übersprungen (fehlt oder ungültig): %s\n", path)
		d.handled[path] = true
	})
}

// fileBytes sums the sizes the delta names for files.
func fileBytes(files []agentapi.File) int64 {
	var n int64
	for _, f := range files {
		n += f.Size
	}
	return n
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

// openDocroot opens the docroot itself as the root of every file operation of a pull: nothing
// outside it – in the server mode <slug>/.wpsync next door – is reachable, whatever the site
// swaps for a symlink while wpsync works (Nach-Review K-1). The docroot must not be a symlink
// (Mac: public/ lies in the DDEV mount). create makes it on the first pull.
func openDocroot(docroot string, create bool) (*os.Root, error) {
	clean := filepath.Clean(docroot)
	if create {
		return safefs.OpenTree(filepath.Dir(clean), filepath.Base(clean))
	}
	return safefs.OpenDir(filepath.Dir(clean), filepath.Base(clean))
}

// docrootRel checks rel like SafeJoin and returns it cleaned, relative to the docroot.
func docrootRel(rel string) (string, error) {
	if _, err := SafeJoin("/", rel); err != nil {
		return "", err
	}
	return filepath.Clean(rel), nil
}

// RemoveFiles deletes files that disappeared on the source. A symlink is removed itself; a path
// through a symlinked folder is skipped (SEC-113).
func RemoveFiles(docroot string, paths []string) {
	root, err := openDocroot(docroot, false)
	if err != nil {
		return
	}
	defer root.Close()
	for _, rel := range paths {
		if target, err := docrootRel(rel); err == nil {
			safefs.Remove(root, target)
		}
	}
}

// PresentLocally reports whether a regular file already exists with identical size and mtime.
// Symlinks never count: neither the file nor a folder on the way is followed.
func PresentLocally(docroot string) func(agentapi.File) bool {
	return func(f agentapi.File) bool {
		root, err := openDocroot(docroot, false)
		if err != nil {
			return false
		}
		defer root.Close()
		target, err := docrootRel(f.Path)
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
	root, err := openDocroot(docroot, true)
	if err != nil {
		return err
	}
	defer root.Close()
	return writeFileIn(root, rel, src, size, mtime)
}

// writeFileIn writes below the docroot root. A symlink on the way – the docroot, a folder
// below it – fails the write instead of following it (SEC-113: a link to .wpsync/history.git would
// let the source replace its config); a file that is a symlink is replaced, not its target.
func writeFileIn(root *os.Root, rel string, src io.Reader, size, mtime int64) error {
	target, err := docrootRel(rel)
	if err != nil {
		return err
	}
	if err := safefs.WriteFile(root, target, src, size, time.Unix(mtime, 0), 0o644); err != nil {
		return fmt.Errorf("%s: %w", rel, err)
	}
	return nil
}
