// Package pull orchestrates a pull from the agent into a local DDEV project.
package pull

import (
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/profile"
)

// DiffFiles returns files that are new or changed since the baseline and baseline files
// that disappeared. Files unknown to the baseline but already present locally (same size
// and mtime) are skipped – that resumes an aborted pull (Spike B20, AC-17). present may be nil.
func DiffFiles(files []agentapi.File, b *baseline.Baseline, present func(agentapi.File) bool) (changed []agentapi.File, deleted []string) {
	current := make(map[string]struct{}, len(files))
	for _, f := range files {
		current[f.Path] = struct{}{}
		if old, ok := b.Files[f.Path]; ok {
			if old.Size != f.Size || old.MTime != f.MTime {
				changed = append(changed, f)
			}
			continue
		}
		if present == nil || !present(f) {
			changed = append(changed, f)
		}
	}
	for path := range b.Files {
		if _, ok := current[path]; !ok {
			deleted = append(deleted, path)
		}
	}
	sort.Strings(deleted)
	return changed, deleted
}

// ChangedTables returns tables that must be (re)loaded: new ones, ones pulled in another mode
// (the profile changed) and ones with a different checksum. Structure-only tables reload only
// when their mode changes.
func ChangedTables(tables []agentapi.Table, b *baseline.Baseline) []agentapi.Table {
	var out []agentapi.Table
	for _, t := range tables {
		mode := t.Mode
		if mode == "" {
			mode = profile.ModeFull
		}
		switch {
		case b.Mode(t.Name) != mode:
			out = append(out, t)
		case mode == profile.ModeStructure:
		case t.Checksum == nil || b.Tables[t.Name] != *t.Checksum:
			out = append(out, t)
		}
	}
	return out
}

// TableKey is the mode a table is pulled with, as the baseline remembers it: full, structure,
// or filtered:<post types> for the tables the post-type filter touches (Spec 5.2).
func TableKey(mode string, s agentapi.Scope, prefix, table string) string {
	if mode != profile.ModeFull || len(s.ExcludePostTypes) == 0 {
		return mode
	}
	switch strings.TrimPrefix(table, prefix) {
	case "posts", "postmeta", "term_relationships", "comments":
		return "filtered:" + strings.Join(s.ExcludePostTypes, ",")
	}
	return mode
}
