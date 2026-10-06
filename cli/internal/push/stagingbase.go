package push

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
	"github.com/usermind/wpsync/internal/safefs"
)

// The baseline describes live and a push to staging never touches it (Spec 2b 6.2). The files a
// push leaves in the copy carry new stamps, though: checked against the baseline, the next push
// to staging would report each of them as changed on the server. So this machine remembers the
// stamps the agent returned for the last confirmed push to staging, per unit, in
// .wpsync/staging-base.json – next to the baseline, never in it, not versioned (the snapshot
// repo ignores everything in .wpsync but the baseline). A push to staging is checked against
// them; a push to live never reads them, and neither does the scan for local changes.

const (
	stagingBaseName = "staging-base.json"
	stagingBaseMax  = 64 << 20
)

// errStagingBase: the file is there but cannot be trusted. Whatever the reason, the live
// baseline decides and the file goes.
var errStagingBase = errors.New("gemerkte Staging-Stempel unbrauchbar")

// copyID names one staging copy as /staging/status describes it. The folder is random per
// creation; copied_at moves whenever the agent finishes copying from live (create, refresh).
type copyID struct {
	URL      string `json:"url"`
	Created  int64  `json:"created"`
	CopiedAt int64  `json:"copied_at"`
}

func (c copyID) valid() bool {
	return c.URL != "" && len(c.URL) <= 2048 && utf8.ValidString(c.URL) && c.Created > 0 && c.CopiedAt > 0
}

// stagingUnit holds every file of a unit in the copy as the agent listed it after the push –
// the same list PushManifest::conflicts compares with on the next push. Paths are relative to
// the unit. Before is what this machine knew of the unit before that push (nil: nothing, the
// live baseline counted); a rollback of the push puts it back.
type stagingUnit struct {
	PushID string                        `json:"push_id"`
	Files  map[string]baseline.FileStamp `json:"files"`
	Before *stagingUnit                  `json:"before,omitempty"`
}

// stagingBase is the content of .wpsync/staging-base.json.
type stagingBase struct {
	Copy  copyID                  `json:"copy"`
	Units map[string]*stagingUnit `json:"units"`
	// MAC seals the content with the pairing secret. The site folder is writable from the local
	// containers, the secret is not readable there: stamps written by anything but this CLI for
	// this site do not count.
	MAC string `json:"mac"`
}

// validRel accepts a file path as a baseline holds it: relative to the unit, no "..", not
// absolute. The paths only travel back to the agent as keys of the conflict check.
func validRel(rel string) bool {
	if rel == "" || !utf8.ValidString(rel) || strings.ContainsRune(rel, 0) {
		return false
	}
	for _, seg := range strings.Split(strings.ReplaceAll(rel, "\\", "/"), "/") {
		if seg == "" || seg == "." || seg == ".." {
			return false
		}
	}
	return true
}

func (u *stagingUnit) valid(nested bool) bool {
	if u == nil || !pushIDRe.MatchString(u.PushID) || u.Files == nil {
		return false
	}
	for rel, stamp := range u.Files {
		if !validRel(rel) || stamp.Size < 0 {
			return false
		}
	}
	// One step back is all a rollback can take: the agent rolls back only the newest push of a unit.
	return u.Before == nil || (!nested && u.Before.valid(true))
}

func (s *stagingBase) valid() bool {
	if !s.Copy.valid() || s.Units == nil {
		return false
	}
	for unit, u := range s.Units {
		if !ValidUnit(unit) || !u.valid(false) {
			return false
		}
	}
	return true
}

// unit returns the remembered stamps of a unit as the base of a push, nil if there are none.
func (s *stagingBase) unit(path string) map[string]agentapi.PushStamp {
	if s == nil || s.Units[path] == nil {
		return nil
	}
	base := map[string]agentapi.PushStamp{}
	for rel, stamp := range s.Units[path].Files {
		base[rel] = agentapi.PushStamp{Size: stamp.Size, MTime: stamp.MTime}
	}
	return base
}

// sealed returns a copy with the MAC over everything else. Maps marshal with sorted keys, so the
// same content always yields the same bytes.
func (s *stagingBase) sealed(secret, siteURL string) *stagingBase {
	out := &stagingBase{Copy: s.Copy, Units: s.Units}
	body, _ := json.Marshal(out)
	key := hmac.New(sha256.New, []byte(secret))
	key.Write([]byte("staging-base:" + siteURL))
	mac := hmac.New(sha256.New, key.Sum(nil))
	mac.Write(body)
	out.MAC = hex.EncodeToString(mac.Sum(nil))
	return out
}

// loadStagingBase reads the remembered stamps: nil without a file, errStagingBase for a file
// that is unreadable, damaged, sealed by someone else or holds paths no baseline could.
func loadStagingBase(siteDir, secret, siteURL string) (*stagingBase, error) {
	root, err := safefs.OpenDir(siteDir, ".wpsync")
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("%w: %v", errStagingBase, err)
	}
	defer root.Close()
	if _, err := root.Lstat(stagingBaseName); errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	file, err := safefs.Open(root, stagingBaseName) // never through a symlink
	if err != nil {
		return nil, fmt.Errorf("%w: %v", errStagingBase, err)
	}
	defer file.Close()
	data, err := io.ReadAll(io.LimitReader(file, stagingBaseMax+1))
	if err != nil || len(data) > stagingBaseMax {
		return nil, fmt.Errorf("%w: nicht lesbar oder zu gross", errStagingBase)
	}
	var s stagingBase
	if err := json.Unmarshal(data, &s); err != nil {
		return nil, fmt.Errorf("%w: kein gültiges JSON", errStagingBase)
	}
	if !s.valid() {
		return nil, fmt.Errorf("%w: unzulässiger Inhalt", errStagingBase)
	}
	if !hmac.Equal([]byte(s.MAC), []byte(s.sealed(secret, siteURL).MAC)) {
		return nil, fmt.Errorf("%w: nicht von wpsync für diese Site geschrieben", errStagingBase)
	}
	return &s, nil
}

