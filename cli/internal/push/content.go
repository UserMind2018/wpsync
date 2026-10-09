package push

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/content"
	"github.com/usermind/wpsync/internal/safefs"
)

// ContentUnit is how a push with a package names its content in results and in the push log. It is
// no unit of the request: the package is staged before and named by its sha256 (Spec Content-Push §7).
const ContentUnit = "content"

// maxPackageBytes bounds the file of --content: what the agent stages at most, plus the head line.
const maxPackageBytes = 16<<20 + 64<<10

// ErrAgentNoContent: the agent has no content channel (agent < 0.7.0 or a build without it).
var ErrAgentNoContent = errors.New("der Agent auf der Site kann noch keine Inhalte pushen")

// ContentError is a refusal of the content of a push – found locally or by the agent. Reason is
// the error.reason of --json (package_invalid, conflict, blocked_row, …), Keys the rows it is
// about, Paths the files of upload_missing. Never a value of a row.
type ContentError struct {
	Reason  string
	Message string
	Keys    []agentapi.ContentKey
	Paths   []string
	Err     error // the agent's answer, if it was one
}

func (e *ContentError) Error() string {
	msg := e.Message
	if msg == "" {
		msg = "Inhalte abgelehnt"
	}
	var shown []string
	for i, k := range e.Keys {
		if i == 5 {
			shown = append(shown, fmt.Sprintf("und %d weitere", len(e.Keys)-5))
			break
		}
		shown = append(shown, k.Table+" "+agentapi.Printable(strings.ReplaceAll(k.Key, "\x00", " ")))
	}
	for i, p := range e.Paths {
		if i == 5 {
			shown = append(shown, fmt.Sprintf("und %d weitere", len(e.Paths)-5))
			break
		}
		shown = append(shown, agentapi.Printable("wp-content/uploads/"+p))
	}
	if len(shown) > 0 {
		msg += " – " + strings.Join(shown, ", ")
	}
	return fmt.Sprintf("%s (%s)", msg, e.Reason)
}

func (e *ContentError) Unwrap() error { return e.Err }

// invalidPackage is a package this CLI refuses before any request.
func invalidPackage(format string, args ...any) *ContentError {
	return &ContentError{Reason: "package_invalid", Message: "--content: " + fmt.Sprintf(format, args...)}
}

// contentFailure turns the refusal inside a dry run's answer into the error.
func contentFailure(f *agentapi.ContentFailure) *ContentError {
	return &ContentError{Reason: f.Code, Message: f.Message, Keys: f.Keys, Paths: f.Paths}
}

// contentError ties a refusal of the agent's content channel (code wpsync_content_<reason>) to a
// ContentError; the agent's answer stays reachable for errors.As. Other errors pass.
func contentError(err error) error {
	var apiErr *agentapi.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	reason, ok := strings.CutPrefix(apiErr.Code, agentapi.ContentCodePrefix)
	if !ok || reason == "stage" || reason == "offset" || reason == "size" || reason == "hash" || reason == "store" {
		return err // the transport of the package, not a verdict on it
	}
	return &ContentError{Reason: reason, Message: apiErr.Message, Keys: apiErr.Keys, Paths: apiErr.Paths, Err: err}
}

// PackageHead is what the CLI reads of the head line; the agent checks all of it.
type PackageHead struct {
	CanonVersion int    `json:"canon_version"`
	Home         string `json:"home"`
	MapID        string `json:"map_id"`
	Rows         int    `json:"rows"`
	SHA256       string `json:"sha256"` // of everything after the head line
}

// PackageRow is one row of a package as the CLI needs it after the push: Row is kept as written
// (normalized columns, base64) for the baseline.
type PackageRow struct {
	Op    string          `json:"op"`
	Table string          `json:"table"`
	Key   string          `json:"key"`
	Row   json.RawMessage `json:"row"`
}

// Package is the file of --content (Spec Content-Push §7.1), read and checked for its form.
type Package struct {
	SHA256 string // of the whole file – the name the agent stages it under
	Data   []byte
	Head   PackageHead
	Rows   []PackageRow
}

var (
	sha256Re     = regexp.MustCompile(`^[a-f0-9]{64}$`)
	packageOps   = map[string]bool{"update": true, "insert": true, "trash": true}
	packageTable = map[string]bool{"posts": true, "postmeta": true, "terms": true, "term_taxonomy": true, "term_relationships": true, "termmeta": true, "options": true}
)

