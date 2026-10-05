// Package push brings locally changed code to a paired site (Spec Stufe 2).
package push

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"syscall"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

const (
	muPlugins    = "mu-plugins"
	maxFileBytes = 256 << 20 // the agent never delivers or accepts larger files
)

var (
	unitRe    = regexp.MustCompile(`^(plugins|themes)/[A-Za-z0-9][A-Za-z0-9._-]*$`)
	versionRe = regexp.MustCompile(`(?mi)^[ \t/*#@]*Version:[ \t]*(\S+)`)
)

// LocalFile is a file of a unit on this machine. SHA256 is filled by Unit.Hash.
type LocalFile struct {
	Size   int64
	MTime  int64
	SHA256 string
}

// Unit is a whole plugin, a whole theme or mu-plugins. File paths are relative to the unit.
type Unit struct {
	Path    string
	Files   map[string]LocalFile          // what is on disk now, without ignored files
	Base    map[string]baseline.FileStamp // what the last pull delivered
	Changed []string                      // new, modified or locally deleted files
	New     bool                          // unknown to the baseline
	Version string

	dir fs.FileInfo // the unit directory as the scan saw it
}

// ValidUnit mirrors the agent's rule (PushUnits::valid): never the agent itself.
func ValidUnit(unit string) bool {
	if unit == muPlugins {
		return true
	}
	return unitRe.MatchString(unit) && !strings.EqualFold(unit, "plugins/wpsync-agent")
}

// UnitOf splits a baseline path such as "wp-content/plugins/x/inc/a.php" into unit and file.
func UnitOf(path string) (unit, rel string, ok bool) {
	rest, found := strings.CutPrefix(path, "wp-content/")
	if !found {
		return "", "", false
	}
	parts := strings.SplitN(rest, "/", 3)
	switch {
	case parts[0] == muPlugins && len(parts) >= 2:
		return muPlugins, strings.TrimPrefix(rest, muPlugins+"/"), true
	case (parts[0] == "plugins" || parts[0] == "themes") && len(parts) == 3:
		unit = parts[0] + "/" + parts[1]
		return unit, parts[2], ValidUnit(unit)
	}
	return "", "", false
}

// Ignored reports files that are never part of a push: what the agent would not deliver on a
// pull either (its fixed exclusions), names that are unsafe to show, the local mail guard and wpsync's own files in mu-plugins,
// and macOS/wpsync leftovers.
func Ignored(unit, rel string, size int64) bool {
	// Control characters (C0, DEL, C1), Bidi controls and invalid UTF-8 would steer the terminal
	// when the plan lists the file; the agent refuses such names as well (PushUnits::validFile).
	if size > maxFileBytes || strings.Contains(rel, "\\") || strings.ContainsFunc(rel, agentapi.Unsafe) {
		return true
	}
	segments := strings.Split(strings.ToLower(rel), "/")
	for _, s := range segments {
		if s == ".git" || s == ".svn" || s == ".hg" {
			return true
		}
	}
	if unit == muPlugins && (segments[0] == "00-local-mailguard.php" || strings.HasPrefix(segments[0], "wpsync")) {
		return true
	}
	name := segments[len(segments)-1]
	return name == ".ds_store" || name == ".htpasswd" || name == ".env" || strings.HasPrefix(name, ".env.") ||
		strings.HasSuffix(name, ".log") || strings.HasSuffix(name, ".wpsync-tmp")
}

// Scan compares the code directories below docroot/wp-content with the baseline. It returns the
// units with local changes and the units that exist in the baseline but no longer on disk.
// A pull sets every file's mtime to the source's, so a differing size or mtime means a local edit.
func Scan(docroot string, base *baseline.Baseline) (units []Unit, deleted []string, err error) {
	units, deleted, _, err = scan(docroot, base)
	return units, deleted, err
}

// scan is Scan plus the units that are symlinks: they are never read and never pushed (U19).
func scan(docroot string, base *baseline.Baseline) (units []Unit, deleted, links []string, err error) {
	known := map[string]map[string]baseline.FileStamp{}
	for path, stamp := range base.Files {
		if unit, rel, ok := UnitOf(path); ok {
			if known[unit] == nil {
				known[unit] = map[string]baseline.FileStamp{}
			}
			known[unit][rel] = stamp
		}
	}

	// Every directory from the site folder down to plugins, themes and mu-plugins must be real: a
	// symlink there would push another site's files (M1). A unit that is a symlink is skipped and
	// never read (U19); inside a unit, symlinks are skipped and never followed.
	local := map[string]bool{}
	linked := map[string]bool{}
	for _, rel := range []string{".", "wp-content"} {
		if _, ok, err := realDir(docroot, rel); err != nil {
			return nil, nil, nil, err
		} else if !ok {
			return nil, sortedKeys(known), nil, nil
		}
	}
	for _, kind := range []string{"plugins", "themes"} {
		_, ok, err := realDir(docroot, "wp-content/"+kind)
		if err != nil {
			return nil, nil, nil, err
		}
		if !ok {
			continue
		}
		entries, err := os.ReadDir(filepath.Join(docroot, "wp-content", kind))
		if err != nil {
			return nil, nil, nil, err
		}
		for _, e := range entries {
			unit := kind + "/" + e.Name()
			switch {
			case !ValidUnit(unit):
			case e.Type()&fs.ModeSymlink != 0:
				linked[unit] = true
				links = append(links, unit)
			case e.IsDir():
				local[unit] = true
			}
		}
	}
	if _, ok, err := realDir(docroot, "wp-content/"+muPlugins); err != nil {
		return nil, nil, nil, err
	} else if ok {
		local[muPlugins] = true
	}

	for unit := range local {
		u, err := scanUnit(docroot, unit, known[unit])
		if err != nil {
			return nil, nil, nil, err
		}
		if len(u.Changed) > 0 && len(u.Files) > 0 {
			sort.Strings(u.Changed)
			units = append(units, u)
		}
	}
	for unit := range known {
		if !local[unit] && !linked[unit] {
			deleted = append(deleted, unit)
		}
	}
	sort.Slice(units, func(i, j int) bool { return units[i].Path < units[j].Path })
	sort.Strings(deleted)
	sort.Strings(links)
	return units, deleted, links, nil
}

