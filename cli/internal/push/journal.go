package push

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io/fs"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/safefs"
)

var (
	pushIDRe = regexp.MustCompile(`^p_[0-9]{8}_[a-f0-9]{12}$`)
	// saltRe: the agent draws 16 random bytes per push (Push.php).
	saltRe = regexp.MustCompile(`^[a-f0-9]{32}$`)
)

// ShowID returns a push ID from the server for display: as is if it has the agent's format,
// otherwise quoted by agentapi.Printable, so a hostile site cannot steer the terminal.
func ShowID(id string) string {
	if pushIDRe.MatchString(id) {
		return id
	}
	return agentapi.Printable(id)
}

// Journal remembers what this machine needs to undo a push: the baseline entries of the units
// before the push and how to reach rescue.php (U9). It lives in .wpsync/pushes/<id>.json and is
// not versioned.
type Journal struct {
	PushID    string    `json:"push_id"`
	Created   time.Time `json:"created"`
	RescueURL string    `json:"rescue_url"`
	Salt      string    `json:"salt"`
	// Units: unit → baseline entries before the push, keyed like the baseline (relative to ABSPATH).
	Units map[string]map[string]baseline.FileStamp `json:"units"`
	// Applied: the baseline carries the pushed state.
	Applied bool `json:"applied"`
	// Target is live or staging; empty (journals before CLI 0.4.0) means live. A push to staging
	// never touches the baseline (Spec 2b 6.2), so Applied stays false for it.
	Target string `json:"target,omitempty"`
}

// target is the target of the push, live for a journal that names none.
func (j *Journal) target() string {
	if j.Target == TargetStaging {
		return TargetStaging
	}
	return TargetLive
}

func knownTarget(target string) bool {
	return target == "" || target == TargetLive || target == TargetStaging
}

func unitPrefix(unit string) string { return "wp-content/" + unit + "/" }

// NewJournal snapshots the baseline entries of the given units.
func NewJournal(pushID, rescueURL, salt string, b *baseline.Baseline, units []string) *Journal {
	j := &Journal{PushID: pushID, Created: time.Now(), RescueURL: rescueURL, Salt: salt, Units: map[string]map[string]baseline.FileStamp{}}
	for _, unit := range units {
		j.Units[unit] = map[string]baseline.FileStamp{}
		for path, stamp := range b.Files {
			if strings.HasPrefix(path, unitPrefix(unit)) {
				j.Units[unit][path] = stamp
			}
		}
	}
	return j
}

// Apply replaces the baseline entries of the pushed units with the server's new stamps.
func Apply(b *baseline.Baseline, stamps map[string]map[string]agentapi.PushStamp) {
	for unit, files := range stamps {
		dropUnit(b, unit)
		for rel, s := range files {
			b.Files[unitPrefix(unit)+rel] = baseline.FileStamp{Size: s.Size, MTime: s.MTime}
		}
	}
}

// Revert puts the baseline entries of the journal's units back to the state before the push.
func Revert(b *baseline.Baseline, j *Journal) {
	for unit, files := range j.Units {
		dropUnit(b, unit)
		for path, stamp := range files {
			b.Files[path] = stamp
		}
	}
}

func dropUnit(b *baseline.Baseline, unit string) {
	for path := range b.Files {
		if strings.HasPrefix(path, unitPrefix(unit)) {
			delete(b.Files, path)
		}
	}
}

// journalRel is the journal folder below the site folder. On the Mac it lies in the DDEV mount:
// every access goes through a root on that folder and never follows a symlink (Nach-Review M-2).
var journalRel = filepath.Join(".wpsync", "pushes")

// SaveJournal writes the journal atomically.
func SaveJournal(siteDir string, j *Journal) error {
	if !pushIDRe.MatchString(j.PushID) {
		return fmt.Errorf("invalid push id %q", j.PushID)
	}
	if !saltRe.MatchString(j.Salt) {
		return fmt.Errorf("der Agent nennt einen ungültigen Rescue-Salt %s", agentapi.Printable(j.Salt))
	}
	if !knownTarget(j.Target) {
		return fmt.Errorf("invalid push target %s", agentapi.Printable(j.Target))
	}
	data, err := json.MarshalIndent(j, "", "  ")
	if err != nil {
		return err
	}
	root, err := safefs.OpenTree(siteDir, journalRel)
	if err != nil {
		return err
	}
	defer root.Close()
	if err := root.Chmod(".", 0o700); err != nil {
		return err
	}
	return safefs.WriteFile(root, j.PushID+".json", bytes.NewReader(data), int64(len(data)), time.Time{}, 0o600)
}

// LoadJournal reads the journal of a push made from this machine. The journal lies in the site
// folder, which code in the local containers can write: push ID and salt must have the agent's
// format, and the rescue URL is checked against the site configuration before use (Rollback).
func LoadJournal(siteDir, pushID string) (*Journal, error) {
	if !pushIDRe.MatchString(pushID) {
		return nil, fmt.Errorf("ungültige Push-ID %q", pushID)
	}
	data, err := readJournal(siteDir, pushID)
	if err != nil {
		return nil, fmt.Errorf("zu Push %s gibt es auf diesem Rechner kein Journal", pushID)
	}
	var j Journal
	if err := json.Unmarshal(data, &j); err != nil || j.PushID != pushID || !saltRe.MatchString(j.Salt) || !knownTarget(j.Target) {
		return nil, fmt.Errorf("das Journal zu Push %s ist beschädigt", pushID)
	}
	return &j, nil
}

func readJournal(siteDir, pushID string) ([]byte, error) {
	root, err := safefs.OpenDir(siteDir, journalRel)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	return safefs.ReadFile(root, pushID+".json")
}

// LatestJournal returns the id of the newest push made from this machine to the given target
// (live or staging), "" if there is none. Journals that cannot be read count for no target.
// Push ids start with the date, so the name order is the time order within a day's precision.
func LatestJournal(siteDir, target string) string {
	root, err := safefs.OpenDir(siteDir, journalRel)
	if err != nil {
		return ""
	}
	defer root.Close()
	entries, _ := fs.ReadDir(root.FS(), ".")
	var ids []string
	for _, e := range entries {
		if id, ok := strings.CutSuffix(e.Name(), ".json"); ok && pushIDRe.MatchString(id) && e.Type().IsRegular() {
			if j, err := LoadJournal(siteDir, id); err == nil && j.target() == target {
				ids = append(ids, id)
			}
		}
	}
	if len(ids) == 0 {
		return ""
	}
	sort.Slice(ids, func(i, j int) bool {
		a, _ := root.Lstat(ids[i] + ".json")
		b, _ := root.Lstat(ids[j] + ".json")
		if a == nil || b == nil || a.ModTime().Equal(b.ModTime()) {
			return ids[i] < ids[j]
		}
		return a.ModTime().Before(b.ModTime())
	})
	return ids[len(ids)-1]
}