// LoadPackage reads package.jsonl and checks what can be checked without the site: a head line,
// lines ending in "\n" only, the checksum of the body, the number of rows, each row an object with
// a known op and table and a key of its own. Everything else is the agent's.
func LoadPackage(file string) (*Package, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, fmt.Errorf("--content: %w", err)
	}
	defer f.Close()
	data, err := io.ReadAll(io.LimitReader(f, maxPackageBytes+1))
	if err != nil {
		return nil, fmt.Errorf("--content: %w", err)
	}
	switch {
	case len(data) > maxPackageBytes:
		return nil, &ContentError{Reason: "package_too_large", Message: "--content: das Paket ist grösser als 16 MB"}
	case len(data) == 0 || data[len(data)-1] != '\n':
		return nil, invalidPackage("die Datei endet nicht mit einem Zeilenvorschub")
	case bytes.ContainsRune(data, '\r'):
		return nil, invalidPackage("Zeilenende muss \\n sein, nicht \\r\\n")
	}
	headLine, body, _ := bytes.Cut(data, []byte("\n"))
	var wrap struct {
		Head *PackageHead `json:"head"`
	}
	if json.Unmarshal(headLine, &wrap) != nil || wrap.Head == nil {
		return nil, invalidPackage("die erste Zeile ist kein Kopf {\"head\": …}")
	}
	whole := sha256.Sum256(data)
	sum := sha256.Sum256(body)
	p := &Package{SHA256: hex.EncodeToString(whole[:]), Data: data, Head: *wrap.Head}
	if !sha256Re.MatchString(p.Head.SHA256) || p.Head.SHA256 != hex.EncodeToString(sum[:]) {
		return nil, invalidPackage("die Prüfsumme im Kopf passt nicht zu den Zeilen")
	}
	seen := map[content.Key]bool{}
	for n, line := range bytes.Split(bytes.TrimSuffix(body, []byte("\n")), []byte("\n")) {
		var row PackageRow
		if len(body) == 0 || json.Unmarshal(line, &row) != nil || !packageOps[row.Op] || !packageTable[row.Table] || row.Key == "" {
			return nil, invalidPackage("Zeile %d ist keine Zeile {op, table, key, expected, row}", n+1)
		}
		key := content.Key{T: row.Table, K: row.Key}
		if seen[key] {
			return nil, invalidPackage("Zeile %d: der Schlüssel kommt doppelt vor", n+1)
		}
		seen[key] = true
		p.Rows = append(p.Rows, row)
	}
	if len(p.Rows) != p.Head.Rows {
		return nil, invalidPackage("der Kopf nennt %d Zeilen, das Paket hat %d", p.Head.Rows, len(p.Rows))
	}
	return p, nil
}

// CheckSite compares the package with the content state of the site folder: it must be built
// from the last pull with --content. A package from an older pull would only meet conflicts.
func (p *Package) CheckSite(siteDir string) error {
	outdated := func(why string) error {
		return &ContentError{Reason: "baseline_outdated", Message: "--content: " + why + " – erneut ziehen (wpsync pull --content) und das Paket neu bauen"}
	}
	if !content.Fresh(siteDir) {
		return outdated("für diese Site gibt es keinen aktuellen Inhaltsstand")
	}
	m, id, err := content.ReadMap(siteDir)
	if err != nil {
		return outdated("map.json ist nicht lesbar")
	}
	if p.Head.CanonVersion != content.CanonVersion {
		return outdated("das Paket hat eine andere kanonische Form als diese CLI")
	}
	if p.Head.MapID != id {
		return outdated("das Paket gehört zu einem anderen Pull")
	}
	if p.Head.Home != m.Live.Home {
		return &ContentError{Reason: "origin_mismatch", Message: "--content: das Paket ist für eine andere Site gebaut"}
	}
	return nil
}

// stagePackage puts the package on the site; an agent without the route has no content channel.
func stagePackage(o Options, pkg *Package) error {
	sent, err := o.Client.ContentStageBytes(pkg.SHA256, pkg.Data, o.ChunkBytes)
	if err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) && apiErr.Code == "rest_no_route" {
			return fmt.Errorf("%w: %w", ErrAgentNoContent, err)
		}
		return contentError(err)
	}
	if sent {
		fmt.Fprintf(o.Out, "Inhalts-Paket abgelegt (%d Zeilen, %d Bytes)\n", len(pkg.Rows), len(pkg.Data))
	}
	return nil
}

