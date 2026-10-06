package agentapi

import (
	"errors"
	"fmt"
	"net/url"
	"strings"
	"unicode"
)

// Status of the staging copy, as /staging/step, /staging/status and env.staging report it.
// StagingDeleted exists only in the answer of a step: the copy is gone, there is no record left.
const (
	StagingCreating   = "creating"
	StagingRefreshing = "refreshing"
	StagingDeleting   = "deleting"
	StagingReady      = "ready"
	StagingLocked     = "locked"
	StagingFailed     = "failed"
	StagingDeleted    = "deleted"
)

// Reason a staging job stopped (StagingStep.ErrorCode).
const (
	StagingErrGuard       = "guard"
	StagingErrDiskFull    = "disk_full"
	StagingErrUnsupported = "staging_unsupported"
	StagingErrFailed      = "failed"
)

// ErrForeignURL: the agent named an address that is not on the paired site. The CLI requests
// probe URLs and the login link itself; a manipulated agent must not point it anywhere else.
var ErrForeignURL = errors.New("der Agent nennt eine Adresse ausserhalb der gekoppelten Site")

// StagingSummary is the staging copy as /ping and /delta report it (env.staging).
type StagingSummary struct {
	Status   string `json:"status"`
	URL      string `json:"url"`
	LastUsed int64  `json:"last_used"`
}

// StagingNeed is the space a staging copy takes. CodeBytes and DiskFree are nil when the agent
// could not find out (Spec 2b 5.7).
type StagingNeed struct {
	CodeBytes *int64   `json:"code_bytes"`
	DBBytes   int64    `json:"db_bytes"`
	DiskFree  *int64   `json:"disk_free"`
	Warnings  []string `json:"warnings"`
}

// StagingProbe are the URLs the CLI checks from the outside before the copy starts (S5, V6).
type StagingProbe struct {
	DenyURL    string `json:"deny_url"`
	RewriteURL string `json:"rewrite_url"`
	FilesURL   string `json:"files_url"`
	Token      string `json:"token"`
}

// StagingBeginRequest starts create, refresh or delete; with Dry it only measures (V1).
type StagingBeginRequest struct {
	Op    string `json:"op"`
	Dry   bool   `json:"dry,omitempty"`
	Code  bool   `json:"code,omitempty"`
	Scope *Scope `json:"scope,omitempty"`
}

// StagingBegin answers /staging/begin. Need is nil for delete, Probe is set only for a create
// that really starts, URL is empty for a dry create.
type StagingBegin struct {
	Need  *StagingNeed  `json:"need"`
	URL   string        `json:"url"`
	Probe *StagingProbe `json:"probe"`
}

// StagingResult is what a finished create or refresh reports. Replaced counts the changed rows
// per staging table.
type StagingResult struct {
	URL           string         `json:"url"`
	Prefix        string         `json:"prefix"`
	Anonymized    bool           `json:"anonymized"`
	Replaced      map[string]int `json:"replaced"`
	SkippedValues int            `json:"skipped_values"`
	Files         int            `json:"files"`
}

// StagingStep is the state of the staging job after one step. A job that fails is an answer with
// Status failed, Error and ErrorCode, not an APIError. Result is set only with Status ready.
type StagingStep struct {
	Status    string         `json:"status"`
	Phase     string         `json:"phase"`
	Done      int            `json:"done"`
	Total     int            `json:"total"`
	Error     string         `json:"error"`
	ErrorCode string         `json:"error_code"`
	Result    *StagingResult `json:"result"`
}

// Finished: no job is left to step; Status says how it ended.
func (s StagingStep) Finished() bool { return s.Phase == "" }

// StagingStatus answers /staging/status; without a copy only Exists is set.
type StagingStatus struct {
	Exists     bool         `json:"exists"`
	Status     string       `json:"status,omitempty"`
	URL        string       `json:"url,omitempty"`
	Prefix     string       `json:"prefix,omitempty"`
	Created    int64        `json:"created,omitempty"`
	CopiedAt   int64        `json:"copied_at,omitempty"`
	LastUsed   int64        `json:"last_used,omitempty"`
	Anonymized bool         `json:"anonymized"`
	DBBytes    int64        `json:"db_bytes,omitempty"`
	Job        *StagingStep `json:"job,omitempty"`
	Error      string       `json:"error,omitempty"`
	Pushes     []PushRecord `json:"pushes,omitempty"`
}

