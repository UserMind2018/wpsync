// Package localgit keeps an internal git history per site: code and baseline, no uploads, no dumps (AC-28).
package localgit

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
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

// Commit initialises the repo on first use and records the current state.
func Commit(siteDir, message string) error {
	if _, err := os.Stat(filepath.Join(siteDir, ".git")); errors.Is(err, os.ErrNotExist) {
		if err := git(siteDir, "init", "-q", "-b", "main"); err != nil {
			return err
		}
	}
	if err := os.WriteFile(filepath.Join(siteDir, ".gitignore"), []byte(gitignore), 0o644); err != nil {
		return err
	}
	if err := git(siteDir, "add", "-A"); err != nil {
		return err
	}
	return git(siteDir, "-c", "user.name=wpsync", "-c", "user.email=wpsync@localhost",
		"commit", "-q", "--allow-empty", "-m", message)
}

func git(dir string, args ...string) error {
	cmd := exec.Command("git", append([]string{"-C", dir}, args...)...)
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("git %s: %w: %s", strings.Join(args, " "), err, strings.TrimSpace(string(out)))
	}
	return nil
}