// printContent shows the agent's check of the package.
func printContent(out io.Writer, pkg *Package, plan *agentapi.ContentPlan) {
	fmt.Fprintf(out, "content – %d Zeilen", len(pkg.Rows))
	for _, table := range []string{"posts", "postmeta", "terms", "term_taxonomy", "term_relationships", "termmeta", "options"} {
		if n := plan.Rows[table]; n > 0 {
			fmt.Fprintf(out, ", %s %d", table, n)
		}
	}
	fmt.Fprintln(out)
	if !plan.OK && plan.Error != nil {
		fmt.Fprintf(out, "  ! %v\n", contentFailure(plan.Error))
	}
	if plan.Partial {
		fmt.Fprintln(out, "  ! Push-Fenster geschlossen: Inhalte nur teilweise geprüft – Verweise auf Objekte der Site und die Dateien von Attachments prüft der Agent erst mit offenem Fenster")
	}
}

// printActions names the steps after applying or taking back content that did not work.
func printActions(out io.Writer, actions []agentapi.PostAction) {
	for _, a := range actions {
		if !a.OK {
			fmt.Fprintf(out, "  ! Nacharbeit %s ist nicht gelungen – bitte auf der Site von Hand nachholen\n", a.Step)
		}
	}
}

// contentUndoName is the file next to the journal with the lines of manifest and baseline a push
// replaced.
func contentUndoName(pushID string) string { return pushID + ".content.json" }

// errForeignAfter: the commit named a fingerprint for a key that is not part of the push.
var errForeignAfter = errors.New("der Agent nennt einen Abdruck für einen Schlüssel, der nicht zum Paket gehört")

// trashMetaNames are the meta keys the agent writes itself at a post with op trash
// (ContentPackage::TRASH_META).
var trashMetaNames = []string{"_wp_trash_meta_status", "_wp_trash_meta_time", "_wp_desired_post_slug"}

// trashMeta reports whether table and key name one of the meta pairs the agent writes for a post
// the package moves to the trash.
func trashMeta(rows map[content.Key]PackageRow, table, key string) bool {
	id, name, ok := strings.Cut(key, "\x00")
	if table != "postmeta" || !ok || !slices.Contains(trashMetaNames, name) {
		return false
	}
	post, ok := rows[content.Key{T: "posts", K: id}]
	return ok && post.Op == "trash"
}

// applyContent brings manifest and baseline of the site folder to the state the agent reports
// and keeps what they held before for a rollback.
func applyContent(siteDir string, j *Journal, pkg *Package, after []agentapi.ContentAfter) error {
	rows := map[content.Key]PackageRow{}
	for _, r := range pkg.Rows {
		rows[content.Key{T: r.Table, K: r.Key}] = r
	}
	// The fingerprints are the site's word. They count only for keys the package holds and for the
	// meta the agent writes itself when a post of the package goes to the trash – anything else
	// would rewrite lines of manifest and baseline the push never touched.
	for _, a := range after {
		if _, ok := rows[content.Key{T: a.T, K: a.K}]; !ok && !trashMeta(rows, a.T, a.K) {
			return fmt.Errorf("%w: %s %s", errForeignAfter, agentapi.Printable(a.T), agentapi.Printable(a.K))
		}
	}
	changes := map[content.Key]content.Change{}
	for _, a := range after {
		key := content.Key{T: a.T, K: a.K}
		c := content.Change{H: a.H}
		if r, ok := rows[key]; ok {
			c.Row, c.Trash = r.Row, r.Op == "trash"
		}
		changes[key] = c
	}
	undo, err := content.Patch(siteDir, changes)
	if err != nil {
		return err
	}
	data, err := json.Marshal(undo)
	if err != nil {
		return err
	}
	root, err := safefs.OpenTree(siteDir, journalRel)
	if err != nil {
		return err
	}
	defer root.Close()
	if err := safefs.WriteFile(root, contentUndoName(j.PushID), bytes.NewReader(data), int64(len(data)), time.Time{}, 0o600); err != nil {
		return err
	}
	j.Content.Applied = true
	return nil
}

// revertContent takes applyContent back after the push was rolled back on the site.
func revertContent(siteDir string, j *Journal) error {
	root, err := safefs.OpenDir(siteDir, journalRel)
	if err != nil {
		return err
	}
	defer root.Close()
	data, err := safefs.ReadFile(root, contentUndoName(j.PushID))
	if err != nil {
		return err
	}
	var undo content.Undo
	if err := json.Unmarshal(data, &undo); err != nil {
		return err
	}
	if err := content.Unpatch(siteDir, &undo); err != nil {
		return err
	}
	j.Content.Applied = false
	return nil
}
