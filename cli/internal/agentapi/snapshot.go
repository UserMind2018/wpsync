package agentapi

import (
	"fmt"
	"io"
	"strconv"
)

// MinAgentSnapshot is the first agent that dumps the tables of a pull in one consistent read
// transaction on the server (Spec 0.11.0). Set to the released version (Spec: Version).
const MinAgentSnapshot = "0.11.0"

// SnapshotBlockSize is the size of every snapshot block but the last; the agent always writes
// 1 MiB. A variable only so tests can use small blocks.
var SnapshotBlockSize int64 = 1 << 20

// States of a snapshot job.
const (
	SnapshotRunning = "running"
	SnapshotDone    = "done"
	SnapshotFailed  = "failed"
)

// SnapshotRequest starts a snapshot. ID is chosen by the CLI: a repeated begin (backoff after
// 502/504) finds the same job instead of starting a second dump.
type SnapshotRequest struct {
	ID     string   `json:"id"`
	Tables []string `json:"tables"`
	Limit  int      `json:"limit"`
	Scope  Scope    `json:"scope"`
}

// SnapshotStatus is the agent's view of one job – never its key, file or path (Spec C5).
type SnapshotStatus struct {
	ID           string           `json:"id"`
	State        string           `json:"state"`
	TablesDone   int              `json:"tables_done"`
	TablesTotal  int              `json:"tables_total"`
	Table        string           `json:"table"`
	BytesPlain   int64            `json:"bytes_plain"`
	Blocks       int              `json:"blocks"`
	BlockSize    int64            `json:"block_size"`
	Size         int64            `json:"size"`
	SHA256       string           `json:"sha256"`
	Rows         map[string]int64 `json:"rows"`
	Inconsistent []string         `json:"inconsistent"`
	Code         string           `json:"code"`
	Error        string           `json:"error"`
	Seconds      float64          `json:"seconds"`
}

// SnapshotBegin starts a snapshot job (or returns the job of the same ID).
func (c *Client) SnapshotBegin(req SnapshotRequest) (*SnapshotStatus, error) {
	var st SnapshotStatus
	if err := c.PostJSON("/wpsync/v1/db-snapshot/begin", req, &st); err != nil {
		return nil, err
	}
	return &st, nil
}

// SnapshotStatus asks for the state of a job.
func (c *Client) SnapshotStatus(id string) (*SnapshotStatus, error) {
	var st SnapshotStatus
	if err := c.PostJSON("/wpsync/v1/db-snapshot/status", map[string]any{"id": id}, &st); err != nil {
		return nil, err
	}
	return &st, nil
}

// SnapshotBlocks writes the blocks from … from+count-1 (or fewer, the agent's time budget) to w and
// returns how many arrived. More than count blocks of SnapshotBlockSize is refused; the caller
// checks the exact length.
func (c *Client) SnapshotBlocks(id string, from, count int, w io.Writer) (int, error) {
	resp, err := c.Post("/wpsync/v1/db-snapshot/blocks", map[string]any{"id": id, "from": from, "count": count})
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	gotFrom, err1 := strconv.Atoi(resp.Header.Get("X-Wpsync-Block-From"))
	n, err2 := strconv.Atoi(resp.Header.Get("X-Wpsync-Blocks"))
	if err1 != nil || err2 != nil || gotFrom != from || n < 1 || n > count {
		return 0, fmt.Errorf("snapshot %s: bad block headers", id)
	}
	body := &boundedReader{r: resp.Body, left: int64(n) * SnapshotBlockSize}
	if _, err := io.Copy(w, body); err != nil {
		return 0, fmt.Errorf("snapshot %s: %w", id, err)
	}
	return n, nil
}

// SnapshotDelete removes job and file on the site; an unknown job is no error.
func (c *Client) SnapshotDelete(id string) error {
	var res struct {
		Deleted bool `json:"deleted"`
	}
	return c.PostJSON("/wpsync/v1/db-snapshot/delete", map[string]any{"id": id}, &res)
}
