// Package localgit keeps an internal git history per site: code and baseline, no uploads, no dumps (AC-28).
// The repo lives next to the site folders, outside every DDEV mount: code in the site can write to the
// site folder, so a .git there would let it run hooks, filters or fsmonitor on the Mac.
package localgit

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/safefs"
	"github.com/usermind/wpsync/internal/sites"
)

const gitignore = `# Managed by wpsync – only code and baseline are versioned
/*
!/.gitignore
!/public/
/public/*
!/public/wp-content/
/public/wp-content/uploads/
# Laufzeit- und Backup-Ordner, die der Agent ebenfalls nicht liefert (agent/src/Excludes.php)
/public/wp-content/cache/
/public/wp-content/upgrade/
/public/wp-content/upgrade-temp-backup/
/public/wp-content/wflogs/
*.wpsync-tmp
!/.wpsync/
/.wpsync/*
!/.wpsync/baseline.json
`

// storeDir holds the snapshot repos; site names start with [a-z0-9], so it never collides with a site.
const storeDir = ".wpsync-git"

// rename is replaced in tests to simulate a failed move.
var rename = os.Rename

// GitDir is the snapshot repo of a site.
func GitDir(sitesRoot, name string) string {
	return filepath.Join(sitesRoot, storeDir, name+".git")
}

// Commit records the current state of <sitesRoot>/<name> (docroot public/) in its snapshot repo
// GitDir(sitesRoot, name). A .git inside the site folder is never used: before the first commit it
// is moved aside, later it only triggers a warning.
func Commit(sitesRoot, name, message string, out io.Writer) error {
	if !sites.ValidName(name) {
		return fmt.Errorf("invalid site name %q", name)
	}
	return CommitTree(GitDir(sitesRoot, name), filepath.Join(sitesRoot, name), "public", message, out)
}

// TreeGitDir is the snapshot repo of a site folder in the server mode: <siteDir>/.wpsync/history.git.
// Only the docroot below siteDir is mounted into the site's containers, so the repo stays out of
// their reach like GitDir on the Mac; the .gitignore keeps it out of the snapshot.
func TreeGitDir(siteDir string) string { return filepath.Join(siteDir, ".wpsync", "history.git") }

var docrootRe = regexp.MustCompile(`^[A-Za-z0-9._-]+$`)

