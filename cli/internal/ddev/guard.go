package ddev

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Entry kinds in a snapshot. Only regular files can be trusted.
const (
	KindFile    = "file"
	KindSymlink = "symlink"
	KindOther   = "other"
)

// Entry is one protected path in .ddev.
type Entry struct {
	Kind   string `json:"kind"`
	SHA256 string `json:"sha256,omitempty"`
	Target string `json:"target,omitempty"` // symlink target, shown only
}

// Tree maps slash-separated paths below .ddev to their entries.
type Tree map[string]Entry

// generatedPrefix marks the compose files DDEV writes itself on every start.
const generatedPrefix = ".ddev-docker-compose-"

// Protected reports whether the ddev binary evaluates rel (relative to .ddev) on the host or it
// decides mounts and images: config, compose files, project commands, .env, providers. Paths that
// only take effect inside a container (nginx/, php/, web-build/ …) are not protected. Matching
// ignores case: macOS file systems usually do, so Config.yaml is read as config.yaml.
func Protected(rel string) bool {
	rel = strings.ToLower(filepath.ToSlash(rel))
	top, _, nested := strings.Cut(rel, "/")
	switch top {
	case "commands", "providers", "share-providers":
		return true
	}
	if nested {
		return false
	}
	switch {
	case rel == "config.yaml", rel == ".env":
		return true
	case strings.HasPrefix(rel, ".env."):
		return true
	case strings.HasPrefix(rel, generatedPrefix) && strings.HasSuffix(rel, ".yaml"):
		// ddev exec/wp/mysql read these without regenerating them.
		return true
	}
	for _, prefix := range []string{"config.", "docker-compose."} {
		if strings.HasPrefix(rel, prefix) && (strings.HasSuffix(rel, ".yaml") || strings.HasSuffix(rel, ".yml")) {
			return true
		}
	}
	return false
}

func generated(rel string) bool { return strings.HasPrefix(strings.ToLower(rel), generatedPrefix) }

// ErrDDEVSymlink: .ddev itself is a symlink, so its content may live anywhere.
var ErrDDEVSymlink = errors.New(".ddev ist ein Symlink – wpsync führt dort keinen ddev-Befehl aus")

// snapshotsDir is the one directory below .ddev the db container may write to.
const snapshotsDir = "db_snapshots"

// Snapshot hashes every protected path in siteDir/.ddev without following symlinks. A missing
// .ddev gives an empty tree. The content of db_snapshots is skipped (the db container may write
// there), but db_snapshots itself must be a real directory: DDEV mounts it writable, so a symlink
// would hand the container .ddev or any path on the Mac.
func Snapshot(siteDir string) (Tree, error) {
	tree, _, err := SnapshotContent(siteDir)
	return tree, err
}

// SnapshotContent is Snapshot plus the bytes each regular file was hashed from, so what the user
// is shown is exactly what gets trusted.
func SnapshotContent(siteDir string) (Tree, map[string][]byte, error) {
	root := filepath.Join(siteDir, ".ddev")
	info, err := os.Lstat(root)
	if errors.Is(err, fs.ErrNotExist) {
		return Tree{}, map[string][]byte{}, nil
	}
	if err != nil {
		return nil, nil, err
	}
	if info.Mode()&fs.ModeSymlink != 0 {
		return nil, nil, ErrDDEVSymlink
	}
	if !info.IsDir() {
		return nil, nil, fmt.Errorf(".ddev is not a directory")
	}
	tree, content := Tree{}, map[string][]byte{}
	err = filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if p == root {
			return nil
		}
		rel, err := filepath.Rel(root, p)
		if err != nil {
			return err
		}
		rel = filepath.ToSlash(rel)
		if d.IsDir() {
			if rel == snapshotsDir {
				return fs.SkipDir
			}
			return nil
		}
		if rel == snapshotsDir {
			// Not a directory: a symlink or file where DDEV expects the snapshot directory.
			target, _ := os.Readlink(p)
			tree[rel] = Entry{Kind: KindOther, Target: target}
			if d.Type()&fs.ModeSymlink != 0 {
				tree[rel] = Entry{Kind: KindSymlink, Target: target}
			}
			return nil
		}
		if !Protected(rel) {
			return nil
		}
		switch {
		case d.Type()&fs.ModeSymlink != 0:
			target, _ := os.Readlink(p)
			tree[rel] = Entry{Kind: KindSymlink, Target: target}
		case d.Type().IsRegular():
			data, err := os.ReadFile(p)
			if err != nil {
				return err
			}
			tree[rel] = Entry{Kind: KindFile, SHA256: hashBytes(data)}
			content[rel] = data
		default:
			tree[rel] = Entry{Kind: KindOther}
		}
		return nil
	})
	if err != nil {
		return nil, nil, fmt.Errorf("check .ddev: %w", err)
	}
	return tree, content, nil
}

func hashBytes(data []byte) string {
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:])
}

func hashString(s string) string { return hashBytes([]byte(s)) }

// Change kinds, shown to the user as they are.
const (
	ChangeAdded   = "neu"
	ChangeChanged = "geändert"
	ChangeMissing = "fehlt"
)

// Change is one protected path that differs from the trusted state.
type Change struct {
	Path string
	Kind string
	// Unsafe: a symlink or special file – never trusted, has to be removed.
	Unsafe bool
}

// Diff compares the current tree with the trusted one, sorted by path.
func Diff(want, got Tree) []Change {
	var out []Change
	for p, g := range got {
		unsafe := g.Kind != KindFile
		w, ok := want[p]
		switch {
		case !ok:
			out = append(out, Change{Path: p, Kind: ChangeAdded, Unsafe: unsafe})
		case w != g || unsafe:
			out = append(out, Change{Path: p, Kind: ChangeChanged, Unsafe: unsafe})
		}
	}
	for p := range want {
		if _, ok := got[p]; !ok {
			out = append(out, Change{Path: p, Kind: ChangeMissing})
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Path < out[j].Path })
	return out
}

