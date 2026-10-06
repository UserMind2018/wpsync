// Package cliout is the machine-readable side of wpsync (Spec Server-Modus §4): fixed exit codes
// and JSON on stdout. Human messages go to stderr in --json mode.
package cliout

import (
	"context"
	"errors"
	"syscall"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/pull"
)

// Exit codes (Spec Server-Modus §4).
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
}

// Failure is a classified error: the "error" object of a JSON result.
type Failure struct {
	Exit      int    `json:"-"`
	Code      string `json:"code"`
	Message   string `json:"message"`
	Installed string `json:"installed,omitempty"`
	Required  string `json:"required,omitempty"`
}

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
	return f
}

func classify(err error) int {
	var usage *UsageError
	var outdated *agentapi.OutdatedError
	var postSetup *pull.PostSetupError
	var local *localenv.Error
	var apiErr *agentapi.APIError
	switch {
	case errors.Is(err, pull.ErrInterrupted), errors.Is(err, context.Canceled):
		return ExitInterrupted
	case errors.As(err, &usage), errors.Is(err, pull.ErrNeedsConfirmation), errors.Is(err, pull.ErrPlainNeedsConfirmation):
		return ExitUsage
	case errors.Is(err, syscall.ENOSPC):
		return ExitDiskFull
	case errors.As(err, &outdated):
		return ExitAgentOutdated
	case errors.As(err, &postSetup):
		return ExitPostSetupFailed
	case errors.As(err, &local):
		return ExitLocalEnv
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
	case e.Status == 429:
		return ExitRateLimited
	case e.Status == 502, e.Status == 503, e.Status == 504:
		return ExitAgentUnreachable
	}
	return ExitUnknown
}