// CommitTree is Commit for an explicit snapshot repo, site folder and docroot folder directly below
// siteDir (server mode: TreeGitDir(siteDir), <slug>/, docroot).
func CommitTree(gitDir, siteDir, docroot, message string, out io.Writer) error {
	if !docrootRe.MatchString(docroot) || docroot == "." || docroot == ".." {
		return fmt.Errorf("docroot must be a folder directly below %s, got %q", siteDir, docroot)
	}
	if err := os.MkdirAll(filepath.Dir(gitDir), 0o700); err != nil {
		return err
	}
	siteGit := filepath.Join(siteDir, ".git")
	_, err := os.Lstat(siteGit)
	hasSiteGit := err == nil
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}

	if _, err := os.Lstat(gitDir); errors.Is(err, os.ErrNotExist) {
		if hasSiteGit {
			if err := moveAside(siteGit, gitDir, out); err != nil {
				return err
			}
		}
		if err := os.Mkdir(gitDir, 0o700); err != nil {
			return err
		}
	} else if err != nil {
		return err
	} else if hasSiteGit {
		fmt.Fprintf(out, "  ! %s liegt im Site-Ordner – nicht von wpsync angelegt und nicht benutzt.\n", printable(siteGit))
		fmt.Fprintln(out, "    git-Befehle in diesem Ordner (auch Shell-Prompt oder IDE) können Code aus der Site auf diesem Mac ausführen.")
	}

	if _, err := os.Stat(filepath.Join(gitDir, "HEAD")); errors.Is(err, os.ErrNotExist) {
		if err := git(gitDir, "", "init", "-q", "-b", "main", "--template="); err != nil {
			return err
		}
	}

	// Folders with their own .git stay out of the snapshot and are reported. The scan only serves
	// warning and info/exclude: the site keeps running and can add or hide a .git at any time.
	nested, unreadable := findNested(siteDir)
	for _, rel := range nested {
		fmt.Fprintf(out, "  ! %s hat ein eigenes .git – der Ordner fehlt im Schnappschuss, wpsync führt darin nichts aus.\n",
			printable(filepath.Join(siteDir, filepath.FromSlash(rel))))
	}
	for _, rel := range unreadable {
		fmt.Fprintf(out, "  ! %s ist nicht lesbar – wpsync konnte darin nicht nach einem eigenen .git suchen.\n",
			printable(filepath.Join(siteDir, filepath.FromSlash(rel))))
	}
	// writeExclude is the first write into the repo that does not go through run.
	if err := sanitizeRepo(gitDir); err != nil {
		return err
	}
	if err := writeExclude(gitDir, nested); err != nil {
		return err
	}
	// The boundary: no gitlink is in the index when add -A or commit runs. For a gitlink in the index git
	// runs a status inside the embedded repo – with its config, filters and hooks. The gitlinks come from
	// the index itself, not from the scan, and are dropped again after add: a .gitignore written by the
	// site outranks info/exclude, and the site can create a .git after the scan.
	if err := dropGitlinks(gitDir, siteDir); err != nil {
		return err
	}
	if err := writeGitignore(siteDir, strings.ReplaceAll(gitignore, "/public/", "/"+docroot+"/")); err != nil {
		return err
	}
	if err := git(gitDir, siteDir, "add", "-A"); err != nil {
		return err
	}
	if err := dropGitlinks(gitDir, siteDir); err != nil {
		return err
	}
	if err := git(gitDir, siteDir, "-c", "user.name=wpsync", "-c", "user.email=wpsync@localhost",
		"commit", "-q", "--allow-empty", "-m", message); err != nil {
		return err
	}
	if err := maintain(gitDir); err != nil {
		fmt.Fprintf(out, "  ! git-Wartung des Schnappschuss-Repos fehlgeschlagen (Schnappschuss ist gespeichert): %v\n", err)
	}
	return nil
}

// writeGitignore replaces <siteDir>/.gitignore. On the Mac the site folder lies in the DDEV mount:
// a symlink there is replaced, never followed (SEC-113).
func writeGitignore(siteDir, content string) error {
	root, err := os.OpenRoot(siteDir)
	if err != nil {
		return err
	}
	defer root.Close()
	return safefs.WriteFile(root, ".gitignore", strings.NewReader(content), int64(len(content)), time.Time{}, 0o644)
}

// staleLocks are the lock files git leaves in the git dir when it is killed mid-write.
var staleLocks = []string{"index.lock", "HEAD.lock", "gc.pid", "packed-refs.lock", "config.lock"}

