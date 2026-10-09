package agentapi

import (
	"regexp"
)

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
// window is needed – apart from the rescue stub, when RescueStub asks for it.
type PushBeginRequest struct {
	Target string     `json:"target"`
	Force  bool       `json:"force"`
	Dry    bool       `json:"dry"`
	Units  []PushUnit `json:"units"`
	// RescueStub asks agent 0.5.1 for rescue.php through a stub in the webroot (Spec Stufe 2, 12).
	// An older agent ignores it and names rescue.php in its plugin folder.
	RescueStub bool `json:"rescue_stub,omitempty"`
	// Content names a package staged before through /content/stage (agent 0.7.0, Spec Content-Push
	// §7). With it Units may be empty: a push of content alone.
	Content *PushContentRef `json:"content,omitempty"`
	// Activate and Deactivate name plugins to switch in the same set, as units "plugins/<slug>"
	// (agent 0.9.0, Spec Content-Push P4 §4.2). A unit to activate must be part of Units; one to
	// deactivate need not. PluginHeads carries, per unit to activate, the first 8192 bytes of every
	// PHP file directly in its folder that names a plugin header: the agent checks them against the
	// target already in the dry run. They are a claim – in the commit the built file counts.
	Activate    []string                     `json:"activate,omitempty"`
	Deactivate  []string                     `json:"deactivate,omitempty"`
	PluginHeads map[string]map[string][]byte `json:"plugin_heads,omitempty"`
}

// PushUnitPlan is the agent's view of one unit.
type PushUnitPlan struct {
	Path      string   `json:"path"`
	Exists    bool     `json:"exists"`
	Version   string   `json:"version"`
	Conflicts []string `json:"conflicts"` // changed on the server since the client's baseline
	Need      []string `json:"need"`      // files the server does not have in this version
	// Same: unit uploads only (agent 0.6.0) – files the target already has with this content.
	// For uploads, Conflicts are files with other content at the same path (Spec Content-Push §8.2).
	Same     []string `json:"same"`
	Writable bool     `json:"writable"`
}

// PushPending is a push that was swapped in but never confirmed.
type PushPending struct {
	PushID  string `json:"push_id"`
	Device  string `json:"device"`
	Created int64  `json:"created"`
}

// MinAgentRescueDB is the first agent whose rescue.php takes the content of a push back without
// WordPress (Spec Content-Push P3). No hard minimum: the CLI tells it by the field rescue.db.
const MinAgentRescueDB = "0.8.0"

// RescueDBState says whether rescue.php can take the content of this push back without WordPress
// (agent 0.8.0, Spec Content-Push P3 §5.3). Reason, when it cannot: no_crypto, driver,
// no_image_key, probe_failed, write_failed – or "unknown" for anything that is no such code.
type RescueDBState struct {
	OK     bool   `json:"ok"`
	Reason string `json:"reason,omitempty"`
}

// PushRescue locates the rollback script that works without WordPress.
type PushRescue struct {
	URL  string `json:"url"`
	Salt string `json:"salt"`
	// Hardening names active plugins that may block PHP below wp-content (agent 0.5.1).
	Hardening []string `json:"hardening"`
	// DB: only for a push with content, and only from agent 0.8.0; nil otherwise.
	DB *RescueDBState `json:"db,omitempty"`
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
	// Content: the agent's check of the package the request named; nil when it named none – or
	// when the agent does not know the content channel.
	Content *ContentPlan `json:"content"`
	// Plugins: the agent's plan for the plugin state the request named; nil when it named none –
	// or when the agent is older than 0.9.0 and ignored the fields.
	Plugins *PluginsPlan `json:"plugins"`
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
	// Extensions: on the unit "content", the project extensions of the package – only if it had any.
	Extensions *ContentExtensions `json:"extensions,omitempty"`
	// Via "rescue": rescue.php took the content of the push back without WordPress; PostActions are
	// the steps the agent caught up on once WordPress loaded again, Left what hung on inserted
	// objects without being of the push and stayed (agent 0.8.0, Spec Content-Push P3 §8.2, R15).
	Via         string       `json:"via,omitempty"`
	PostActions []PostAction `json:"post_actions,omitempty"`
	Left        []ContentKey `json:"left,omitempty"`
	LeftTotal   int          `json:"left_total,omitempty"`
	// Activated, Deactivated, Back: on the unit "plugins" (agent 0.9.0) the entries of active_plugins
	// the push added and removed, and what a rollback changed back.
	Activated   []string         `json:"activated,omitempty"`
	Deactivated []string         `json:"deactivated,omitempty"`
	Back        *RollbackPlugins `json:"back,omitempty"`
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
	// OpenedBy: the WordPress user who had opened the push window at begin (agent 0.6.0); nil without.
	OpenedBy *int64 `json:"opened_by,omitempty"`
}

