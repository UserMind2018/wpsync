package agentapi

import "fmt"

// PushStamp identifies a file version on the server, as the baseline stores it.
type PushStamp struct {
	Size  int64 `json:"size"`
	MTime int64 `json:"mtime"`
}

// PushFile is one file of a unit as it shall look after the push.
type PushFile struct {
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
	MTime  int64  `json:"mtime"`
}

// PushUnit is a whole plugin, theme or mu-plugins: what the client last saw (Base) and what it
// wants there (Files). Paths are relative to the unit.
type PushUnit struct {
	Path  string               `json:"path"`
	Base  map[string]PushStamp `json:"base"`
	Files map[string]PushFile  `json:"files"`
}

// PushBeginRequest asks the agent to check a push. With Dry nothing is created and no push
// window is needed.
type PushBeginRequest struct {
	Target string     `json:"target"`
	Force  bool       `json:"force"`
	Dry    bool       `json:"dry"`
	Units  []PushUnit `json:"units"`
}

// PushUnitPlan is the agent's view of one unit.
type PushUnitPlan struct {
	Path      string   `json:"path"`
	Exists    bool     `json:"exists"`
	Version   string   `json:"version"`
	Conflicts []string `json:"conflicts"` // changed on the server since the client's baseline
	Need      []string `json:"need"`      // files the server does not have in this version
	Writable  bool     `json:"writable"`
}

// PushPending is a push that was swapped in but never confirmed.
type PushPending struct {
	PushID  string `json:"push_id"`
	Device  string `json:"device"`
	Created int64  `json:"created"`
}

// PushRescue locates the rollback script that works without WordPress.
type PushRescue struct {
	URL  string `json:"url"`
	Salt string `json:"salt"`
}

// PushBegin is the answer to /push/begin. PushID and Rescue.Salt are empty for a dry run.
// Target is the target the agent answers for (agent 0.5.0); an older agent names none.
type PushBegin struct {
	PushID       string         `json:"push_id"`
	Target       string         `json:"target"`
	AgentVersion string         `json:"agent_version"`
	HealthURLs   []string       `json:"health_urls"`
	WindowOpen   bool           `json:"window_open"`
	Pending      *PushPending   `json:"pending"`
	Units        []PushUnitPlan `json:"units"`
	Rescue       PushRescue     `json:"rescue"`
}

// PushChunk is a file or a piece of one; Data travels base64-encoded.
type PushChunk struct {
	Path   string `json:"path"`
	Offset int64  `json:"offset"`
	Data   []byte `json:"data"`
}

type pushCursor struct {
	U int `json:"u"`
	I int `json:"i"`
}

// PushRecordUnit summarizes one unit of a past push.
type PushRecordUnit struct {
	Path       string `json:"path"`
	Exists     bool   `json:"exists"`
	OldVersion string `json:"old_version"`
	NewVersion string `json:"new_version"`
	Files      int    `json:"files"`
	Uploaded   int    `json:"uploaded"`
}

// PushRecord is one line of the push log. Status: uploading, committed, confirmed, rolled_back,
// failed or expired. Pruned means the snapshot is gone.
type PushRecord struct {
	PushID    string           `json:"push_id"`
	Device    string           `json:"device"`
	Target    string           `json:"target"`
	Status    string           `json:"status"`
	Forced    bool             `json:"forced"`
	Pruned    bool             `json:"pruned"`
	Units     []PushRecordUnit `json:"units"`
	Created   int64            `json:"created"`
	Committed *int64           `json:"committed"`
	Finished  *int64           `json:"finished"`
}

// PushBegin checks a push and, unless req.Dry, creates it.
func (c *Client) PushBegin(req PushBeginRequest) (*PushBegin, error) {
	var res PushBegin
	if err := c.PostJSON("/wpsync/v1/push/begin", req, &res); err != nil {
		return nil, err
	}
	return &res, nil
}

// PushUpload sends files (or pieces of files) of the unit with the given index.
func (c *Client) PushUpload(pushID string, unit int, chunks []PushChunk) error {
	var res struct{}
	return c.PostJSON("/wpsync/v1/push/upload", map[string]any{"push_id": pushID, "unit": unit, "files": chunks}, &res)
}

// PushCommit builds and swaps the units; large units take several requests. It returns the new
// stamps per unit for the baseline.
func (c *Client) PushCommit(pushID string) (map[string]map[string]PushStamp, error) {
	var cursor *pushCursor
	for {
		var res struct {
			Next   *pushCursor                     `json:"next"`
			Stamps map[string]map[string]PushStamp `json:"stamps"`
		}
		if err := c.PostJSON("/wpsync/v1/push/commit", map[string]any{"push_id": pushID, "cursor": cursor}, &res); err != nil {
			return nil, err
		}
		if res.Next == nil {
			return res.Stamps, nil
		}
		// Each call places at least one file; a cursor that does not move would loop forever.
		if cursor != nil && (res.Next.U < cursor.U || (res.Next.U == cursor.U && res.Next.I <= cursor.I)) {
			return nil, fmt.Errorf("push commit: agent cursor did not advance (%d/%d)", res.Next.U, res.Next.I)
		}
		cursor = res.Next
	}
}

// PushConfirm marks a swapped push as healthy.
func (c *Client) PushConfirm(pushID string) error {
	var res struct{}
	return c.PostJSON("/wpsync/v1/push/confirm", map[string]any{"push_id": pushID}, &res)
}

// PushRollback restores the snapshot through WordPress.
func (c *Client) PushRollback(pushID string) error {
	var res struct{}
	return c.PostJSON("/wpsync/v1/push/rollback", map[string]any{"push_id": pushID}, &res)
}

// PushList returns the latest pushes, newest first.
func (c *Client) PushList() ([]PushRecord, error) {
	var res struct {
		Pushes []PushRecord `json:"pushes"`
	}
	if err := c.PostJSON("/wpsync/v1/push/list", map[string]any{}, &res); err != nil {
		return nil, err
	}
	return res.Pushes, nil
}
