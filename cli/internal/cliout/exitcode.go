// Package cliout is the machine-readable side of wpsync (Spec Server-Modus §4): fixed exit codes
// and JSON on stdout. Human messages go to stderr in --json mode.
package cliout

import (
	"context"
	"errors"
	"syscall"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/content"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/pull"
	"github.com/usermind/wpsync/internal/push"
	"github.com/usermind/wpsync/internal/staging"
)

// Exit codes (Spec Server-Modus §4; 40–53: Spec 2b 6.4).
const (
	ExitOK               = 0
	ExitUnknown          = 1
	ExitUsage            = 2
	ExitAgentUnreachable = 10
	ExitAgentOutdated    = 11
	ExitPairRejected     = 12
	ExitAuthFailed       = 13
	ExitRateLimited      = 14
	ExitLocalEnv         = 20
	ExitDiskFull         = 21
	ExitPostSetupFailed  = 22
	ExitInterrupted      = 30

	ExitPushWindowClosed   = 40
	ExitPushConflict       = 41
	ExitPushPending        = 42
	ExitPushRolledBack     = 43
	ExitBusy               = 44
	ExitStagingMissing     = 50
	ExitStagingExists      = 51
	ExitStagingUnsupported = 52
	ExitStagingLocked      = 53
)

// Names of the exit codes, as they appear in the JSON error object.
var Names = map[int]string{
	ExitOK:               "ok",
	ExitUnknown:          "unknown",
	ExitUsage:            "usage",
	ExitAgentUnreachable: "agent_unreachable",
	ExitAgentOutdated:    "agent_outdated",
	ExitPairRejected:     "pair_rejected",
	ExitAuthFailed:       "auth_failed",
	ExitRateLimited:      "rate_limited",
	ExitLocalEnv:         "local_env",
	ExitDiskFull:         "disk_full",
	ExitPostSetupFailed:  "postsetup_failed",
	ExitInterrupted:      "interrupted",

	ExitPushWindowClosed:   "push_window_closed",
	ExitPushConflict:       "push_conflict",
	ExitPushPending:        "push_pending",
	ExitPushRolledBack:     "push_rolled_back",
	ExitBusy:               "busy",
	ExitStagingMissing:     "staging_missing",
	ExitStagingExists:      "staging_exists",
	ExitStagingUnsupported: "staging_unsupported",
	ExitStagingLocked:      "staging_locked",
}

// Failure is a classified error: the "error" object of a JSON result.
type Failure struct {
	Exit      int    `json:"-"`
	Code      string `json:"code"`
	Message   string `json:"message"`
	Installed string `json:"installed,omitempty"`
	Required  string `json:"required,omitempty"`
	// Reason names a case within Code for callers that must not parse Message ("site_locked",
	// and the staging and push cases without an exit code of their own, see reason).
	Reason string `json:"reason,omitempty"`
	// Path: with reason not_readable the file or folder, relative to the docroot.
	Path string `json:"path,omitempty"`
	// SkippedNew: with reason nothing_to_push the local units that are new and were not named
	// (Spec Content-Push §10, S7); left out when there are none.
	SkippedNew []string `json:"skipped_new,omitempty"`
	// Device and AdminURL: with exit 40 whose push window it is (as far as pair stored it) and
	// where an administrator opens it (Spec Container-Push C8).
	Device   string `json:"device,omitempty"`
	AdminURL string `json:"admin_url,omitempty"`
}

// WindowError adds to an exit 40 the device and the admin page of the push window. Message and
// exit code stay those of Err.
type WindowError struct {
	Err      error
	Device   string
	AdminURL string
}

func (e *WindowError) Error() string { return e.Err.Error() }
func (e *WindowError) Unwrap() error { return e.Err }

// UsageError: wrong call – unknown flag, missing argument, unsupported combination.
type UsageError struct{ Err error }

func (e *UsageError) Error() string { return e.Err.Error() }
func (e *UsageError) Unwrap() error { return e.Err }