// PushBegin checks a push and, unless req.Dry, creates it.
func (c *Client) PushBegin(req PushBeginRequest) (*PushBegin, error) {
	var res PushBegin
	if err := c.PostJSON("/wpsync/v1/push/begin", req, &res); err != nil {
		return nil, err
	}
	if res.Content != nil {
		res.Content.Clean()
	}
	if res.Plugins != nil {
		res.Plugins.Clean()
	}
	if db := res.Rescue.DB; db != nil {
		switch {
		case db.OK:
			db.Reason = ""
		case !stepRe.MatchString(db.Reason):
			db.Reason = "unknown"
		}
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
	res, err := c.PushCommitFull(pushID)
	if err != nil {
		return nil, err
	}
	return res.Stamps, nil
}

// PushConfirm marks a swapped push as healthy.
func (c *Client) PushConfirm(pushID string) error {
	_, _, err := c.PushConfirmState(pushID)
	return err
}

// PushConfirmState is PushConfirm and returns how the agent closed the push: "confirmed" – or
// "rolled_back" with the warning content_kept, when rescue.php had taken code and uploads back
// and only the content of the push still stands (agent 0.7.0, Spec Content-Push §7.6).
func (c *Client) PushConfirmState(pushID string) (status string, warnings []string, err error) {
	var res struct {
		Status   string   `json:"status"`
		Warnings []string `json:"warnings"`
	}
	if err := c.PostJSON("/wpsync/v1/push/confirm", map[string]any{"push_id": pushID}, &res); err != nil {
		return "", nil, err
	}
	for _, w := range res.Warnings {
		if warningRe.MatchString(w) && len(warnings) < 10 {
			warnings = append(warnings, w)
		}
	}
	if res.Status != "rolled_back" {
		res.Status = "confirmed"
	}
	return res.Status, warnings, nil
}

// RollbackNotes is what a rollback reports beyond its status (agent 0.6.0): warnings such as
// upload_changed_since_push and the uploads it left in place, relative to wp-content/uploads/.
type RollbackNotes struct {
	Warnings []string `json:"warnings"`
	Kept     []string `json:"kept"`
	// PostActions: the steps after taking content back (agent 0.7.0, Spec Content-Push §7.7).
	PostActions []PostAction `json:"post_actions"`
	// Content: what rescue.php says about the content of the push when asked with content=1
	// (agent 0.8.0, Spec Content-Push P3 §7.6); nil from the agent's own rollback, from an older
	// rescue.php and for a push without content.
	Content *RescueContent `json:"content,omitempty"`
	// Plugins: what the rollback changed in active_plugins (agent 0.9.0, Spec Content-Push P4 §4.4);
	// nil for a push without a plugin state and while the database part of the push still stands.
	Plugins *RollbackPlugins `json:"plugins,omitempty"`
	// PluginsNotRestored: with the warning plugins_not_restored the entries of the push that still
	// stand in active_plugins although code and uploads are back (A18).
	PluginsNotRestored *PluginsKept `json:"plugins_not_restored,omitempty"`
}

// RescueContent is the part "content" of an answer of rescue.php. State: rolled_back (rows were
// written back), nothing (every row stood in its state before the push already) or kept (the
// content still stands; Error says why). Cache: none, stale (a persistent object cache still
// holds the pushed state – action=cache flushes it) or flushed.
type RescueContent struct {
	State string              `json:"state"`
	Cache string              `json:"cache,omitempty"`
	Error *RescueContentError `json:"error,omitempty"`
	// Left: what hung on objects the push had inserted without being of the push; it stayed on
	// the site, orphaned (R15). At most 200 keys, LeftTotal counts all.
	Left      []ContentKey `json:"left,omitempty"`
	LeftTotal int          `json:"left_total,omitempty"`
}

// RescueContentError: why rescue.php left the content – changed_since_push (with Keys),
// before_image_invalid, engine_unsupported, content_failed (Unrestored: one row has to be checked
// by hand), rescue_db_unavailable or db_unreachable. Never a value, never a word of the database.
type RescueContentError struct {
	Code       string       `json:"code"`
	Keys       []ContentKey `json:"keys,omitempty"`
	Total      int          `json:"total,omitempty"`
	Unrestored bool         `json:"unrestored,omitempty"`
}

// clean keeps what looks like the agent's words; nil for a state this CLI does not know – the
// caller then assumes the content still stands.
func (c *RescueContent) clean() *RescueContent {
	if c == nil {
		return nil
	}
	out := &RescueContent{State: c.State}
	switch c.State {
	case "rolled_back", "nothing":
		if c.Cache == "none" || c.Cache == "stale" || c.Cache == "flushed" {
			out.Cache = c.Cache
		}
		out.Left = CleanKeys(c.Left)
		if out.LeftTotal = c.LeftTotal; out.LeftTotal < len(out.Left) || out.LeftTotal > 1<<20 {
			out.LeftTotal = len(out.Left)
		}
	case "kept":
		e := RescueContentError{Code: "content_failed"}
		if c.Error != nil {
			if stepRe.MatchString(c.Error.Code) {
				e.Code = c.Error.Code
			}
			e.Keys, e.Unrestored = CleanKeys(c.Error.Keys), c.Error.Unrestored
			if e.Total = c.Error.Total; e.Total < len(e.Keys) || e.Total > 1<<20 {
				e.Total = len(e.Keys)
			}
		}
		out.Error = &e
	default:
		return nil
	}
	return out
}

var warningRe = regexp.MustCompile(`^[a-z][a-z_]{0,39}$`)

// Clean keeps only warnings that look like the agent's codes and at most 100 kept paths: both
// come from the site. Empty lists stay nil.
func (n RollbackNotes) Clean() RollbackNotes {
	var out RollbackNotes
	for _, w := range n.Warnings {
		if warningRe.MatchString(w) {
			out.Warnings = append(out.Warnings, w)
		}
	}
	for _, k := range n.Kept {
		if len(out.Kept) == 100 {
			break
		}
		out.Kept = append(out.Kept, k)
	}
	out.PostActions = CleanActions(n.PostActions)
	out.Content = n.Content.clean()
	out.Plugins, out.PluginsNotRestored = n.Plugins.clean(), n.PluginsNotRestored.clean()
	return out
}

// PushRollbackNotes restores the snapshot through WordPress and returns the agent's notes.
func (c *Client) PushRollbackNotes(pushID string) (RollbackNotes, error) {
	var res RollbackNotes
	if err := c.PostJSON("/wpsync/v1/push/rollback", map[string]any{"push_id": pushID}, &res); err != nil {
		return RollbackNotes{}, err
	}
	return res.Clean(), nil
}

// PushRollback restores the snapshot through WordPress.
func (c *Client) PushRollback(pushID string) error {
	_, err := c.PushRollbackNotes(pushID)
	return err
}

// PushList returns the latest pushes, newest first.
func (c *Client) PushList() ([]PushRecord, error) {
	var res struct {
		Pushes []PushRecord `json:"pushes"`
	}
	if err := c.PostJSON("/wpsync/v1/push/list", map[string]any{}, &res); err != nil {
		return nil, err
	}
	for _, r := range res.Pushes {
		for i := range r.Units {
			u := &r.Units[i]
			if u.Extensions != nil {
				u.Extensions.Clean()
			}
			if u.Via != "rescue" {
				u.Via = ""
			}
			u.PostActions, u.Left = CleanActions(u.PostActions), CleanKeys(u.Left)
			if u.LeftTotal < len(u.Left) || u.LeftTotal > 1<<20 {
				u.LeftTotal = len(u.Left)
			}
			if u.Activated != nil {
				u.Activated = cleanEntries(u.Activated)
			}
			if u.Deactivated != nil {
				u.Deactivated = cleanEntries(u.Deactivated)
			}
			u.Back = u.Back.clean()
		}
	}
	return res.Pushes, nil
}