// saveStagingBase writes the stamps atomically (temporary file, then rename) with the rights of
// the baseline, through a root on .wpsync that follows no symlink.
func saveStagingBase(siteDir, secret, siteURL string, s *stagingBase) error {
	if !s.valid() {
		return fmt.Errorf("%w: unzulässiger Inhalt", errStagingBase)
	}
	data, err := json.MarshalIndent(s.sealed(secret, siteURL), "", "  ")
	if err != nil {
		return err
	}
	root, err := safefs.OpenTree(siteDir, ".wpsync")
	if err != nil {
		return err
	}
	defer root.Close()
	return safefs.WriteFile(root, stagingBaseName, bytes.NewReader(data), int64(len(data)), time.Time{}, 0o644)
}

// dropStagingBase removes the file; the live baseline counts again for every unit.
func dropStagingBase(siteDir string) error {
	root, err := safefs.OpenDir(siteDir, ".wpsync")
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	defer root.Close()
	// Remove takes a symlink away without following it.
	if err := safefs.Remove(root, stagingBaseName); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	return nil
}

// stagingStamps asks the agent which copy there is and returns the stamps this machine remembers
// of exactly that copy. A file for another copy – created anew, or copied from live again – or
// one that cannot be trusted is removed; then, and without an answer about the copy, there are
// no stamps and the live baseline decides, as for a copy fresh from live.
func (o Options) stagingStamps(siteDir string) (*copyID, *stagingBase) {
	st, err := o.Client.StagingStatus()
	if err != nil || !st.Exists {
		return nil, nil // the push itself will say what is wrong with the copy
	}
	id := copyID{URL: st.URL, Created: st.Created, CopiedAt: st.CopiedAt}
	if !id.valid() {
		return nil, nil
	}
	s, err := loadStagingBase(siteDir, o.Secret, o.Site.URL)
	switch {
	case err != nil:
		fmt.Fprintf(o.Out, "  ! %v – verworfen, verglichen wird mit dem letzten Pull\n", err)
	case s == nil:
		return &id, nil
	case s.Copy != id:
		fmt.Fprintln(o.Out, "  Die Staging-Kopie wurde seit dem letzten Push neu angelegt oder aufgefrischt – verglichen wird mit dem letzten Pull.")
	default:
		return &id, s
	}
	if err := dropStagingBase(siteDir); err != nil {
		fmt.Fprintf(o.Out, "  ! %s liess sich nicht entfernen (%v) – benutzt wird die Datei nicht\n", stagingBaseName, err)
	}
	return &id, nil
}

// rememberStaging stores the stamps of a confirmed push to staging: for the pushed units what
// the agent listed after the swap, for all others what was known before. A unit the agent sent
// no usable stamps for is forgotten, and without a known copy everything is.
func (o Options) rememberStaging(siteDir string, id *copyID, known *stagingBase, pushID string, units []string, stamps map[string]map[string]agentapi.PushStamp) error {
	if id == nil {
		return dropStagingBase(siteDir)
	}
	s := &stagingBase{Copy: *id, Units: map[string]*stagingUnit{}}
	if known != nil && known.Copy == *id {
		for unit, u := range known.Units {
			s.Units[unit] = u
		}
	}
	for _, unit := range units {
		now := &stagingUnit{PushID: pushID, Files: map[string]baseline.FileStamp{}}
		if before := s.Units[unit]; before != nil {
			now.Before = &stagingUnit{PushID: before.PushID, Files: before.Files}
		}
		files, ok := stamps[unit]
		for rel, stamp := range files {
			now.Files[rel] = baseline.FileStamp{Size: stamp.Size, MTime: stamp.MTime}
		}
		if !ok || !now.valid(false) {
			fmt.Fprintf(o.Out, "  ! der Agent nennt für %s keine brauchbaren Stempel – der nächste Push nach Staging vergleicht mit dem letzten Pull\n", unit)
			delete(s.Units, unit)
			continue
		}
		s.Units[unit] = now
	}
	return saveStagingBase(siteDir, o.Secret, o.Site.URL, s)
}

// forgetStagingPush follows a rollback: the units that carry the stamps of that push get back
// what was known before it – the rollback renamed the snapshot into place, its files keep their
// stamps – or nothing, if the push was the first to staging. Stamps of other pushes stay: a push
// this machine holds no stamps of changed nothing here.
func (o Options) forgetStagingPush(siteDir, pushID string) error {
	s, err := loadStagingBase(siteDir, o.Secret, o.Site.URL)
	if err != nil {
		return dropStagingBase(siteDir)
	}
	if s == nil {
		return nil
	}
	changed := false
	for unit, u := range s.Units {
		if u.PushID != pushID {
			continue
		}
		changed = true
		if u.Before != nil {
			s.Units[unit] = u.Before
		} else {
			delete(s.Units, unit)
		}
	}
	if !changed {
		return nil
	}
	return saveStagingBase(siteDir, o.Secret, o.Site.URL, s)
}
