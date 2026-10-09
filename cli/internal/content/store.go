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
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/safefs"
)

// Files are the paths below <siteDir>/.wpsync/content (Spec Content-Push §4.3).
type Files struct{ Dir, Manifest, Map, Baseline, Unfaithful, Env, Summary string }

const (
	manifestName   = "manifest.jsonl"
	mapName        = "map.json"
	baselineName   = "baseline.jsonl"
	unfaithfulName = "unfaithful.jsonl"
	envName        = "env.json"
	summaryName    = "summary.json"
)

// Paths returns the content files of a site folder.
func Paths(siteDir string) Files {
	dir := filepath.Join(siteDir, ".wpsync", "content")
	return Files{Dir: dir, Manifest: filepath.Join(dir, manifestName), Map: filepath.Join(dir, mapName),
		Baseline: filepath.Join(dir, baselineName), Unfaithful: filepath.Join(dir, unfaithfulName), Env: filepath.Join(dir, envName),
		Summary: filepath.Join(dir, summaryName)}
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

// Env is what the local runtime needs of the source to run WP-CLI in the site: the container
// driver picks its WP-CLI image by the PHP version and hands the table prefix to WordPress. The
// pull takes both from /delta; env.json keeps them, so `content export` runs without a request to
// the site. Nothing else of the source belongs here – no URL, no secret.
type Env struct {
	PHPVersion  string `json:"php_version"`
	TablePrefix string `json:"table_prefix"`
}

// Agent returns e as the Env a localenv.Driver is configured with.
func (e Env) Agent() agentapi.Env {
	return agentapi.Env{PHPVersion: e.PHPVersion, TablePrefix: e.TablePrefix}
}

// ErrEnv: env.json holds a value that must not reach docker or WP-CLI. Not agentapi.ErrInvalidEnv:
// that one names a value the source sent, this is a file of the site folder (exit code local_env).
var ErrEnv = errors.New("unzulässiger Wert")

// check applies the rules of /delta (agentapi.Env.InvalidArgs) to both values; unlike there, the
// PHP version must be present.
func (e Env) check() error {
	var bad []string
	if _, ok := agentapi.PHPMajorMinor(e.PHPVersion); !ok {
		bad = append(bad, "php_version "+agentapi.Printable(e.PHPVersion))
	}
	if !agentapi.ValidTablePrefix(e.TablePrefix) {
		bad = append(bad, "table_prefix "+agentapi.Printable(e.TablePrefix))
	}
	if len(bad) > 0 {
		return fmt.Errorf("env.json: %w: %s", ErrEnv, strings.Join(bad, ", "))
	}
	return nil
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

// ErrMap: the local URL of map.json is not a plain http(s) URL. It becomes an argument of
// `wp eval-file`, and the file lies in the site folder like env.json.
var ErrMap = errors.New("unzulässige lokale URL")

// checkLocalURL applies the rule of a site URL (agentapi.ValidSiteURL: http or https, a host, no
// whitespace, no control character) – such a value cannot start with a dash either.
func checkLocalURL(local string) error {
	if !agentapi.ValidSiteURL(local) {
		return fmt.Errorf("%w: %s", ErrMap, agentapi.Printable(local))
	}
	return nil
}

// maxRecordLine bounds one line of manifest.jsonl, baseline.jsonl and of the export. Generous: a
// row carries its values in base64, and a single value may be many megabytes. A variable for the
// tests.
var maxRecordLine = 256 << 20

// ReadMap returns map.json and its ID. The local URL is checked before anyone hands it to
// WP-CLI: ErrMap.
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
	if err := checkLocalURL(m.Local); err != nil {
		return Map{}, "", fmt.Errorf("map.json: %w", err)
	}
	sum := sha256.Sum256(data)
	return m, hex.EncodeToString(sum[:]), nil
}

func writeEnv(root *os.Root, e Env) error {
	data, err := json.MarshalIndent(e, "", "  ")
	if err != nil {
		return err
	}
	return writeFile(root, envName, func(w io.Writer) error { _, err := w.Write(append(data, '\n')); return err })
}

// ReadEnv returns env.json. The file lies in the site folder (on the Mac in the DDEV mount), so
// its values are checked before anyone hands them to docker or WP-CLI: ErrEnv.
func ReadEnv(siteDir string) (Env, error) {
	var e Env
	root, err := open(siteDir, false)
	if err != nil {
		return e, err
	}
	defer root.Close()
	data, err := safefs.ReadFile(root, envName)
	if err != nil {
		return e, err
	}
	if err := json.Unmarshal(data, &e); err != nil {
		return Env{}, fmt.Errorf("env.json: %w", err)
	}
	if err := e.check(); err != nil {
		return Env{}, err
	}
	return e, nil
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
	for _, name := range []string{manifestName, mapName, baselineName, unfaithfulName, envName} {
		if info, err := safefs.Lstat(root, name); err != nil || !info.Mode().IsRegular() {
			return false
		}
	}
	return true
}

// Invalidate drops the content state of a site folder: summary.json goes, so Fresh says no and the
// next pull with --content reloads the content tables and rebuilds the baseline. A pull without
// --content calls it before it replaces a content table (Plan B11). No state is no error – neither
// a missing folder nor one behind a symlink, which is never followed and never counts as fresh.
func Invalidate(siteDir string) error {
	root, err := open(siteDir, false)
	if errors.Is(err, os.ErrNotExist) || errors.Is(err, safefs.ErrSymlink) {
		return nil
	}
	if err != nil {
		return err
	}
	defer root.Close()
	if err := root.Remove(summaryName); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	return nil
}

type recordLine struct {
	T   string  `json:"t"`
	K   string  `json:"k"`
	H   *string `json:"h"`
	Why string  `json:"why"`
}

// noPrint marks a manifest row without a fingerprint in the map of compare; the reason follows.
// A fingerprint is hex and never starts with it.
const noPrint = "!"

// manifestWhy is the reason a manifest row carries no fingerprint, as unfaithful.jsonl names it:
// what the agent said (unnormalizable, key_encoding, pseudonymized) if it is a plain word, else
// unnormalizable. The manifest comes from the site; the reasons compare gives itself stay its own.
func manifestWhy(why string) string {
	if why == "" || len(why) > 32 || why == "differs" || why == "local_only" || why == "unnormalizable_local" {
		return "unnormalizable"
	}
	for _, c := range []byte(why) {
		if (c < 'a' || c > 'z') && c != '_' {
			return "unnormalizable"
		}
	}
	return why
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
		data, readErr := agentapi.ReadLine(br, maxRecordLine)
		if errors.Is(readErr, agentapi.ErrLineTooLong) {
			return fmt.Errorf("%s: %w (mehr als %d Bytes)", name, readErr, maxRecordLine)
		}
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
	live := map[string]string{} // t \x00 k → h; noPrint + reason without fingerprint
	if err := lines(root, manifestName, func(r recordLine) error {
		h := noPrint + manifestWhy(r.Why)
		if r.H != nil && !strings.HasPrefix(*r.H, noPrint) && *r.H != "" {
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
			case strings.HasPrefix(h, noPrint):
				why = strings.TrimPrefix(h, noPrint)
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
// the live site, map.json, env.json (env: PHP version and table prefix of the source as this pull
// got them), baseline of the local site and unfaithful.jsonl. summary.json goes first and comes
// back last, so a failed refresh never looks fresh.
func Refresh(siteDir string, src Source, r localenv.Runner, scope agentapi.Scope, localURL string, env Env, now time.Time) (*Summary, error) {
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
	if err := writeEnv(root, env); err != nil {
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