// Fingerprint identifies exactly this set of changes in this state, so a scripted
// `wpsync trust --fingerprint` approves only what was shown.
func Fingerprint(changes []Change, got Tree) string {
	h := sha256.New()
	for _, c := range changes {
		e := got[c.Path]
		fmt.Fprintf(h, "%s\x00%s\x00%s\x00%s\x00%s\n", c.Kind, c.Path, e.Kind, e.SHA256, e.Target)
	}
	return hex.EncodeToString(h.Sum(nil))
}

// DeviationError stops before any ddev call: .ddev differs from the trusted state.
type DeviationError struct {
	Site    string
	Changes []Change
	// Fresh: first pull, .ddev should not exist yet.
	Fresh bool
}

func (e *DeviationError) Error() string {
	var b strings.Builder
	if e.Fresh {
		fmt.Fprintf(&b, "Vor dem ersten Pull von %s liegt schon ein .ddev mit Inhalt – es wurde kein ddev-Befehl ausgeführt.\n", e.Site)
	} else {
		fmt.Fprintf(&b, ".ddev von %s weicht vom geprüften Stand ab – es wurde kein ddev-Befehl ausgeführt.\n", e.Site)
	}
	b.WriteString("DDEV wertet diese Dateien auf dem Mac aus (Hooks, Host-Kommandos, Mounts):\n")
	unsafe := false
	for _, c := range e.Changes {
		fmt.Fprintf(&b, "  %-9s %s", c.Kind+":", agentapi.Printable(c.Path))
		if c.Unsafe {
			b.WriteString("  (Symlink/keine normale Datei – nicht freigebbar)")
			unsafe = true
		}
		b.WriteString("\n")
	}
	b.WriteString("Stammt die Änderung nicht von dir, Datei prüfen und entfernen – die Site könnte manipuliert sein.\n")
	if unsafe {
		b.WriteString("Symlinks und Sonderdateien an diesen Stellen müssen entfernt werden.\n")
	}
	if e.Fresh {
		b.WriteString("Den Ordner .ddev der Site entfernen; wpsync legt ihn beim Pull neu an.")
		return b.String()
	}
	fmt.Fprintf(&b, "Eigene Anpassung: ansehen und freigeben mit wpsync trust %s", e.Site)
	return b.String()
}

// ErrNotAdopted: an existing site has no trusted state yet and nobody confirmed the takeover.
var ErrNotAdopted = errors.New("noch kein geprüfter Stand für .ddev")

// stateFormat versions the state file.
const stateFormat = 1

// treeName is the key of .ddev in a state file; other trees could be added later.
const treeName = "ddev"

type stateFile struct {
	Version int             `json:"version"`
	Trees   map[string]Tree `json:"trees"`
}

// Store keeps the trusted state per site outside every directory a container mounts.
type Store struct {
	Dir string
}

// NewStore places the state below configDir and refuses a location inside sitesRoot: the site
// directories are mounted into the containers.
func NewStore(configDir, sitesRoot string) (Store, error) {
	dir := filepath.Join(configDir, "ddev-state")
	if within(sitesRoot, dir) {
		return Store{}, fmt.Errorf("WPSYNC_CONFIG_DIR liegt im Sites-Ordner %s – der geprüfte Stand von .ddev muss ausserhalb liegen", sitesRoot)
	}
	return Store{Dir: dir}, nil
}

func within(root, p string) bool {
	root, p = absClean(root), absClean(p)
	rel, err := filepath.Rel(root, p)
	return err == nil && (rel == "." || (rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))))
}

// absClean resolves symlinks in the longest existing prefix of p and appends the rest, so a
// path that does not exist yet compares like an existing one (/var vs /private/var on macOS).
func absClean(p string) string {
	if abs, err := filepath.Abs(p); err == nil {
		p = abs
	}
	p = filepath.Clean(p)
	var rest []string
	for {
		if real, err := filepath.EvalSymlinks(p); err == nil {
			return filepath.Join(append([]string{real}, rest...)...)
		}
		parent := filepath.Dir(p)
		if parent == p {
			return filepath.Join(append([]string{p}, rest...)...)
		}
		rest = append([]string{filepath.Base(p)}, rest...)
		p = parent
	}
}

// Path of a site's state file.
func (s Store) Path(site string) string {
	return filepath.Join(s.Dir, path.Base(site)+".json")
}

// Load returns the trusted tree; ok is false if the site has none yet.
func (s Store) Load(site string) (tree Tree, ok bool, err error) {
	data, err := os.ReadFile(s.Path(site))
	if errors.Is(err, fs.ErrNotExist) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	var f stateFile
	if err := json.Unmarshal(data, &f); err != nil {
		return nil, false, fmt.Errorf("parse %s: %w", s.Path(site), err)
	}
	if f.Version != stateFormat {
		return nil, false, fmt.Errorf("%s: unknown format %d", s.Path(site), f.Version)
	}
	t, ok := f.Trees[treeName]
	if !ok {
		return nil, false, nil
	}
	if t == nil {
		t = Tree{}
	}
	return t, true, nil
}

// Save writes the trusted tree with mode 0600.
func (s Store) Save(site string, tree Tree) error {
	if err := os.MkdirAll(s.Dir, 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(stateFile{Version: stateFormat, Trees: map[string]Tree{treeName: tree}}, "", "  ")
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(s.Dir, ".state-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := os.Rename(tmp.Name(), s.Path(site)); err != nil {
		return fmt.Errorf("save .ddev state: %w", err)
	}
	return nil
}
