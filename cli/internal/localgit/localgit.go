// Package localgit keeps an internal git history per site: code and baseline, no uploads, no dumps (AC-28).
// The repo lives next to the site folders, outside every DDEV mount: code in the site can write to the
// site folder, so a .git there would let it run hooks, filters or fsmonitor on the Mac. git never gets a
// work tree: wpsync reads the files itself and hands them to git fast-import (Review 3, M-1).
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

// gitignore lies in every snapshot as a record of what is versioned. git never reads it and wpsync
// no longer writes it into the site folder: snapshot decides what goes in (Review 3, M-1).
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
// siteDir (server mode: TreeGitDir(siteDir), <slug>/, docroot). It returns ErrIncomplete after a
// commit that lacks files of the site; they are reported on out.
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

	if err := sanitizeRepo(gitDir); err != nil {
		return err
	}
	notes := &notes{out: out}
	entries, wpc, err := snapshot(siteDir, docroot, notes)
	if err != nil {
		return err
	}
	if wpc != nil {
		defer wpc.Close()
	}
	if err := importTree(gitDir, siteDir, message, entries, notes); err != nil {
		return err
	}
	if err := maintain(gitDir); err != nil {
		fmt.Fprintf(out, "  ! git-Wartung des Schnappschuss-Repos fehlgeschlagen (Schnappschuss ist gespeichert): %v\n", err)
	}
	if notes.missing > 0 {
		return fmt.Errorf("%w: %d missing", ErrIncomplete, notes.missing)
	}
	return nil
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

func git(gitDir, workTree string, args ...string) error {
	_, err := run(gitDir, workTree, args...)
	return err
}

// run runs git on an explicit git dir and work tree, without repo discovery, inherited GIT_* variables,
// user/system config or the user's global ignore and attributes files, and returns its stdout.
func run(gitDir, workTree string, args ...string) ([]byte, error) {
	cmd, err := command(gitDir, workTree, args...)
	if err != nil {
		return nil, fmt.Errorf("git %s: %w", strings.Join(args, " "), err)
	}
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	if err := cmd.Run(); err != nil {
		msg := strings.TrimSpace(stderr.String() + "\n" + stdout.String())
		return nil, fmt.Errorf("git %s: %w: %s", strings.Join(args, " "), err, printable(msg))
	}
	return stdout.Bytes(), nil
}

// command prepares a git call for run and importTree: repo sanitized, git from an absolute PATH
// entry, config and environment pinned.
func command(gitDir, workTree string, args ...string) (*exec.Cmd, error) {
	if err := sanitizeRepo(gitDir); err != nil {
		return nil, err
	}
	bin, err := gitPath()
	if err != nil {
		return nil, err
	}
	base := []string{"--git-dir=" + gitDir}
	if workTree != "" {
		base = append(base, "--work-tree="+workTree)
	}
	base = append(base, "-c", "core.excludesFile="+os.DevNull, "-c", "core.attributesFile="+os.DevNull,
		// defence in depth: wpsync runs git without a work tree of the site, but a tampered repo
		// config must not name hooks or an fsmonitor either
		"-c", "core.fsmonitor=false", "-c", "core.hooksPath="+os.DevNull,
		// no auto gc/maintenance inside a call: killed with a short-lived container (or by SIGKILL)
		// it leaves HEAD.lock behind, which blocked every later commit. Commit runs maintain itself.
		"-c", "gc.auto=0", "-c", "maintenance.auto=false",
		"-c", "gc.autoDetach=false", "-c", "maintenance.autoDetach=false")
	cmd := exec.Command(bin, append(base, args...)...)
	cmd.Dir = gitDir
	cmd.Env = gitEnv(gitDir)
	return cmd, nil
}

// gitPath finds git in the absolute entries of PATH only. A relative entry ("." or "bin") would
// run a git from the current folder; Go refuses that anyway (exec.ErrDot), which broke the
// snapshot when a caller's PATH held one (Spec Container-Push A7).
func gitPath() (string, error) {
	for _, dir := range filepath.SplitList(os.Getenv("PATH")) {
		if !filepath.IsAbs(dir) {
			continue
		}
		p := filepath.Join(dir, "git")
		if info, err := os.Stat(p); err == nil && info.Mode().IsRegular() && info.Mode().Perm()&0o111 != 0 {
			return p, nil
		}
	}
	return "", exec.ErrNotFound
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