func sortedKeys(m map[string]map[string]baseline.FileStamp) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// scanUnit lists a unit's files through a root pinned to the unit directory and compares them
// with the baseline.
func scanUnit(docroot, unit string, base map[string]baseline.FileStamp) (Unit, error) {
	root, dir, err := openUnit(docroot, unit, nil)
	if err != nil {
		return Unit{}, err
	}
	defer root.Close()
	files, err := localFiles(root, unit)
	if err != nil {
		return Unit{}, err
	}
	u := Unit{Path: unit, Files: files, Base: base, New: len(base) == 0, Version: versionIn(root, unit), dir: dir}
	for rel, f := range files {
		if b, ok := u.Base[rel]; !ok || b.Size != f.Size || b.MTime != f.MTime {
			u.Changed = append(u.Changed, rel)
		}
	}
	for rel, b := range u.Base {
		if _, ok := files[rel]; !ok && !Ignored(unit, rel, b.Size) {
			u.Changed = append(u.Changed, rel)
		}
	}
	return u, nil
}

// localFiles lists the pushable files of a unit: no symlinks, nothing ignored.
func localFiles(root *os.Root, unit string) (map[string]LocalFile, error) {
	files := map[string]LocalFile{}
	err := fs.WalkDir(root.FS(), ".", func(rel string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() || d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		if !info.Mode().IsRegular() || Ignored(unit, rel, info.Size()) {
			return nil
		}
		files[rel] = LocalFile{Size: info.Size(), MTime: info.ModTime().Unix()}
		return nil
	})
	return files, err
}

// Hash fills in the sha256 of every file; the agent decides with it what must be uploaded.
// It reads only what the scan saw: no symlinks, nothing outside the unit, same size and mtime.
func (u *Unit) Hash(docroot string) error {
	root, err := u.open(docroot)
	if err != nil {
		return err
	}
	defer root.Close()
	for rel, f := range u.Files {
		file, err := openFile(root, u.Path, rel, f)
		if err != nil {
			return err
		}
		h := sha256.New()
		_, err = io.Copy(h, file)
		file.Close()
		if err != nil {
			return err
		}
		f.SHA256 = hex.EncodeToString(h.Sum(nil))
		u.Files[rel] = f
	}
	return nil
}

// open pins the unit directory again; it must still be the one the scan listed.
func (u *Unit) open(docroot string) (*os.Root, error) {
	root, _, err := openUnit(docroot, u.Path, u.dir)
	return root, err
}

// Request converts the unit into the manifest for /push/begin.
func (u *Unit) Request() agentapi.PushUnit {
	req := agentapi.PushUnit{Path: u.Path, Base: map[string]agentapi.PushStamp{}, Files: map[string]agentapi.PushFile{}}
	for rel, b := range u.Base {
		req.Base[rel] = agentapi.PushStamp{Size: b.Size, MTime: b.MTime}
	}
	for rel, f := range u.Files {
		req.Files[rel] = agentapi.PushFile{Size: f.Size, SHA256: f.SHA256, MTime: f.MTime}
	}
	return req
}

// Version reads the version from the plugin header or the theme's style.css; "" if there is none.
// Same rule as the agent (PushUnits::version), so both sides compare like with like.
func Version(dir, unit string) string {
	root, err := os.OpenRoot(dir)
	if err != nil {
		return ""
	}
	defer root.Close()
	return versionIn(root, unit)
}

// versionIn reads the version through a root pinned to the unit directory.
func versionIn(root *os.Root, unit string) string {
	if unit == muPlugins {
		return ""
	}
	marker := "plugin name:"
	candidates, _ := fs.Glob(root.FS(), "*.php")
	if strings.HasPrefix(unit, "themes/") {
		marker = "theme name:"
		candidates = []string{"style.css"}
	}
	for _, name := range candidates {
		file, err := root.OpenFile(name, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
		if err != nil {
			continue
		}
		head := make([]byte, 8192)
		n, _ := io.ReadFull(file, head)
		file.Close()
		if text := string(head[:n]); strings.Contains(strings.ToLower(text), marker) {
			if m := versionRe.FindStringSubmatch(text); m != nil {
				return m[1]
			}
		}
	}
	return ""
}