// ClearStaleLocks removes lock files that a killed git left in gitDir: index.lock, HEAD.lock,
// refs/heads/*.lock, gc.pid and the like, top level only. The caller must hold the site lock –
// then no git of wpsync runs in this repo (pull takes it; a missing repo is no error).
func ClearStaleLocks(gitDir string) error {
	root, err := os.OpenRoot(gitDir)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	defer root.Close()
	names := append([]string(nil), staleLocks...)
	if heads, err := fs.ReadDir(root.FS(), "refs/heads"); err == nil {
		for _, e := range heads {
			if !e.IsDir() && strings.HasSuffix(e.Name(), ".lock") {
				names = append(names, filepath.Join("refs", "heads", e.Name()))
			}
		}
	}
	var errs []error
	for _, name := range names {
		if err := root.Remove(name); err != nil && !errors.Is(err, os.ErrNotExist) {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}

// maintain runs git's automatic housekeeping in the foreground after a commit (gc.auto is 0 in
// every other call). A failure only leaves loose objects; ClearStaleLocks removes its locks.
func maintain(gitDir string) error {
	return git(gitDir, "", "-c", "gc.auto=6700", "gc", "--auto", "--quiet")
}

// moveAside moves a pre-existing <site>/.git next to the snapshot repo without running git in it
// (<name>.git → <name>.alt-<time>.git).
func moveAside(siteGit, gitDir string, out io.Writer) error {
	dest := strings.TrimSuffix(gitDir, ".git") + ".alt-" + time.Now().Format("20060102-150405") + ".git"
	if _, err := os.Lstat(dest); err == nil {
		return fmt.Errorf("bisheriges Git-Repo %s ließ sich nicht aus dem Site-Ordner verschieben – nichts ausgeführt: %s existiert bereits",
			printable(siteGit), printable(dest))
	}
	if err := rename(siteGit, dest); err != nil {
		return fmt.Errorf("bisheriges Git-Repo %s ließ sich nicht aus dem Site-Ordner verschieben – nichts ausgeführt: %w",
			printable(siteGit), err)
	}
	fmt.Fprintf(out, "  Bisheriges Site-Git verschoben nach %s – die Historie beginnt neu. Das alte Repo darf gelöscht werden; nicht mit git öffnen, es kann manipuliert sein.\n",
		printable(dest))
	return nil
}

// findNested is replaced in tests to simulate a scan that misses a repo.
var findNested = nestedRepos

// nestedRepos returns the folders below siteDir (slash-separated, relative) that contain a .git of any
// kind – directory, gitfile or symlink – and the folders it could not read. Like git, it asks the file
// system for "<dir>/.git", so .GIT on a case-insensitive volume counts too. Symlinks are not followed,
// <site>/.git itself is handled by Commit, and a found folder is not searched further.
func nestedRepos(siteDir string) (repos, unreadable []string) {
	topGit, _ := os.Lstat(filepath.Join(siteDir, ".git"))
	rel := func(p string) string {
		r, _ := filepath.Rel(siteDir, p)
		return filepath.ToSlash(r)
	}
	filepath.WalkDir(siteDir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			if d != nil && d.IsDir() && p != siteDir {
				unreadable = append(unreadable, rel(p))
				return fs.SkipDir
			}
			return nil
		}
		if !d.IsDir() {
			return nil
		}
		if p == siteDir {
			return nil
		}
		info, err := os.Lstat(p)
		if err == nil && topGit != nil && os.SameFile(info, topGit) {
			return fs.SkipDir
		}
		switch _, err := os.Lstat(filepath.Join(p, ".git")); {
		case err == nil:
			repos = append(repos, rel(p))
			return fs.SkipDir
		case !errors.Is(err, os.ErrNotExist):
			unreadable = append(unreadable, rel(p))
			return fs.SkipDir
		}
		return nil
	})
	return repos, unreadable
}

// dropGitlinks removes every gitlink (mode 160000) from the index without running git inside it.
func dropGitlinks(gitDir, siteDir string) error {
	staged, err := run(gitDir, siteDir, "ls-files", "--stage", "-z")
	if err != nil {
		return err
	}
	rm := []string{"rm", "--cached", "-f", "-q", "--ignore-unmatch", "--"}
	for _, rec := range strings.Split(string(staged), "\x00") {
		meta, path, ok := strings.Cut(rec, "\t")
		if ok && strings.HasPrefix(meta, "160000 ") {
			rm = append(rm, ":(literal)"+path)
		}
	}
	if len(rm) == 6 {
		return nil
	}
	return git(gitDir, siteDir, rm...)
}

// writeExclude rewrites <G>/info/exclude with one anchored, literal pattern per nested repo folder.
func writeExclude(gitDir string, nested []string) error {
	var b strings.Builder
	b.WriteString("# Managed by wpsync – folders with their own .git\n")
	for _, rel := range nested {
		if p := excludePattern(rel); p != "" {
			b.WriteString(p + "\n")
		}
	}
	root, err := os.OpenRoot(gitDir)
	if err != nil {
		return err
	}
	defer root.Close()
	content := b.String()
	return safefs.WriteFile(root, filepath.Join("info", "exclude"), strings.NewReader(content), int64(len(content)), time.Time{}, 0o600)
}

