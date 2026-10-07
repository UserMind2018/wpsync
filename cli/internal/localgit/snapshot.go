package localgit

import (
	"bufio"
	"bytes"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/safefs"
)

// excludedDirs are the folders directly below wp-content that the snapshot leaves out: uploads and
// the runtime and backup folders the agent does not deliver either (agent/src/Excludes.php).
var excludedDirs = map[string]bool{"uploads": true, "cache": true, "upgrade": true, "upgrade-temp-backup": true, "wflogs": true}

// testHookBeforeRead runs, if set, right before a file of wp-content is opened for the snapshot.
// Only tests set it, to swap a folder for a symlink at the worst moment (Review 3, M-1).
var testHookBeforeRead func(rel string)

// testHookAfterStat runs, if set, after the size of a file is read and before it is copied. Only
// tests set it, to change the length of a file while the snapshot copies it (F-3).
var testHookAfterStat func(rel string)

// ErrIncomplete is returned by CommitTree after a written commit that lacks files of the site: not
// readable, changed while the snapshot ran, or wp-content/baseline a symlink. Each one is reported on
// out. The snapshot is saved; callers name it as a warning (snapshot_incomplete), not as a failure.
var ErrIncomplete = errors.New("snapshot incomplete")

// notes reports what the snapshot leaves out and counts the files and folders that are missing.
type notes struct {
	out     io.Writer
	missing int
}

// missingf reports a file or folder of the site that is missing in the snapshot.
func (n *notes) missingf(format string, a ...any) {
	n.missing++
	fmt.Fprintf(n.out, format, a...)
}

// entry is one path of the snapshot tree: a file read through the wp-content root (root set) or
// content held in memory (.gitignore, baseline, the target of a symlink).
type entry struct {
	path string // path in the tree, slash-separated, relative to the site folder
	mode string // 100644, 100755 or 120000
	rel  string // with root: path below wp-content
	root *os.Root
	data []byte
}

// snapshot lists what the snapshot holds: the wpsync .gitignore (as a record, git never reads it),
// .wpsync/baseline.json and the code below <docroot>/wp-content. Nothing is followed: wp-content is
// read through its own root, folders are listed without resolving symlinks, and every file is
// opened only if no folder on its way is a symlink. A folder that a site swaps for a symlink while
// the snapshot runs can therefore neither lead out of wp-content nor into another folder of it –
// the file is left out and reported (Review 3, M-1). The caller closes the returned root.
func snapshot(siteDir, docroot string, out *notes) ([]entry, *os.Root, error) {
	entries := []entry{{path: ".gitignore", mode: "100644", data: []byte(strings.ReplaceAll(gitignore, "/public/", "/"+docroot+"/"))}}
	base, err := readBaseline(siteDir, out)
	if err != nil {
		return nil, nil, err
	}
	if base != nil {
		entries = append(entries, entry{path: ".wpsync/baseline.json", mode: "100644", data: base})
	}
	wpc, err := safefs.OpenDir(siteDir, filepath.Join(docroot, "wp-content"))
	switch {
	case errors.Is(err, fs.ErrNotExist):
		return entries, nil, nil
	case errors.Is(err, safefs.ErrSymlink):
		out.missingf("  ! %s ist ein symbolischer Link – fehlt im Schnappschuss.\n", printable(filepath.Join(siteDir, docroot, "wp-content")))
		return entries, nil, nil
	case err != nil:
		return nil, nil, err
	}
	show := func(rel string) string {
		return printable(filepath.Join(siteDir, docroot, "wp-content", filepath.FromSlash(rel)))
	}
	prefix := docroot + "/wp-content/"
	walkErr := fs.WalkDir(wpc.FS(), ".", func(rel string, d fs.DirEntry, err error) error {
		if err != nil {
			out.missingf("  ! %s ist nicht lesbar – fehlt im Schnappschuss.\n", show(rel))
			if d != nil && d.IsDir() {
				return fs.SkipDir
			}
			return nil
		}
		name := d.Name()
		switch {
		case rel != "." && strings.HasSuffix(name, safefs.TmpSuffix):
			if d.IsDir() {
				return fs.SkipDir
			}
		case d.IsDir():
			if rel != "." && !strings.Contains(rel, "/") && excludedDirs[strings.ToLower(name)] {
				return fs.SkipDir
			}
			nested, err := hasGit(wpc, rel)
			if err != nil {
				out.missingf("  ! %s ist nicht lesbar – fehlt im Schnappschuss.\n", show(rel))
				return fs.SkipDir
			}
			if nested {
				fmt.Fprintf(out.out, "  ! %s hat ein eigenes .git – der Ordner fehlt im Schnappschuss, wpsync führt darin nichts aus.\n", show(rel))
				return fs.SkipDir
			}
		case d.Type()&fs.ModeSymlink != 0:
			target, err := wpc.Readlink(filepath.FromSlash(rel))
			if err != nil {
				out.missingf("  ! %s ist nicht lesbar – fehlt im Schnappschuss.\n", show(rel))
				return nil
			}
			entries = append(entries, entry{path: prefix + rel, mode: "120000", data: []byte(target)})
		case d.Type().IsRegular():
			mode := "100644"
			if info, err := d.Info(); err == nil && info.Mode()&0o111 != 0 {
				mode = "100755"
			}
			entries = append(entries, entry{path: prefix + rel, mode: mode, rel: rel, root: wpc})
		}
		return nil
	})
	if walkErr != nil {
		wpc.Close()
		return nil, nil, walkErr
	}
	return entries, wpc, nil
}