// StagingLogin is a one-time link: access cookie plus login as the staging admin (T1). URL
// carries the token; it is shown to the user or requested, never logged – String and GoString
// keep it out of %v.
type StagingLogin struct {
	URL     string `json:"url"`
	Expires int64  `json:"expires"`
}

func (l StagingLogin) String() string {
	return fmt.Sprintf("StagingLogin{URL: %s?…, Expires: %d}", l.Redacted(), l.Expires)
}

func (l StagingLogin) GoString() string { return l.String() }

// Redacted is the link without its token, for messages and logs; empty if it is not a URL.
func (l StagingLogin) Redacted() string {
	u, err := url.Parse(l.URL)
	if err != nil {
		return ""
	}
	u.User, u.RawQuery, u.ForceQuery, u.Fragment, u.RawFragment = nil, "", false, "", ""
	return u.String()
}

// StagingBegin checks and, unless req.Dry, starts a staging job. With ErrForeignURL a create has
// already started on the server; StagingStep("fail") takes it down again.
func (c *Client) StagingBegin(req StagingBeginRequest) (*StagingBegin, error) {
	var res StagingBegin
	if err := c.PostJSON("/wpsync/v1/staging/begin", req, &res); err != nil {
		return nil, err
	}
	if p := res.Probe; p != nil {
		for _, u := range []string{p.DenyURL, p.RewriteURL, p.FilesURL} {
			if !SameOrigin(c.BaseURL, u) {
				return nil, fmt.Errorf("%w: %s", ErrForeignURL, Printable(u))
			}
		}
	}
	return &res, nil
}

// StagingStep works on the job; the first step of create carries the probe verdict ("ok", "fail").
func (c *Client) StagingStep(probe string) (*StagingStep, error) {
	body := map[string]any{}
	if probe != "" {
		body["probe"] = probe
	}
	var res StagingStep
	if err := c.PostJSON("/wpsync/v1/staging/step", body, &res); err != nil {
		return nil, err
	}
	return &res, nil
}

// StagingStatus returns the staging record of the site.
func (c *Client) StagingStatus() (*StagingStatus, error) {
	var res StagingStatus
	if err := c.PostJSON("/wpsync/v1/staging/status", map[string]any{}, &res); err != nil {
		return nil, err
	}
	return &res, nil
}

// StagingLogin returns a one-time login link; it also lifts the lock after the expiry. The link
// is on the paired site or the call fails – without naming the link.
func (c *Client) StagingLogin() (*StagingLogin, error) {
	var res StagingLogin
	if err := c.PostJSON("/wpsync/v1/staging/login", map[string]any{}, &res); err != nil {
		return nil, err
	}
	if !SameOrigin(c.BaseURL, res.URL) {
		return nil, fmt.Errorf("%w (Login-Link)", ErrForeignURL)
	}
	return &res, nil
}

// SameOrigin reports whether rawURL has the scheme, host and port of siteURL – http or https
// only, host without regard to case or a trailing dot, the default port written out or not.
// Links from the agent are only followed on the paired site, so everything a browser or another
// parser could read differently fails: userinfo, backslashes, control characters, whitespace.
// http passes only when the site itself was paired over http.
func SameOrigin(siteURL, rawURL string) bool {
	site, ok1 := origin(siteURL)
	u, ok2 := origin(rawURL)
	return ok1 && ok2 && site == u
}

// origin is scheme://host:port in one spelling.
func origin(raw string) (string, bool) {
	if strings.ContainsFunc(raw, func(r rune) bool { return r == '\\' || Unsafe(r) || unicode.IsSpace(r) }) {
		return "", false
	}
	u, err := url.Parse(raw)
	if err != nil || u.Opaque != "" || u.User != nil || (u.Scheme != "http" && u.Scheme != "https") {
		return "", false
	}
	host := strings.ToLower(strings.TrimSuffix(u.Hostname(), "."))
	if host == "" {
		return "", false
	}
	port := u.Port()
	if port == "" {
		port = map[string]string{"http": "80", "https": "443"}[u.Scheme]
	}
	return u.Scheme + "://" + host + ":" + port, true
}