// Usage marks err as a usage error; nil stays nil.
func Usage(err error) error {
	if err == nil {
		return nil
	}
	return &UsageError{Err: err}
}

type hinted struct {
	msg string
	err error
}

func (h *hinted) Error() string { return h.msg }
func (h *hinted) Unwrap() error { return h.err }

// Hint replaces the message of err with an actionable German one and keeps err for the exit code.
func Hint(err error, msg string) error { return &hinted{msg: msg, err: err} }

// Classify maps an error to its exit code. The order matters: an interruption or a full disk
// wins over the layer the error surfaced in.
func Classify(err error) Failure {
	if err == nil {
		return Failure{Exit: ExitOK, Code: Names[ExitOK]}
	}
	f := Failure{Exit: classify(err), Message: err.Error()}
	f.Code = Names[f.Exit]
	var outdated *agentapi.OutdatedError
	if errors.As(err, &outdated) {
		f.Installed, f.Required = outdated.Installed, outdated.Required
	}
	f.Reason = reason(err, f.Exit)
	var unreadable *push.UnreadableError
	if f.Reason == "not_readable" && errors.As(err, &unreadable) {
		f.Path = unreadable.Path
	}
	var skipped *push.SkippedNewError
	if f.Reason == "nothing_to_push" && errors.As(err, &skipped) {
		f.SkippedNew = append([]string{}, skipped.Units...)
	}
	var window *WindowError
	if f.Exit == ExitPushWindowClosed && errors.As(err, &window) {
		f.Device, f.AdminURL = window.Device, window.AdminURL
	}
	return f
}

// reason: the local site lock, and what stays unknown although a caller can tell it apart – a
// staging job that stopped, a copy in a status that does not allow the call, a request that
// reached the copy instead of the live site, an address of the agent outside the paired site,
// the push cases of Spec Container-Push C11 and P-O3, and what stops pull --content after the
// tables are loaded (manifest, export of the working copy, canonical form).
func reason(err error, exit int) string {
	if errors.Is(err, pull.ErrPullRunning) {
		return "site_locked"
	}
	if exit != ExitUnknown {
		return ""
	}
	var apiErr *agentapi.APIError
	code := ""
	if errors.As(err, &apiErr) {
		code = apiErr.Code
	}
	switch {
	case errors.Is(err, agentapi.ErrForeignURL):
		return "foreign_url"
	case errors.Is(err, staging.ErrFailed), code == "wpsync_staging_failed":
		return "staging_failed"
	case code == "wpsync_staging_state":
		return "staging_state"
	case code == "wpsync_staging_copy":
		return "staging_copy"
	case errors.Is(err, push.ErrNothing):
		return "nothing_to_push"
	case errors.Is(err, push.ErrNotWritable):
		return "not_writable"
	case errors.Is(err, push.ErrRescueUnreachable):
		return "rescue_unreachable"
	case errors.Is(err, push.ErrNotReadable):
		return "not_readable"
	case errors.Is(err, push.ErrChanged), errors.Is(err, push.ErrSymlink):
		return "local_changed"
	case errors.Is(err, push.ErrTargetMismatch):
		return "target_mismatch"
	case errors.Is(err, push.ErrUploadExists), code == "wpsync_upload_exists":
		return "upload_exists"
	case errors.Is(err, push.ErrUploadTypeBlocked), code == "wpsync_upload_type_blocked":
		return "upload_type_blocked"
	case errors.Is(err, agentapi.ErrManifest):
		return "manifest_incomplete"
	case errors.Is(err, content.ErrExport), errors.Is(err, content.ErrNoStream):
		return "content_export_failed"
	case errors.Is(err, content.ErrCanonVersion):
		return "canon_version"
	}
	return ""
}