// hasGit reports whether the folder holds a .git of any kind, in any case: git treats .GIT on a
// case-insensitive volume as a repo, and a tree entry .GIT would become one on checkout.
func hasGit(root *os.Root, rel string) (bool, error) {
	list, err := fs.ReadDir(root.FS(), rel)
	if err != nil {
		return false, err
	}
	for _, e := range list {
		if strings.EqualFold(e.Name(), ".git") {
			return true, nil
		}
	}
	return false, nil
}

// readBaseline reads <siteDir>/.wpsync/baseline.json without following a symlink; nil if there is none.
func readBaseline(siteDir string, out *notes) ([]byte, error) {
	root, err := safefs.OpenDir(siteDir, ".wpsync")
	if err == nil {
		defer root.Close()
		var data []byte
		data, err = safefs.ReadFile(root, "baseline.json")
		if err == nil {
			return data, nil
		}
	}
	switch {
	case errors.Is(err, fs.ErrNotExist):
		return nil, nil
	case errors.Is(err, safefs.ErrSymlink):
		out.missingf("  ! %s ist ein symbolischer Link – fehlt im Schnappschuss.\n", printable(filepath.Join(siteDir, ".wpsync", "baseline.json")))
		return nil, nil
	}
	return nil, err
}

// importTree writes the entries as one commit on refs/heads/main through git fast-import: git gets
// the content on stdin and never sees the site folder – no work tree, no index, no attributes,
// filters or hooks of the site. Without the final "done" git keeps the branch where it was.
func importTree(gitDir, siteDir, message string, entries []entry, out *notes) error {
	parent := ""
	if head, err := run(gitDir, "", "rev-parse", "--verify", "-q", "refs/heads/main^{commit}"); err == nil {
		parent = strings.TrimSpace(string(head))
	}
	cmd, err := command(gitDir, "", "fast-import", "--quiet", "--done", "--date-format=raw")
	if err != nil {
		return err
	}
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return err
	}
	var stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = io.Discard, &stderr
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("git fast-import: %w", err)
	}
	w := bufio.NewWriterSize(stdin, 1<<16)
	writeErr := writeStream(w, siteDir, parent, message, entries, out)
	if writeErr == nil {
		writeErr = w.Flush()
	}
	stdin.Close()
	waitErr := cmd.Wait()
	if writeErr != nil || waitErr != nil {
		removeCrashReports(gitDir)
	}
	if writeErr != nil {
		return writeErr
	}
	if waitErr != nil {
		return fmt.Errorf("git fast-import: %w: %s", waitErr, printable(strings.TrimSpace(stderr.String())))
	}
	return nil
}

// writeStream writes the fast-import commands: a commit that replaces the whole tree.
func writeStream(w *bufio.Writer, siteDir, parent, message string, entries []entry, out *notes) error {
	fmt.Fprintf(w, "commit refs/heads/main\ncommitter wpsync <wpsync@localhost> %d +0000\ndata %d\n%s\n", time.Now().Unix(), len(message), message)
	if parent != "" {
		fmt.Fprintf(w, "from %s\n", parent)
	}
	w.WriteString("deleteall\n")
	var dir folder
	defer dir.close()
	for _, e := range entries {
		if e.root == nil {
			fmt.Fprintf(w, "M %s inline %s\ndata %d\n", e.mode, quotePath(e.path), len(e.data))
			w.Write(e.data)
			w.WriteString("\n")
			continue
		}
		if err := writeFile(w, siteDir, e, &dir, out); err != nil {
			return err
		}
	}
	_, err := w.WriteString("done\n")
	return err
}

