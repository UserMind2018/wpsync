package push

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"regexp"
	"slices"
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// PluginsUnit is how the push log names the plugin state of a push. It is no unit of a request.
const PluginsUnit = "plugins"

var (
	// ErrPluginSwitch: --activate or --deactivate names something a push cannot switch, or a unit
	// to activate is not there to be pushed (exit code usage).
	ErrPluginSwitch = errors.New("ungültiger Plugin-Schalter")
	// ErrAgentNoPlugins: the agent cannot switch plugins with a push (agent < 0.9.0).
	ErrAgentNoPlugins = errors.New("der Agent auf der Site kann mit einem Push noch keine Plugins schalten")
)

// Limits the agent has too (PushPlugins::MAX_UNITS, HEAD_BYTES, MAX_HEADS).
const (
	maxSwitches = 20
	headBytes   = 8192
	maxHeads    = 5
	// maxHookScan bounds what is read of one PHP file to look for an activation hook.
	maxHookScan = 2 << 20
)

// headFileRe is a file name the agent accepts in plugin_heads.
var headFileRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]*\.php$`)

// switches is the plugin state a push asks for: unit names, normalised.
type switches struct{ Activate, Deactivate []string }

func (s switches) any() bool { return len(s.Activate)+len(s.Deactivate) > 0 }

// switches checks Activate and Deactivate before anything is scanned or sent (Spec Content-Push P4
// §4.1): only plugins in a folder, never the agent, at most 20 per switch, none twice, none in
// both; and nothing to activate together with --no-code.
func (o Options) switches() (switches, error) {
	var s switches
	seen := map[string]string{}
	for _, side := range []struct {
		flag string
		in   []string
		out  *[]string
	}{{"--activate", o.Activate, &s.Activate}, {"--deactivate", o.Deactivate, &s.Deactivate}} {
		if len(side.in) > maxSwitches {
			return switches{}, fmt.Errorf("%w: %s nennt mehr als %d Plugins", ErrPluginSwitch, side.flag, maxSwitches)
		}
		for _, raw := range side.in {
			name := unitName(raw)
			if !agentapi.PluginUnit(name) || !ValidUnit(name) {
				return switches{}, fmt.Errorf("%w: %s %q – schalten lässt sich nur ein Plugin in seinem Ordner, plugins/<slug>: kein Theme, nicht mu-plugins, nie plugins/wpsync-agent",
					ErrPluginSwitch, side.flag, raw)
			}
			key := strings.ToLower(name)
			switch prev, ok := seen[key]; {
			case ok && prev == side.flag:
				return switches{}, fmt.Errorf("%w: %s nennt %s doppelt", ErrPluginSwitch, side.flag, name)
			case ok:
				return switches{}, fmt.Errorf("%w: %s steht in --activate und in --deactivate", ErrPluginSwitch, name)
			}
			seen[key] = side.flag
			*side.out = append(*side.out, name)
		}
	}
	if len(s.Activate) > 0 && o.NoCode {
		return switches{}, fmt.Errorf("%w: --activate geht nicht mit --no-code – aktiviert wird nur ein Plugin, dessen Einheit derselbe Push überträgt", ErrPluginSwitch)
	}
	return s, nil
}

// unitBase collects the baseline entries of one unit, keyed relative to the unit – as scan does.
func unitBase(b *baseline.Baseline, unit string) map[string]baseline.FileStamp {
	out := map[string]baseline.FileStamp{}
	for path, stamp := range b.Files {
		if u, rel, ok := UnitOf(path); ok && u == unit {
			out[rel] = stamp
		}
	}
	return out
}

// withActivated adds every unit of --activate to the set, changed or not (A3): the agent activates
// only code the same push uploaded and checked. units are the units picked so far, all the changed
// units of the scan, links the units that are symlinks. A unit that is missing locally, empty or a
// symlink is a wrong call.
func withActivated(docroot string, base *baseline.Baseline, units, all []Unit, activate, links []string) ([]Unit, error) {
	if len(activate) == 0 {
		return units, nil
	}
	for _, name := range activate {
		if slices.Contains(links, name) {
			return nil, fmt.Errorf("%w: --activate %s – %s %w", ErrPluginSwitch, name, showDir(docroot, "wp-content/"+name), ErrSymlink)
		}
		if slices.ContainsFunc(units, func(u Unit) bool { return u.Path == name }) {
			continue
		}
		if i := slices.IndexFunc(all, func(u Unit) bool { return u.Path == name }); i >= 0 {
			units = append(units, all[i]) // changed, only not named
			continue
		}
		if _, ok, err := realDir(docroot, "wp-content/"+name); err != nil {
			return nil, err
		} else if !ok {
			return nil, fmt.Errorf("%w: --activate %s – der Ordner %s fehlt lokal; aktiviert wird nur ein Plugin, das derselbe Push überträgt",
				ErrPluginSwitch, name, showDir(docroot, "wp-content/"+name))
		}
		u, err := scanUnit(docroot, name, unitBase(base, name))
		if err != nil {
			return nil, err
		}
		if len(u.Files) == 0 {
			return nil, fmt.Errorf("%w: --activate %s – der Ordner enthält keine pushbare Datei", ErrPluginSwitch, name)
		}
		sort.Strings(u.Changed)
		units = append(units, u)
	}
	sort.Slice(units, func(i, j int) bool { return units[i].Path < units[j].Path })
	return units, nil
}

// pluginHeads reads, for a unit to activate, what the agent checks already in the dry run (A10):
// the first 8192 bytes of every PHP file directly in the folder that names a plugin header. The
// agent resolves the main file and reads the header itself – the CLI only delivers the bytes.
//
// heads is nil when the heads cannot be sent as the agent takes them (more than five, or a file
// name it would refuse): then none is sent and the agent checks the built file in the commit. An
// empty map means: no file with a header – the agent says no_plugin_file in the dry run.
// hook reports whether any PHP file of the unit registers an activation hook (a heuristic for the
// hint that a push does not run it).
func pluginHeads(docroot string, u *Unit) (heads map[string][]byte, hook bool, err error) {
	root, err := u.open(docroot)
	if err != nil {
		return nil, false, err
	}
	defer root.Close()
	names := make([]string, 0, len(u.Files))
	for rel := range u.Files {
		if strings.HasSuffix(rel, ".php") {
			names = append(names, rel)
		}
	}
	sort.Strings(names)
	heads = map[string][]byte{}
	sendable := true
	for _, rel := range names {
		file, err := openFile(root, u.Path, rel, u.Files[rel])
		if err != nil {
			return nil, false, err
		}
		data, err := io.ReadAll(io.LimitReader(file, maxHookScan))
		file.Close()
		if err != nil {
			return nil, false, err
		}
		if bytes.Contains(data, []byte("register_activation_hook")) {
			hook = true
		}
		if strings.Contains(rel, "/") || strings.HasPrefix(rel, ".") {
			continue // only files directly in the folder can be the main file
		}
		head := data[:min(len(data), headBytes)]
		if !bytes.Contains(bytes.ToLower(head), []byte("plugin name:")) {
			continue
		}
		if !headFileRe.MatchString(rel) || len(heads) == maxHeads {
			sendable = false
			continue
		}
		heads[rel] = head
	}
	if !sendable {
		return nil, hook, nil
	}
	return heads, hook, nil
}
