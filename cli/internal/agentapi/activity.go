package agentapi

import (
	"errors"
	"strings"
	"unicode"
)

// ActivityRoute is the night report of agent 0.12.0.
const ActivityRoute = "/wpsync/v1/activity"

// MinAgentActivity is the first agent that keeps the activity log.
const MinAgentActivity = "0.12.0"

// ActivityMaxEvents bounds one answer, as the agent does (ActivityLog::MAX_LIMIT).
const ActivityMaxEvents = 500

// activityText is the longest text the agent stores (VARCHAR(191)); activityListMax bounds the
// auto-update lists.
const (
	activityText    = 191
	activityListMax = 1000
)

var (
	activityKinds = map[string]bool{"updated": true, "downgraded": true, "installed": true, "deleted": true,
		"activated": true, "deactivated": true, "theme_switched": true, "update_failed": true, "translations_updated": true}
	activityTypes   = map[string]bool{"core": true, "plugin": true, "theme": true, "translation": true}
	activitySources = map[string]bool{"auto": true, "wpsync": true, "cli": true, "user": true, "other": true, "detected": true}
)

// ActivityUser is the WordPress user behind an event (source user).
type ActivityUser struct {
	ID    int64  `json:"id"`
	Login string `json:"login,omitempty"`
}

// ActivityEvent is one change of the installed software.
type ActivityEvent struct {
	At     int64         `json:"at"`
	Kind   string        `json:"kind"`
	Type   string        `json:"type"`
	Slug   string        `json:"slug"`
	Name   string        `json:"name"`
	From   *string       `json:"from"`
	To     *string       `json:"to"`
	Source string        `json:"source"`
	User   *ActivityUser `json:"user"`
	Detail *string       `json:"detail"`
}

// AutoUpdates names the plugins and themes with WordPress auto-updates switched on.
type AutoUpdates struct {
	Plugins []string `json:"plugins"`
	Themes  []string `json:"themes"`
}

// ActivityReport is the answer of /activity.
type ActivityReport struct {
	Since        int64           `json:"since"`
	Until        int64           `json:"until"`
	Checked      int64           `json:"checked"`
	LogSince     *int64          `json:"log_since"`
	AgentVersion string          `json:"agent_version"`
	Core         string          `json:"core"`
	Events       []ActivityEvent `json:"events"`
	Truncated    bool            `json:"truncated"`
	AutoUpdates  AutoUpdates     `json:"auto_updates"`
}

// Activity asks for the events between since and until (Unix seconds), at most limit of them.
// An agent before 0.12.0 has no such route: that is an OutdatedError (exit agent_outdated).
func (c *Client) Activity(since, until int64, limit int) (*ActivityReport, error) {
	var res ActivityReport
	err := c.PostJSON(ActivityRoute, map[string]any{"since": since, "until": until, "limit": limit}, &res)
	var apiErr *APIError
	if errors.As(err, &apiErr) && apiErr.Code == "rest_no_route" {
		return nil, &OutdatedError{Required: MinAgentActivity, Err: errors.New("der Agent führt noch kein Aktivitäts-Protokoll")}
	}
	if err != nil {
		return nil, err
	}
	res.Clean()
	return &res, nil
}

// Clean drops what the agent never sends and makes every text safe to print.
func (r *ActivityReport) Clean() {
	events := make([]ActivityEvent, 0, len(r.Events))
	for _, e := range r.Events {
		if e.At <= 0 || !activityKinds[e.Kind] || !activityTypes[e.Type] || !activitySources[e.Source] {
			continue
		}
		if len(events) == ActivityMaxEvents {
			r.Truncated = true
			break
		}
		e.Slug, e.Name = activityClean(e.Slug), activityClean(e.Name)
		e.From, e.To, e.Detail = activityCleanPtr(e.From), activityCleanPtr(e.To), activityCleanPtr(e.Detail)
		if e.User != nil {
			if e.User.ID <= 0 {
				e.User = nil
			} else {
				u := ActivityUser{ID: e.User.ID, Login: activityClean(e.User.Login)}
				e.User = &u
			}
		}
		events = append(events, e)
	}
	r.Events = events
	r.AgentVersion, r.Core = activityClean(r.AgentVersion), activityClean(r.Core)
	r.AutoUpdates.Plugins = activityList(r.AutoUpdates.Plugins)
	r.AutoUpdates.Themes = activityList(r.AutoUpdates.Themes)
}

// activityClean replaces control characters and line separators, drops invisible formatting
// characters (bidi overrides, zero-width), keeps valid UTF-8 and cuts at activityText bytes.
func activityClean(s string) string {
	s = strings.Map(func(r rune) rune {
		switch {
		case unicode.IsControl(r), unicode.Is(unicode.Zl, r), unicode.Is(unicode.Zp, r):
			return ' '
		case unicode.Is(unicode.Cf, r):
			return -1
		}
		return r
	}, strings.ToValidUTF8(s, ""))
	if len(s) > activityText {
		s = strings.ToValidUTF8(s[:activityText], "")
	}
	return s
}

func activityCleanPtr(s *string) *string {
	if s == nil {
		return nil
	}
	v := activityClean(*s)
	return &v
}

func activityList(in []string) []string {
	out := make([]string, 0, len(in))
	for _, s := range in {
		if len(out) == activityListMax {
			break
		}
		if s = activityClean(s); s != "" {
			out = append(out, s)
		}
	}
	return out
}