// writeFile copies one file of wp-content into the stream. A file that cannot be opened safely is
// left out and reported; one that changes its length while it is copied breaks the commit.
func writeFile(w *bufio.Writer, siteDir string, e entry, dir *folder, out *notes) error {
	if testHookBeforeRead != nil {
		testHookBeforeRead(e.rel)
	}
	shown := printable(filepath.Join(siteDir, filepath.FromSlash(e.path)))
	f, err := dir.open(e.root, e.rel)
	if err != nil {
		if errors.Is(err, fs.ErrPermission) {
			out.missingf("  ! %s ist nicht lesbar – fehlt im Schnappschuss.\n", shown)
		} else {
			out.missingf("  ! %s hat sich während des Schnappschusses geändert – fehlt im Schnappschuss.\n", shown)
		}
		return nil
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil || !info.Mode().IsRegular() {
		out.missingf("  ! %s hat sich während des Schnappschusses geändert – fehlt im Schnappschuss.\n", shown)
		return nil
	}
	if testHookAfterStat != nil {
		testHookAfterStat(e.rel)
	}
	fmt.Fprintf(w, "M %s inline %s\ndata %d\n", e.mode, quotePath(e.path), info.Size())
	n, err := io.Copy(w, io.LimitReader(f, info.Size()))
	if err != nil || n != info.Size() {
		return fmt.Errorf("snapshot: %s changed while it was read (%d of %d bytes): %v", shown, n, info.Size(), err)
	}
	_, err = w.WriteString("\n")
	return err
}

// folder holds the folders on the way to the last file read, each opened from its parent. The
// entries come folder by folder, so each folder is opened once – as safefs.Open opens a file: not a
// symlink, and the opened folder is the one Lstat saw – and its files relative to that handle. A
// folder swapped afterwards does not redirect them: no path is resolved again. Checking every
// path from wp-content on cost 19 s for 38 000 files (Grill 2026-10-07).
type folder struct {
	names []string   // path of the deepest open folder below wp-content
	roots []*os.Root // roots[i] is names[:i+1]
	err   error      // opening names failed
}

// open opens rel (slash-separated, below wp-content) through the handle of its folder.
func (d *folder) open(wpc *os.Root, rel string) (*os.File, error) {
	parts := strings.Split(rel, "/")
	want, name := parts[:len(parts)-1], parts[len(parts)-1]
	keep := 0
	for keep < len(d.roots) && keep < len(want) && d.names[keep] == want[keep] {
		keep++
	}
	if d.err != nil || keep < len(d.roots) || len(d.names) != len(d.roots) {
		d.closeFrom(keep)
	}
	for len(d.roots) < len(want) {
		parent := wpc
		if n := len(d.roots); n > 0 {
			parent = d.roots[n-1]
		}
		next := want[len(d.roots)]
		d.names = append(d.names, next)
		sub, err := openFolder(parent, next)
		if err != nil {
			d.err = err
			return nil, err
		}
		d.roots = append(d.roots, sub)
	}
	dir := wpc
	if n := len(d.roots); n > 0 {
		dir = d.roots[n-1]
	}
	return safefs.Open(dir, name)
}

// closeFrom closes the folders from depth i on.
func (d *folder) closeFrom(i int) {
	for _, r := range d.roots[i:] {
		r.Close()
	}
	d.roots, d.names, d.err = d.roots[:i], d.names[:i], nil
}

func (d *folder) close() { d.closeFrom(0) }

// openFolder opens the folder name directly below r as a root of its own, if it is not a symlink
// and the opened folder is the one Lstat saw.
func openFolder(r *os.Root, name string) (*os.Root, error) {
	info, err := r.Lstat(name)
	if err != nil {
		return nil, err
	}
	if !info.IsDir() {
		return nil, fmt.Errorf("%s: %w", name, safefs.ErrSymlink)
	}
	sub, err := r.OpenRoot(name)
	if err != nil {
		return nil, err
	}
	if now, err := sub.Stat("."); err != nil || !os.SameFile(info, now) {
		sub.Close()
		return nil, fmt.Errorf("%s changed while opening: %w", name, safefs.ErrSymlink)
	}
	return sub, nil
}

// quotePath quotes a path C-style for fast-import: always quoted, so a leading quote, a line break
// or a control character in a name the site chose cannot end the command early.
func quotePath(p string) string {
	var b strings.Builder
	b.WriteByte('"')
	for i := 0; i < len(p); i++ {
		switch c := p[i]; {
		case c == '"' || c == '\\':
			b.WriteByte('\\')
			b.WriteByte(c)
		case c == '\n':
			b.WriteString(`\n`)
		case c < 0x20 || c == 0x7f:
			fmt.Fprintf(&b, `\%03o`, c)
		default:
			b.WriteByte(c)
		}
	}
	b.WriteByte('"')
	return b.String()
}

// removeCrashReports deletes the fast_import_crash_* files git leaves in the repo after an aborted
// import: they hold a dump of the last commands, so file content of the site (F-3).
func removeCrashReports(gitDir string) {
	left, _ := filepath.Glob(filepath.Join(gitDir, "fast_import_crash_*"))
	for _, f := range left {
		os.Remove(f)
	}
}
