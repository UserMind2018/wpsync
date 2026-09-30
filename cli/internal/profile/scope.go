package profile

import (
	"slices"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
)

// Scope is what the agent is asked to deliver for this profile.
func (p *Profile) Scope(sheet *agentapi.Infosheet) agentapi.Scope {
	s := agentapi.Scope{
		ExcludePostTypes: p.ExcludedPostTypes(sheet.PostTypes),
		ExcludePlugins:   p.ExcludedPlugins(sheet.Plugins),
		ExcludeThemes:    p.ExcludedThemes(sheet.Themes),
		UploadsSince:     p.Uploads.Since,
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

// InScope mirrors the agent's Scope::excludesPath for a path relative to ABSPATH. Files outside
// the scope are neither downloaded nor deleted locally.
func InScope(s agentapi.Scope, path string) bool {
	rel, ok := strings.CutPrefix(path, "wp-content/")
	if !ok {
		return true
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
