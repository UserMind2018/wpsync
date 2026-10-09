package content

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/safefs"
)

// Files are the paths below <siteDir>/.wpsync/content (Spec Content-Push §4.3).
type Files struct{ Dir, Manifest, Map, Baseline, Unfaithful, Summary string }

const (
	manifestName   = "manifest.jsonl"
	mapName        = "map.json"
	baselineName   = "baseline.jsonl"
	unfaithfulName = "unfaithful.jsonl"
	summaryName    = "summary.json"
)

// Paths returns the content files of a site folder.
func Paths(siteDir string) Files {
	dir := filepath.Join(siteDir, ".wpsync", "content")
	return Files{Dir: dir, Manifest: filepath.Join(dir, manifestName), Map: filepath.Join(dir, mapName),
		Baseline: filepath.Join(dir, baselineName), Unfaithful: filepath.Join(dir, unfaithfulName), Summary: filepath.Join(dir, summaryName)}
}

// Map is the domain map of the pull (Spec Content-Push §5.1): exactly the values the pull gave to
// wp search-replace.
type Map struct {
	CanonVersion int                     `json:"canon_version"`
	Variants     []string                `json:"variants"`
	Live         agentapi.ContentOrigins `json:"live"`
	Local        string                  `json:"local"`
	PulledAt     string                  `json:"pulled_at"`
}

// Summary is the `content` object of pull --json (Spec Content-Push §10).
type Summary struct {
	Rows         int              `json:"rows"`
	Unfaithful   int              `json:"unfaithful"`
	IDMax        map[string]int64 `json:"id_max"`
	CanonVersion int              `json:"canon_version"`
	// Reloaded: this pull reloaded the content tables and rebuilt the baseline (B10). Set by the
	// pull, never stored: summary.json always says false.
	Reloaded bool `json:"reloaded"`
}

// ErrCanonVersion: the agent computes fingerprints in another canonical form than this CLI.
var ErrCanonVersion = errors.New("der Agent rechnet Fingerabdrücke in einer anderen Form als diese CLI")

// Source delivers the manifest of the live site; *agentapi.Client implements it.
type Source interface {
	ContentManifest(scope agentapi.Scope, w io.Writer) (*agentapi.ContentHead, int, error)
}

// open returns a root on <siteDir>/.wpsync/content, created without following a symlink. On the
// Mac .wpsync lies in the DDEV mount: site code must not redirect these files (SEC-113).
func open(siteDir string, create bool) (*os.Root, error) {
	if create {
		if err := os.MkdirAll(siteDir, 0o755); err != nil {
			return nil, err
		}
		return safefs.OpenTree(siteDir, filepath.Join(".wpsync", "content"))
	}
	return safefs.OpenDir(siteDir, filepath.Join(".wpsync", "content"))
}

// writeFile streams into a fresh temp file inside root (O_EXCL, never through a symlink) and
// renames it into place: a symlink at name is replaced itself, a reader never sees half a file.
func writeFile(root *os.Root, name string, fill func(io.Writer) error) error {
	tmp := name + safefs.TmpSuffix
	if err := root.Remove(tmp); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	f, err := root.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if err != nil {
		return err
	}
	w := bufio.NewWriterSize(f, 1<<20)
	err = fill(w)
	if err == nil {
		err = w.Flush()
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = root.Remove(tmp)
		return err
	}
	return root.Rename(tmp, name)
}

// WriteMap writes map.json and returns its ID: the sha256 of the file (map_id of a package).
func WriteMap(siteDir string, m Map) (string, error) {
	root, err := open(siteDir, true)
	if err != nil {
		return "", err
	}
	defer root.Close()
	return writeMap(root, m)
}

func writeMap(root *os.Root, m Map) (string, error) {
	data, err := json.MarshalIndent(m, "", "  ")
	if err != nil {
		return "", err
	}
	data = append(data, '\n')
	if err := writeFile(root, mapName, func(w io.Writer) error { _, err := w.Write(data); return err }); err != nil {
		return "", err
	}
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:]), nil
}

// ReadMap returns map.json and its ID.
func ReadMap(siteDir string) (Map, string, error) {
	var m Map
	root, err := open(siteDir, false)
	if err != nil {
		return m, "", err
	}
	defer root.Close()
	data, err := safefs.ReadFile(root, mapName)
	if err != nil {
		return m, "", err
	}
	if err := json.Unmarshal(data, &m); err != nil {
		return m, "", fmt.Errorf("map.json: %w", err)
	}
	sum := sha256.Sum256(data)
	return m, hex.EncodeToString(sum[:]), nil
}

// LoadSummary returns summary.json.
func LoadSummary(siteDir string) (*Summary, error) {
	root, err := open(siteDir, false)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	data, err := safefs.ReadFile(root, summaryName)
	if err != nil {
		return nil, err
	}
	var s Summary
	if err := json.Unmarshal(data, &s); err != nil {
		return nil, fmt.Errorf("summary.json: %w", err)
	}
	return &s, nil
}