// excludePattern escapes glob characters so the pattern matches the folder literally. A line break
// cannot be expressed in an ignore file; then the nearest ancestor without one is excluded.
func excludePattern(rel string) string {
	parts := strings.Split(rel, "/")
	for i, part := range parts {
		if strings.ContainsAny(part, "\n\r") {
			parts = parts[:i]
			break
		}
	}
	if len(parts) == 0 {
		return ""
	}
	esc := strings.NewReplacer(`\`, `\\`, `*`, `\*`, `?`, `\?`, `[`, `\[`)
	return "/" + esc.Replace(strings.Join(parts, "/")) + "/"
}

func git(gitDir, workTree string, args ...string) error {
	_, err := run(gitDir, workTree, args...)
	return err
}

// run runs git on an explicit git dir and work tree, without repo discovery, inherited GIT_* variables,
// user/system config or the user's global ignore and attributes files, and returns its stdout.
func run(gitDir, workTree string, args ...string) ([]byte, error) {
	if err := sanitizeRepo(gitDir); err != nil {
		return nil, err
	}
	base := []string{"--git-dir=" + gitDir}
	if workTree != "" {
		base = append(base, "--work-tree="+workTree)
	}
	base = append(base, "-c", "core.excludesFile="+os.DevNull, "-c", "core.attributesFile="+os.DevNull,
		// defence in depth, not the boundary: also reaches git's subprocesses in embedded repos, but does
		// not stop their clean filters – only an index without gitlinks does (see Commit)
		"-c", "core.fsmonitor=false", "-c", "core.hooksPath="+os.DevNull,
		// no auto gc/maintenance inside commit: killed with a short-lived container (or by SIGKILL)
		// it leaves HEAD.lock behind, which blocked every later commit. Commit runs Maintain itself.
		"-c", "gc.auto=0", "-c", "maintenance.auto=false",
		"-c", "gc.autoDetach=false", "-c", "maintenance.autoDetach=false")
	cmd := exec.Command("git", append(base, args...)...)
	cmd.Dir = gitDir
	cmd.Env = gitEnv(gitDir)
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	if err := cmd.Run(); err != nil {
		msg := strings.TrimSpace(stderr.String() + "\n" + stdout.String())
		return nil, fmt.Errorf("git %s: %w: %s", strings.Join(args, " "), err, printable(msg))
	}
	return stdout.Bytes(), nil
}

// emptyTree is git's well-known empty tree; it exists in every SHA-1 repo without being stored.
const emptyTree = "4b825dc642cb6eb9a060e54bf8d69288fbee4904"

func gitEnv(gitDir string) []string {
	var env []string
	for _, kv := range os.Environ() {
		if !strings.HasPrefix(kv, "GIT_") {
			env = append(env, kv)
		}
	}
	return append(env, "GIT_CONFIG_NOSYSTEM=1", "GIT_CONFIG_GLOBAL="+os.DevNull, "GIT_ATTR_NOSYSTEM=1", "GIT_TERMINAL_PROMPT=0",
		// git ≥ 2.40 reads attributes from this tree instead of the work tree and index: a
		// .gitattributes of the site never names a filter, diff driver or merge driver (SEC-113).
		// Older git ignores it; sanitizeRepo then still keeps every driver definition out of the config.
		"GIT_ATTR_SOURCE="+emptyTree,
		// The common dir is the repo itself: a file <G>/commondir would otherwise point git at a
		// config and info/attributes elsewhere, e.g. in the docroot (Nach-Review K-2).
		"GIT_COMMON_DIR="+gitDir)
}

// allowedCore are the config entries git init writes; wpsync sets nothing else in the repo.
var allowedCore = map[string]bool{
	"repositoryformatversion": true, "filemode": true, "bare": true, "logallrefupdates": true,
	"ignorecase": true, "precomposeunicode": true, "symlinks": true,
}

var (
	configSectionRe = regexp.MustCompile(`^\[([A-Za-z]+)\]$`)
	configEntryRe   = regexp.MustCompile(`^([A-Za-z]+) = ([A-Za-z0-9]+)$`)
)

// repoEntries are the top-level entries of a wpsync snapshot repo; true marks folders. Stale locks
// (*.lock, gc.pid) stay for ClearStaleLocks, temp files of safefs (*.wpsync-tmp) are removed.
var repoEntries = map[string]bool{
	"HEAD": false, "config": false, "description": false, "index": false, "packed-refs": false,
	"ORIG_HEAD": false, "COMMIT_EDITMSG": false, "gc.pid": false, "gc.log": false,
	"info": true, "objects": true, "refs": true, "logs": true,
}

// ErrRepoSymlink: an entry of the snapshot repo is a symlink; wpsync runs no git in it.
var ErrRepoSymlink = errors.New("ist ein symbolischer Link – wpsync führt in diesem Snapshot-Repo kein git aus")

// sanitizeRepo runs before every git call and keeps the snapshot repo to what git init and commit
// create (SEC-113, Nach-Review K-2), as defence in depth behind the symlink checks of safefs:
//   - top level: only repoEntries and stale locks. Anything else is removed – commondir,
//     config.worktree, worktrees/, gitdir would point git at a config or info/attributes elsewhere.
//     Removing instead of aborting: the repo belongs to wpsync alone, and an abort would let
//     whoever planted the file block every later pull.
//   - an allowed entry that is a symlink (or of the wrong kind) aborts: it cannot be repaired
//     without losing history, and following it could write or read outside the repo.
//   - info/ holds only exclude; objects/info/(http-)alternates are removed (foreign object stores).
//   - config holds only the [core] entries of git init with plain values; anything else – a
//     filter, diff or merge driver, include, core.fsmonitor … – is dropped by rewriting it.
func sanitizeRepo(gitDir string) error {
	root, err := os.OpenRoot(gitDir)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	defer root.Close()
	entries, err := fs.ReadDir(root.FS(), ".")
	if err != nil {
		return err
	}
	for _, e := range entries {
		name := e.Name()
		isDir, known := repoEntries[name]
		switch {
		case !known && strings.HasSuffix(name, ".lock") && e.Type().IsRegular():
			continue
		case !known:
			if err := root.RemoveAll(name); err != nil {
				return fmt.Errorf("remove %s/%s: %w", printable(gitDir), printable(name), err)
			}
			continue
		case e.Type()&fs.ModeSymlink != 0, isDir != e.IsDir():
			return fmt.Errorf("%s/%s %w", printable(gitDir), printable(name), ErrRepoSymlink)
		}
	}
	if err := keepOnly(root, "info", "exclude"); err != nil {
		return fmt.Errorf("%s/info: %w", printable(gitDir), err)
	}
	for _, alt := range []string{"alternates", "http-alternates"} {
		if err := root.RemoveAll(filepath.Join("objects", "info", alt)); err != nil {
			return fmt.Errorf("remove %s/objects/info/%s: %w", printable(gitDir), alt, err)
		}
	}
	info, err := root.Lstat("config")
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	var data []byte
	if info.Mode().IsRegular() {
		if data, err = root.ReadFile("config"); err != nil {
			return err
		}
	}
	clean, ok := cleanConfig(string(data))
	if ok && info.Mode().IsRegular() {
		return nil
	}
	return safefs.WriteFile(root, "config", strings.NewReader(clean), int64(len(clean)), time.Time{}, 0o600)
}

// keepOnly removes every entry of dir except the regular file keep (a missing dir is fine).
func keepOnly(root *os.Root, dir, keep string) error {
	entries, err := fs.ReadDir(root.FS(), dir)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	for _, e := range entries {
		if e.Name() == keep && e.Type().IsRegular() {
			continue
		}
		if err := root.RemoveAll(filepath.Join(dir, e.Name())); err != nil {
			return err
		}
	}
	return nil
}

// cleanConfig returns the allowed core entries as a fresh config and whether data had nothing else.
func cleanConfig(data string) (string, bool) {
	ok := true
	section := ""
	var b strings.Builder
	b.WriteString("[core]\n")
	for _, line := range strings.Split(data, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		if m := configSectionRe.FindStringSubmatch(line); m != nil {
			section = strings.ToLower(m[1])
			if section != "core" {
				ok = false
			}
			continue
		}
		m := configEntryRe.FindStringSubmatch(line)
		if section != "core" || m == nil || !allowedCore[strings.ToLower(m[1])] {
			ok = false
			continue
		}
		fmt.Fprintf(&b, "\t%s = %s\n", strings.ToLower(m[1]), m[2])
	}
	return b.String(), ok
}

// printable escapes control characters (except newline and tab) so git output and paths cannot drive the terminal.
func printable(s string) string {
	var b strings.Builder
	for _, r := range s {
		if (r < 0x20 && r != '\n' && r != '\t') || (r >= 0x7f && r <= 0x9f) {
			fmt.Fprintf(&b, `\x%02x`, r)
			continue
		}
		b.WriteRune(r)
	}
	return b.String()
}
