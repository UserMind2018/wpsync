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
	"strings"
	"time"

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

// Commit records the current state of <sitesRoot>/<name> in its snapshot repo. A .git inside the
// site folder is never used: before the first commit it is moved aside, later it only triggers a warning.
func Commit(sitesRoot, name, message string, out io.Writer) error {
	if !sites.ValidName(name) {
		return fmt.Errorf("invalid site name %q", name)
	}
	siteDir := filepath.Join(sitesRoot, name)
	gitDir := GitDir(sitesRoot, name)
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
			if err := moveAside(siteGit, sitesRoot, name, out); err != nil {
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
	if err := os.WriteFile(filepath.Join(siteDir, ".gitignore"), []byte(gitignore), 0o644); err != nil {
		return err
	}
	if err := git(gitDir, siteDir, "add", "-A"); err != nil {
		return err
	}
	if err := dropGitlinks(gitDir, siteDir); err != nil {
		return err
	}
	return git(gitDir, siteDir, "-c", "user.name=wpsync", "-c", "user.email=wpsync@localhost",
		"commit", "-q", "--allow-empty", "-m", message)
}

// moveAside moves a pre-existing <site>/.git out of the site folder without running git in it.
func moveAside(siteGit, sitesRoot, name string, out io.Writer) error {
	dest := filepath.Join(sitesRoot, storeDir, name+".alt-"+time.Now().Format("20060102-150405")+".git")
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
	info := filepath.Join(gitDir, "info")
	if err := os.MkdirAll(info, 0o700); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(info, "exclude"), []byte(b.String()), 0o600)
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
	base := []string{"--git-dir=" + gitDir}
	if workTree != "" {
		base = append(base, "--work-tree="+workTree)
	}
	base = append(base, "-c", "core.excludesFile="+os.DevNull, "-c", "core.attributesFile="+os.DevNull,
		// defence in depth, not the boundary: also reaches git's subprocesses in embedded repos, but does
		// not stop their clean filters – only an index without gitlinks does (see Commit)
		"-c", "core.fsmonitor=false", "-c", "core.hooksPath="+os.DevNull)
	cmd := exec.Command("git", append(base, args...)...)
	cmd.Dir = gitDir
	cmd.Env = gitEnv()
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	if err := cmd.Run(); err != nil {
		msg := strings.TrimSpace(stderr.String() + "\n" + stdout.String())
		return nil, fmt.Errorf("git %s: %w: %s", strings.Join(args, " "), err, printable(msg))
	}
	return stdout.Bytes(), nil
}

func gitEnv() []string {
	var env []string
	for _, kv := range os.Environ() {
		if !strings.HasPrefix(kv, "GIT_") {
			env = append(env, kv)
		}
	}
	return append(env, "GIT_CONFIG_NOSYSTEM=1", "GIT_CONFIG_GLOBAL="+os.DevNull, "GIT_ATTR_NOSYSTEM=1", "GIT_TERMINAL_PROMPT=0")
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
