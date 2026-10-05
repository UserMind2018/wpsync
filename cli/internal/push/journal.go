package push

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

var pushIDRe = regexp.MustCompile(`^p_[0-9]{8}_[a-f0-9]{12}$`)

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

func journalDir(siteDir string) string { return filepath.Join(siteDir, ".wpsync", "pushes") }

// SaveJournal writes the journal atomically.
func SaveJournal(siteDir string, j *Journal) error {
	if !pushIDRe.MatchString(j.PushID) {
		return fmt.Errorf("invalid push id %q", j.PushID)
	}
	if err := os.MkdirAll(journalDir(siteDir), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(j, "", "  ")
	if err != nil {
		return err
	}
	p := filepath.Join(journalDir(siteDir), j.PushID+".json")
	if err := os.WriteFile(p+".tmp", data, 0o600); err != nil {
		return err
	}
	return os.Rename(p+".tmp", p)
}

// LoadJournal reads the journal of a push made from this machine.
func LoadJournal(siteDir, pushID string) (*Journal, error) {
	if !pushIDRe.MatchString(pushID) {
		return nil, fmt.Errorf("ungültige Push-ID %q", pushID)
	}
	data, err := os.ReadFile(filepath.Join(journalDir(siteDir), pushID+".json"))
	if err != nil {
		return nil, fmt.Errorf("zu Push %s gibt es auf diesem Rechner kein Journal", pushID)
	}
	var j Journal
	if err := json.Unmarshal(data, &j); err != nil {
		return nil, err
	}
	return &j, nil
}

// LatestJournal returns the id of the newest push made from this machine, "" if there is none.
// Push ids start with the date, so the name order is the time order within a day's precision.
func LatestJournal(siteDir string) string {
	entries, _ := os.ReadDir(journalDir(siteDir))
	var ids []string
	for _, e := range entries {
		if id, ok := strings.CutSuffix(e.Name(), ".json"); ok && pushIDRe.MatchString(id) {
			ids = append(ids, id)
		}
	}
	if len(ids) == 0 {
		return ""
	}
	sort.Slice(ids, func(i, j int) bool {
		a, _ := os.Stat(filepath.Join(journalDir(siteDir), ids[i]+".json"))
		b, _ := os.Stat(filepath.Join(journalDir(siteDir), ids[j]+".json"))
		if a == nil || b == nil || a.ModTime().Equal(b.ModTime()) {
			return ids[i] < ids[j]
		}
		return a.ModTime().Before(b.ModTime())
	})
	return ids[len(ids)-1]
}