// Fresh reports whether the site folder holds a complete content state of this CLI's canonical
// form. A pull with --content reloads the content tables when it does not (Plan B1).
func Fresh(siteDir string) bool {
	s, err := LoadSummary(siteDir)
	if err != nil || s.CanonVersion != CanonVersion {
		return false
	}
	root, err := open(siteDir, false)
	if err != nil {
		return false
	}
	defer root.Close()
	for _, name := range []string{manifestName, mapName, baselineName, unfaithfulName} {
		if info, err := safefs.Lstat(root, name); err != nil || !info.Mode().IsRegular() {
			return false
		}
	}
	return true
}

type recordLine struct {
	T string  `json:"t"`
	K string  `json:"k"`
	H *string `json:"h"`
}

// lines calls fn for every record line ({"t":…}) of a JSON-Lines file; other lines are skipped.
func lines(root *os.Root, name string, fn func(recordLine) error) error {
	f, err := safefs.Open(root, name)
	if err != nil {
		return err
	}
	defer f.Close()
	br := bufio.NewReaderSize(f, 1<<20)
	for {
		data, readErr := br.ReadBytes('\n')
		if bytes.HasPrefix(data, []byte(`{"t":`)) {
			var rec recordLine
			if err := json.Unmarshal(data, &rec); err != nil {
				return fmt.Errorf("%s: %w", name, err)
			}
			if err := fn(rec); err != nil {
				return err
			}
		}
		if readErr != nil {
			if errors.Is(readErr, io.EOF) {
				return nil
			}
			return readErr
		}
	}
}

// Compare writes unfaithful.jsonl: the keys of the baseline whose fingerprint is not the one of
// the manifest (Spec Content-Push §4.3). It returns the rows of the manifest and the number of
// unfaithful keys. A row of the manifest that is missing locally is not unfaithful – the pull
// profile filters rows.
func Compare(siteDir string) (rows, bad int, err error) {
	root, err := open(siteDir, false)
	if err != nil {
		return 0, 0, err
	}
	defer root.Close()
	return compare(root)
}

func compare(root *os.Root) (rows, bad int, err error) {
	live := map[string]string{} // t \x00 k → h; "" without fingerprint
	if err := lines(root, manifestName, func(r recordLine) error {
		h := ""
		if r.H != nil {
			h = *r.H
		}
		live[r.T+"\x00"+r.K] = h
		rows++
		return nil
	}); err != nil {
		return 0, 0, err
	}
	err = writeFile(root, unfaithfulName, func(w io.Writer) error {
		enc := json.NewEncoder(w)
		enc.SetEscapeHTML(false)
		return lines(root, baselineName, func(r recordLine) error {
			h, known := live[r.T+"\x00"+r.K]
			why := ""
			switch {
			case !known:
				why = "local_only"
			case h == "":
				why = "unnormalizable"
			case r.H == nil:
				why = "unnormalizable_local"
			case *r.H != h:
				why = "differs"
			}
			if why == "" {
				return nil
			}
			bad++
			return enc.Encode(struct {
				T   string `json:"t"`
				K   string `json:"k"`
				Why string `json:"why"`
			}{r.T, r.K, why})
		})
	})
	return rows, bad, err
}

// Refresh rebuilds the content state after a pull that reloaded the content tables: manifest of
// the live site, map.json, baseline of the local site and unfaithful.jsonl. summary.json goes
// first and comes back last, so a failed refresh never looks fresh.
func Refresh(siteDir string, src Source, r localenv.Runner, scope agentapi.Scope, localURL string, now time.Time) (*Summary, error) {
	root, err := open(siteDir, true)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	if err := root.Remove(summaryName); err != nil && !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}
	var head *agentapi.ContentHead
	if err := writeFile(root, manifestName, func(w io.Writer) error {
		var err error
		head, _, err = src.ContentManifest(scope, w)
		return err
	}); err != nil {
		return nil, err
	}
	if head == nil {
		return nil, fmt.Errorf("%w: der Kopf fehlt", agentapi.ErrManifest)
	}
	if head.CanonVersion != CanonVersion {
		return nil, fmt.Errorf("%w (Agent %d, CLI %d)", ErrCanonVersion, head.CanonVersion, CanonVersion)
	}
	if _, err := writeMap(root, Map{CanonVersion: head.CanonVersion, Variants: head.Variants, Live: head.Origins,
		Local: localURL, PulledAt: now.UTC().Format(time.RFC3339)}); err != nil {
		return nil, err
	}
	if err := writeFile(root, baselineName, func(w io.Writer) error {
		_, err := Export(r, localURL, nil, w)
		return err
	}); err != nil {
		return nil, err
	}
	rows, bad, err := compare(root)
	if err != nil {
		return nil, err
	}
	s := &Summary{Rows: rows, Unfaithful: bad, IDMax: head.IDMax, CanonVersion: head.CanonVersion}
	data, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return nil, err
	}
	if err := writeFile(root, summaryName, func(w io.Writer) error { _, err := w.Write(append(data, '\n')); return err }); err != nil {
		return nil, err
	}
	return s, nil
}
