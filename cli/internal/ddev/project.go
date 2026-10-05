package ddev

import (
	"errors"
	"io"
	"os"
	"path/filepath"
)

// Guarded runs ddev only after Before passed and calls After once a call succeeded. Every
// project-related ddev call of wpsync goes through it (SEC: site code in the container must not
// reach the Mac through .ddev).
type Guarded struct {
	Inner  Runner
	Before func(args []string) error
	After  func(args []string) error
}

// Run implements Runner.
func (g *Guarded) Run(args ...string) error {
	if err := g.Before(args); err != nil {
		return err
	}
	if err := g.Inner.Run(args...); err != nil {
		return err
	}
	return g.After(args)
}

// Output implements Runner.
func (g *Guarded) Output(args ...string) (string, error) {
	if err := g.Before(args); err != nil {
		return "", err
	}
	out, err := g.Inner.Output(args...)
	if err != nil {
		return "", err
	}
	return out, g.After(args)
}

// RunStdin implements Runner.
func (g *Guarded) RunStdin(stdin io.Reader, args ...string) error {
	if err := g.Before(args); err != nil {
		return err
	}
	if err := g.Inner.RunStdin(stdin, args...); err != nil {
		return err
	}
	return g.After(args)
}

// Project is a site's DDEV project together with its trusted .ddev state.
type Project struct {
	Name   string
	Dir    string
	Store  Store
	Docker Docker

	trusted Tree
	known   bool // a trusted state exists (stored or started fresh)
}

// OpenProject loads the trusted state of a site.
func OpenProject(name, dir string, store Store, d Docker) (*Project, error) {
	tree, ok, err := store.Load(name)
	if err != nil {
		return nil, err
	}
	return &Project{Name: name, Dir: dir, Store: store, Docker: d, trusted: tree, known: ok}, nil
}

// Trusted reports whether the site has a trusted state.
func (p *Project) Trusted() bool { return p.known }

// StartFresh is the first pull: no DDEV project yet, so .ddev must not hold any protected path.
// Whatever an older state said no longer applies.
func (p *Project) StartFresh() {
	p.trusted, p.known = Tree{}, true
}

// Runner wraps inner so that every call is checked first.
func (p *Project) Runner(inner Runner) Runner {
	return &Guarded{Inner: inner, Before: p.before, After: p.after}
}

// Changes compares .ddev with the trusted state (empty if there is none).
func (p *Project) Changes() ([]Change, Tree, error) {
	got, err := Snapshot(p.Dir)
	if err != nil {
		return nil, nil, err
	}
	return Diff(p.trusted, got), got, nil
}

// Review is Changes plus the bytes each file was hashed from, for showing them before Trust.
func (p *Project) Review() ([]Change, Tree, map[string][]byte, error) {
	got, content, err := SnapshotContent(p.Dir)
	if err != nil {
		return nil, nil, nil, err
	}
	return Diff(p.trusted, got), got, content, nil
}

// Check fails with a DeviationError if .ddev differs from the trusted state. Before start and
// restart, DDEV's own generated compose files may differ: ddev writes them anew before using them.
func (p *Project) Check(args ...string) error {
	if !p.known {
		return ErrNotAdopted
	}
	changes, _, err := p.Changes()
	if err != nil {
		return err
	}
	if regenerates(args) {
		kept := changes[:0]
		for _, c := range changes {
			if !generated(c.Path) || c.Unsafe {
				kept = append(kept, c)
			}
		}
		changes = kept
	}
	if len(changes) > 0 {
		return &DeviationError{Site: p.Name, Changes: changes}
	}
	return nil
}

func (p *Project) before(args []string) error { return p.Check(args...) }

// after records what DDEV itself writes as trusted: config.yaml on `ddev config`, the generated
// compose files on start and restart (A5). After a start the containers must be hardened first.
// Every other call (wp, mysql, describe, stop) runs site code or hooks and writes nothing
// protected, so a change afterwards is a deviation, not a new trusted state.
func (p *Project) after(args []string) error {
	verb := ""
	if len(args) > 0 {
		verb = args[0]
	}
	switch verb {
	case "start", "restart":
		if err := VerifyHardened(p.Docker, p.Name, p.Dir); err != nil {
			return err
		}
		return p.Accept()
	case "config":
		return p.Accept()
	}
	return p.Check(args...)
}

func regenerates(args []string) bool {
	return len(args) > 0 && (args[0] == "start" || args[0] == "restart")
}

// ErrUnsafeEntry: a symlink or special file at a protected path cannot be trusted.
var ErrUnsafeEntry = errors.New("Symlink oder Sonderdatei in .ddev – erst entfernen")

// Accept stores the current .ddev as trusted.
func (p *Project) Accept() error {
	got, err := Snapshot(p.Dir)
	if err != nil {
		return err
	}
	return p.Trust(got)
}

// Trust stores exactly the given snapshot as trusted – the one the user was shown, so a later
// change is still a deviation.
func (p *Project) Trust(tree Tree) error {
	for _, e := range tree {
		if e.Kind != KindFile {
			return ErrUnsafeEntry
		}
	}
	if err := p.Store.Save(p.Name, tree); err != nil {
		return err
	}
	p.trusted, p.known = tree, true
	return nil
}

// EnsureOwnFiles writes wpsync's compose files if they are missing or outdated (new hardening,
// other mailguard path) and records them as trusted. Call it after Check passed: a file that
// differs from the trusted state is a deviation, not an update. changed means DDEV must restart.
func (p *Project) EnsureOwnFiles(mailguardSource string) (bool, error) {
	files, err := OwnFiles(mailguardSource)
	if err != nil {
		return false, err
	}
	changed := false
	for name, content := range files {
		path := filepath.Join(p.Dir, ".ddev", name)
		if old, err := os.ReadFile(path); err == nil && string(old) == content {
			continue
		}
		if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
			return false, err
		}
		p.trusted[name] = Entry{Kind: KindFile, SHA256: hashString(content)}
		changed = true
	}
	if changed {
		if err := p.Store.Save(p.Name, p.trusted); err != nil {
			return false, err
		}
	}
	return changed, nil
}