func classify(err error) int {
	var usage *UsageError
	var outdated *agentapi.OutdatedError
	var postSetup *pull.PostSetupError
	var local *localenv.Error
	var apiErr *agentapi.APIError
	var pending *push.PendingError
	var rolled *push.RolledBackError
	switch {
	case errors.Is(err, pull.ErrInterrupted), errors.Is(err, context.Canceled):
		return ExitInterrupted
	case errors.As(err, &usage), errors.Is(err, pull.ErrNeedsConfirmation), errors.Is(err, pull.ErrPlainNeedsConfirmation),
		errors.Is(err, pull.ErrUploadsWithoutProxy), errors.Is(err, pull.ErrInvalidDocroot),
		errors.Is(err, pull.ErrContentScope),
		errors.Is(err, push.ErrNeedsYes), errors.Is(err, staging.ErrNeedsYes), errors.Is(err, push.ErrTarget):
		return ExitUsage
	case errors.Is(err, agentapi.ErrInvalidEnv), errors.Is(err, pull.ErrInvalidTableName),
		errors.Is(err, agentapi.ErrForeignURL):
		// Values of the source that wpsync refuses (prefix, URLs, PHP version, table names, an
		// address outside the paired site) are neither a local problem nor a call error, and no
		// retry helps: unknown, wherever they surface.
		return ExitUnknown
	case errors.Is(err, syscall.ENOSPC):
		return ExitDiskFull
	case errors.As(err, &outdated), errors.Is(err, push.ErrAgentNoStaging), errors.Is(err, push.ErrAgentNoUploads):
		return ExitAgentOutdated
	case errors.As(err, &postSetup):
		return ExitPostSetupFailed
	case errors.As(err, &local):
		// Includes the local site lock (reason site_locked): busy is the server's lock only.
		return ExitLocalEnv
	case errors.Is(err, push.ErrWindowClosed), errors.Is(err, push.ErrRollbackWindow), errors.Is(err, staging.ErrWindowClosed):
		return ExitPushWindowClosed
	case errors.Is(err, push.ErrConflict):
		return ExitPushConflict
	case errors.As(err, &pending):
		return ExitPushPending
	case errors.As(err, &rolled):
		return ExitPushRolledBack
	case errors.Is(err, staging.ErrBusy):
		return ExitBusy
	case errors.Is(err, staging.ErrMissing):
		return ExitStagingMissing
	case errors.Is(err, staging.ErrExists):
		return ExitStagingExists
	case errors.Is(err, staging.ErrUnsupported):
		return ExitStagingUnsupported
	case errors.Is(err, staging.ErrLocked):
		return ExitStagingLocked
	case errors.Is(err, agentapi.ErrSuspectedBan):
		return ExitRateLimited
	case errors.As(err, &apiErr):
		return classifyAPI(apiErr)
	case errors.Is(err, agentapi.ErrUnreachable):
		return ExitAgentUnreachable
	}
	return ExitUnknown
}

func classifyAPI(e *agentapi.APIError) int {
	switch {
	case e.Code == "wpsync_code":
		return ExitPairRejected
	case e.Code == "wpsync_unpaired", e.Code == "wpsync_auth":
		return ExitAuthFailed
	case e.Code == "rest_no_route":
		return ExitAgentOutdated
	case e.Code == "wpsync_push_window":
		return ExitPushWindowClosed
	case e.Code == "wpsync_push_conflict":
		return ExitPushConflict
	case e.Code == "wpsync_push_pending", e.Code == "wpsync_staging_pending":
		return ExitPushPending
	case e.Code == "wpsync_staging_missing":
		return ExitStagingMissing
	case e.Code == "wpsync_staging_exists":
		return ExitStagingExists
	case e.Code == "wpsync_staging_unsupported":
		return ExitStagingUnsupported
	case e.Code == "wpsync_staging_locked":
		return ExitStagingLocked
	case e.Status == 423:
		// wpsync_push_locked, wpsync_staging_busy: a push or a staging job runs on the server.
		return ExitBusy
	case e.Status == 507:
		// wpsync_push_space, wpsync_staging_space: the server is out of space.
		return ExitDiskFull
	case e.Status == 429:
		return ExitRateLimited
	case e.Status == 502, e.Status == 503, e.Status == 504:
		return ExitAgentUnreachable
	}
	return ExitUnknown
}
