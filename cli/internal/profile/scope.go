package profile

import (
	"slices"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/pathmatch"
)

// Scope is what the agent is asked to deliver for this profile.
func (p *Profile) Scope(sheet *agentapi.Infosheet) agentapi.Scope {
	s := agentapi.Scope{
		ExcludePostTypes: p.ExcludedPostTypes(sheet.PostTypes),
		ExcludePlugins:   p.ExcludedPlugins(sheet.Plugins),
		ExcludeThemes:    p.ExcludedThemes(sheet.Themes),
		UploadsSince:     p.Uploads.Since,
	}
	if len(p.Paths.Exclude) > 0 {
		s.ExcludePaths = p.Paths.Exclude
	}
	for name, mode := range p.TableModes(sheet.Tables) {
		if mode != ModeFull {
			if s.Tables == nil {
				s.Tables = map[string]string{}
			}
			s.Tables[name] = mode
		}
	}
	return s
}

// Filter returns InScope for s with the exclude paths compiled once. An invalid stored list
// excludes nothing here; scan refuses such a list before it is stored.
func Filter(s agentapi.Scope) func(path string) bool {
	set, err := pathmatch.Compile(s.ExcludePaths)
	if err != nil {
		set = nil
	}
	return func(path string) bool { return inScope(s, set, path) }
}

// InScope mirrors the agent's Scope::excludesPath and Scope::excludesPattern for a path relative
// to ABSPATH. Files outside the scope are neither downloaded nor deleted locally.
func InScope(s agentapi.Scope, path string) bool { return Filter(s)(path) }

func inScope(s agentapi.Scope, set *pathmatch.Set, path string) bool {
	rel, ok := strings.CutPrefix(path, "wp-content/")
	if !ok {
		return true
	}
	if set.Match(rel, false) {
		return false
	}
	parts := strings.Split(rel, "/")
	if len(parts) < 2 {
		return true
	}
	top, name, isDir := parts[0], parts[1], len(parts) > 2
	switch top {
	case "plugins":
		if !isDir {
			slug, isPHP := strings.CutSuffix(name, ".php")
			return !isPHP || !slices.Contains(s.ExcludePlugins, slug)
		}
		return !slices.Contains(s.ExcludePlugins, name)
	case "themes":
		return !isDir || !slices.Contains(s.ExcludeThemes, name)
	case "uploads":
		return !isDir || s.UploadsSince == "" || !IsYear(name) || name >= s.UploadsSince
	}
	return true
}
