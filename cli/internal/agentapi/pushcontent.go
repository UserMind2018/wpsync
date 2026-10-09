package agentapi

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"regexp"
)

// MinAgentContentPush is the first agent that takes content with a push (Spec Content-Push §7).
// Agent 0.7.0 was released with the channel; a build without it is told apart by its answer.
const MinAgentContentPush = "0.7.0"

// ContentCodePrefix starts the error code of every refusal of the content channel; the rest is
// the reason (conflict, blocked_row, …).
const ContentCodePrefix = "wpsync_content_"

// maxContentKeys bounds what the CLI takes from the site per answer, as the agent does
// (ContentException::MAX_KEYS).
const maxContentKeys = 200

// ContentKey names one row of a package or of the target: table without prefix and the key as in
// manifest and package (pairs carry "\x00" between object and name). Pattern: with
// pseudonym_in_package the id of the pattern that matched. Never a value.
type ContentKey struct {
	Table   string `json:"table"`
	Key     string `json:"key"`
	Pattern string `json:"pattern,omitempty"`
}

// ContentLimits is what the agent takes in one transaction and one request (Spec §7.5).
type ContentLimits struct {
	MaxRows       int   `json:"max_rows"`
	MaxBytes      int64 `json:"max_bytes"`
	BudgetSeconds int   `json:"budget_seconds"`
	// IDHeadroom is how far above the highest id of the target a new post, term or term_taxonomy
	// may lie (id_outside_corridor); 0 from an agent that does not name it.
	IDHeadroom int64 `json:"id_headroom,omitempty"`
}

// ContentExtensions are the project extensions a package was built with: what it pushes beyond
// the agent's whitelist. The agent names them in the dry run and keeps them in the record of the
// push (unit "content").
type ContentExtensions struct {
	PostTypes      []string `json:"post_types"`
	Taxonomies     []string `json:"taxonomies"`
	MetaExceptions []string `json:"meta_exceptions"`
}

var extensionRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,255}$`)

// Clean keeps at most 100 names per list that look like the agent's.
func (e *ContentExtensions) Clean() {
	clean := func(names []string) []string {
		out := []string{}
		for _, n := range names {
			if len(out) < 100 && extensionRe.MatchString(n) {
				out = append(out, n)
			}
		}
		return out
	}
	e.PostTypes, e.Taxonomies, e.MetaExceptions = clean(e.PostTypes), clean(e.Taxonomies), clean(e.MetaExceptions)
}

// ContentFailure is a refusal of the content channel, in the dry run inside the answer.
type ContentFailure struct {
	Code    string       `json:"code"` // the reason, without prefix
	Message string       `json:"message"`
	Keys    []ContentKey `json:"keys,omitempty"`
	Total   int          `json:"total,omitempty"` // number of keys on the site; Keys holds at most 200
	Paths   []string     `json:"paths,omitempty"` // upload_missing: relative to wp-content/uploads/
}

// ContentPlan is the part "content" of the answer to /push/begin.
type ContentPlan struct {
	OK         bool            `json:"ok"`
	Error      *ContentFailure `json:"error"`
	Rows       map[string]int  `json:"rows"` // rows of the package per table
	Limits     ContentLimits   `json:"limits"`
	Conflicts  []ContentKey    `json:"conflicts"`
	HealthURLs []string        `json:"health_urls"` // published pages the package changes, at most 10
	// Extensions: only when the package names project extensions.
	Extensions *ContentExtensions `json:"extensions,omitempty"`
}

// PushContentRef names the staged package of a push by the sha256 of the whole file.
type PushContentRef struct {
	SHA256 string `json:"sha256"`
}

// ContentAfter is the fingerprint of one key after the push; H nil: the key is gone.
type ContentAfter struct {
	T string  `json:"t"`
	K string  `json:"k"`
	H *string `json:"h"`
}

// PostAction is one step after applying or taking back content (Spec §7.7). A failed step is no
// failed push.
type PostAction struct {
	Step string `json:"step"`
	OK   bool   `json:"ok"`
}

// ContentApplied is the part "content" of the answer to /push/commit.
type ContentApplied struct {
	Rows        int            `json:"rows"`
	After       []ContentAfter `json:"after"`
	PostActions []PostAction   `json:"post_actions"`
	Seconds     float64        `json:"seconds"`
}

// PushCommitResult is everything /push/commit answers: the stamps of the swapped units and, for a
// push with content, what the agent applied.
type PushCommitResult struct {
	Stamps  map[string]map[string]PushStamp
	Content *ContentApplied
}

var (
	stepRe  = regexp.MustCompile(`^[a-z][a-z0-9_]{0,39}$`)
	hashRe  = regexp.MustCompile(`^[a-f0-9]{64}$`)
	tableRe = regexp.MustCompile(`^[a-z_]{1,32}$`)
)

// CleanKeys keeps at most 200 keys whose table looks like one and whose key is of a sane length.
// The key itself stays as delivered – it is data for the caller, written as JSON; for people it
// goes through Printable.
func CleanKeys(keys []ContentKey) []ContentKey {
	var out []ContentKey
	for _, k := range keys {
		if len(out) == maxContentKeys {
			break
		}
		if !tableRe.MatchString(k.Table) || k.Key == "" || len(k.Key) > 512 {
			continue
		}
		if !stepRe.MatchString(k.Pattern) {
			k.Pattern = ""
		}
		out = append(out, k)
	}
	return out
}

// CleanActions keeps at most 30 steps that look like the agent's names.
func CleanActions(actions []PostAction) []PostAction {
	var out []PostAction
	for _, a := range actions {
		if len(out) < 30 && stepRe.MatchString(a.Step) {
			out = append(out, a)
		}
	}
	return out
}

// Clean bounds and cleans what came from the site.
func (f *ContentFailure) Clean() {
	if !stepRe.MatchString(f.Code) {
		f.Code = "content_failed"
	}
	f.Message = CleanText(f.Message)
	f.Keys = CleanKeys(f.Keys)
	if len(f.Paths) > maxContentKeys {
		f.Paths = f.Paths[:maxContentKeys]
	}
}

// Clean bounds and cleans what came from the site.
func (p *ContentPlan) Clean() {
	if p.Error != nil {
		p.Error.Clean()
	}
	p.Conflicts = CleanKeys(p.Conflicts)
	if len(p.HealthURLs) > 10 {
		p.HealthURLs = p.HealthURLs[:10]
	}
	if p.Extensions != nil {
		p.Extensions.Clean()
	}
}

// Clean drops fingerprints that are none and bounds the lists.
func (a *ContentApplied) Clean() {
	var after []ContentAfter
	for _, e := range a.After {
		if len(after) < 4*5000 && tableRe.MatchString(e.T) && e.K != "" && (e.H == nil || hashRe.MatchString(*e.H)) {
			after = append(after, e)
		}
	}
	a.After = after
	a.PostActions = CleanActions(a.PostActions)
}

// ErrStage: the agent did not take the package as sent.
var ErrStage = errors.New("das Inhalts-Paket liess sich nicht auf der Site ablegen")

type stageAnswer struct {
	SHA256   string `json:"sha256"`
	Received int64  `json:"received"`
	Complete bool   `json:"complete"`
}

// ContentStage puts a package on the site (/content/stage), in pieces of at most chunk raw bytes.
// It asks first what is there: a package that lies complete is not sent again, an interrupted
// upload continues. Needs no open push window. It reports whether bytes were sent.
func (c *Client) ContentStage(sha256 string, size int64, r io.ReaderAt, chunk int) (sent bool, err error) {
	const route = "/wpsync/v1/content/stage"
	var have stageAnswer
	if err := c.PostJSON(route, map[string]any{"sha256": sha256, "size": size}, &have); err != nil {
		return false, err
	}
	if have.Complete {
		return false, nil
	}
	if have.Received < 0 || have.Received >= size {
		have.Received = 0
	}
	for off := have.Received; off < size; {
		buf := make([]byte, min(int64(chunk), size-off))
		if _, err := r.ReadAt(buf, off); err != nil && !(errors.Is(err, io.EOF) && off+int64(len(buf)) == size) {
			return sent, err
		}
		var res stageAnswer
		if err := c.PostJSON(route, map[string]any{"sha256": sha256, "size": size, "offset": off, "data": buf}, &res); err != nil {
			return sent, err
		}
		sent = true
		off += int64(len(buf))
		if res.Received != off || res.Complete != (off == size) {
			return sent, fmt.Errorf("%w: der Agent bestätigt %d von %d Bytes", ErrStage, res.Received, off)
		}
	}
	return sent, nil
}

// ContentStageBytes is ContentStage for a package held in memory.
func (c *Client) ContentStageBytes(sha256 string, data []byte, chunk int) (bool, error) {
	return c.ContentStage(sha256, int64(len(data)), bytes.NewReader(data), chunk)
}

// PushCommitFull is PushCommit and also returns what the agent did with the content of the push.
func (c *Client) PushCommitFull(pushID string) (*PushCommitResult, error) {
	var cursor *pushCursor
	for {
		var res struct {
			Next    *pushCursor                     `json:"next"`
			Stamps  map[string]map[string]PushStamp `json:"stamps"`
			Content *ContentApplied                 `json:"content"`
		}
		if err := c.PostJSON("/wpsync/v1/push/commit", map[string]any{"push_id": pushID, "cursor": cursor}, &res); err != nil {
			return nil, err
		}
		if res.Next == nil {
			if res.Content != nil {
				res.Content.Clean()
			}
			return &PushCommitResult{Stamps: res.Stamps, Content: res.Content}, nil
		}
		// Each call places at least one file; a cursor that does not move would loop forever.
		if cursor != nil && (res.Next.U < cursor.U || (res.Next.U == cursor.U && res.Next.I <= cursor.I)) {
			return nil, fmt.Errorf("push commit: agent cursor did not advance (%d/%d)", res.Next.U, res.Next.I)
		}
		cursor = res.Next
	}
}
