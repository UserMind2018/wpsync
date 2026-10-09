package pull

import (
	"errors"
	"fmt"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

// PhaseContent is reported through Options.Progress while manifest and baseline are built.
const PhaseContent = "content"

var (
	// ErrAgentNoContent: the agent has no /content/manifest (agent < 0.7.0).
	ErrAgentNoContent = errors.New("der Agent auf der Site kennt noch kein Inhalts-Manifest")
	// ErrContentScope: the pull profile leaves out one of the seven content tables or pulls it
	// without data – then there is no baseline for a content push (exit code usage).
	ErrContentScope = errors.New("--content braucht alle Inhaltstabellen mit Daten")
)

// contentNames are the seven content tables without prefix, in the order of the manifest.
var contentNames = []string{"posts", "postmeta", "terms", "term_taxonomy", "term_relationships", "termmeta", "options"}

// contentTables returns the seven content tables with prefix. Each must be in the delta with data
// (a checksum): a table the profile skips or pulls as structure has no rows to compare.
func contentTables(tables []agentapi.Table, prefix string) ([]string, error) {
	have := map[string]bool{}
	for _, t := range tables {
		if t.Checksum != nil {
			have[t.Name] = true
		}
	}
	var out, missing []string
	for _, n := range contentNames {
		if !have[prefix+n] {
			missing = append(missing, prefix+n)
		}
		out = append(out, prefix+n)
	}
	if len(missing) > 0 {
		return nil, fmt.Errorf("%w – im Profil fehlen oder ohne Daten: %s (ändern mit wpsync scan)", ErrContentScope, strings.Join(missing, ", "))
	}
	return out, nil
}

// withContent decides what a pull with --content reloads (Plan B1): as soon as one content table
// is to be reloaded or the site folder holds no fresh content state, all seven are – the baseline
// must be the state right after the pull, for every table. refresh says whether manifest, baseline
// and unfaithful list have to be rebuilt.
func withContent(changed, all []agentapi.Table, content []string, fresh bool) (reload []agentapi.Table, refresh bool) {
	isContent := map[string]bool{}
	for _, n := range content {
		isContent[n] = true
	}
	queued := map[string]bool{}
	refresh = !fresh
	for _, t := range changed {
		queued[t.Name] = true
		if isContent[t.Name] {
			refresh = true
		}
	}
	reload = append(reload, changed...)
	if !refresh {
		return reload, false
	}
	for _, t := range all {
		if isContent[t.Name] && !queued[t.Name] {
			reload = append(reload, t)
		}
	}
	return reload, true
}

// reloadsContent reports whether one of the tables to reload is a content table. A pull without
// --content then drops the content state: its baseline would no longer be the working copy (Plan B11).
func reloadsContent(tables []agentapi.Table, prefix string) bool {
	isContent := map[string]bool{}
	for _, n := range contentNames {
		isContent[prefix+n] = true
	}
	for _, t := range tables {
		if isContent[t.Name] {
			return true
		}
	}
	return false
}
