package pull

import (
	"compress/gzip"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/safefs"
)

// SnapshotMode chooses how the tables of a pull are read on the site (Spec 0.11.0 E1).
type SnapshotMode string

const (
	SnapshotAuto    SnapshotMode = "auto"    // consistent snapshot when the agent can, else chunks
	SnapshotRequire SnapshotMode = "require" // snapshot or fail
	SnapshotOff     SnapshotMode = "off"     // chunks as before 0.11.0
)

// ParseSnapshotMode reads --snapshot; "" is auto.
func ParseSnapshotMode(s string) (SnapshotMode, error) {
	switch SnapshotMode(s) {
	case "", SnapshotAuto:
		return SnapshotAuto, nil
	case SnapshotRequire, SnapshotOff:
		return SnapshotMode(s), nil
	}
	return "", fmt.Errorf("--snapshot %q: erlaubt sind auto, require, off", s)
}

// ErrSnapshot: no consistent snapshot of the database (require), or the site sent one that is wrong.
var ErrSnapshot = errors.New("kein konsistenter Schnappschuss der Datenbank")

// SnapshotReport says how the tables of a pull were read; part of pull --json (Spec E7).
type SnapshotReport struct {
	Used bool `json:"used"`
	// Fallback: why chunks were used – off, agent_outdated, unsupported:<reason>, busy, failed:<code>, checksum.
	Fallback     string   `json:"fallback,omitempty"`
	Inconsistent []string `json:"inconsistent_tables,omitempty"`
	Seconds      float64  `json:"seconds,omitempty"`
}

const (
	snapshotPoll             = time.Second
	snapshotTimeout          = 30 * time.Minute
	snapshotBlocksPerRequest = 16
	snapshotRetries          = 3
	// snapshotPart is the downloaded gzip stream in the tables folder; no table file ends like this.
	snapshotPart = "snapshot.gz.part"
)

// SnapshotOptions for one snapshot attempt.
type SnapshotOptions struct {
	Mode         SnapshotMode
	AgentVersion string
	Limit        int
	Scope        agentapi.Scope
	Out          io.Writer
	Progress     func(DBProgress)
	Sleep        func(time.Duration)    // nil = time.Sleep
	Now          func() time.Time       // nil = time.Now
	NewID        func() (string, error) // nil = 16 random bytes as hex
}

func (o *SnapshotOptions) defaults() {
	if o.Mode == "" {
		o.Mode = SnapshotAuto
	}
	if o.Out == nil {
		o.Out = io.Discard
	}
	if o.Progress == nil {
		o.Progress = func(DBProgress) {}
	}
	if o.Sleep == nil {
		o.Sleep = time.Sleep
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.NewID == nil {
		o.NewID = randomSnapshotID
	}
}

func randomSnapshotID() (string, error) {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

// fallback ends an attempt without a snapshot: chunks in auto, an error in require.
func (o *SnapshotOptions) fallback(reason, msg string) (SnapshotReport, error) {
	if o.Mode == SnapshotRequire {
		return SnapshotReport{}, fmt.Errorf("%w: %s", ErrSnapshot, msg)
	}
	fmt.Fprintf(o.Out, "  ! %s – Tabellen werden in Häppchen geladen (nicht konsistent)\n", msg)
	return SnapshotReport{Fallback: reason}, nil
}

// snapshotTables loads tables as one consistent snapshot of the site into dir – the same files and
// markers as downloadTables. Used=false: the caller loads them in chunks; Fallback says why.
func snapshotTables(c *agentapi.Client, dir *os.Root, tables []agentapi.Table, o SnapshotOptions) (SnapshotReport, error) {
	o.defaults()
	if o.Mode == SnapshotOff {
		return SnapshotReport{Fallback: string(SnapshotOff)}, nil
	}
	if !agentapi.AtLeast(o.AgentVersion, agentapi.MinAgentSnapshot) {
		if o.Mode == SnapshotRequire {
			return SnapshotReport{}, &agentapi.OutdatedError{Installed: o.AgentVersion, Required: agentapi.MinAgentSnapshot, Err: ErrSnapshot}
		}
		return o.fallback("agent_outdated", fmt.Sprintf("Der Agent auf der Site (%s) kann keinen Schnappschuss, erst ab %s", agentapi.Printable(o.AgentVersion), agentapi.MinAgentSnapshot))
	}
	if err := checkTableFiles(tables); err != nil {
		return SnapshotReport{}, err
	}
	names := make([]string, len(tables))
	var bytesTotal int64
	for i, t := range tables {
		names[i] = t.Name
		bytesTotal += transferBytes(t)
	}
	id, err := o.NewID()
	if err != nil {
		return SnapshotReport{}, err
	}
	started := o.Now()
	st, err := c.SnapshotBegin(agentapi.SnapshotRequest{ID: id, Tables: names, Limit: o.Limit, Scope: o.Scope})
	if err != nil {
		var apiErr *agentapi.APIError
		if errors.As(err, &apiErr) {
			reason := agentapi.Printable(apiErr.Message)
			switch apiErr.Code {
			case "wpsync_snapshot_unsupported":
				return o.fallback("unsupported:"+reasonToken(apiErr.Message), "Schnappschuss auf der Site nicht möglich ("+reason+")")
			case "wpsync_snapshot_busy":
				return o.fallback("busy", "Auf der Site läuft gerade ein Schnappschuss oder ein Push")
			}
		}
		return SnapshotReport{}, err
	}
	for st.State == agentapi.SnapshotRunning {
		o.Progress(DBProgress{Done: min(st.TablesDone, len(tables)), Total: len(tables), Table: st.Table, BytesTotal: bytesTotal})
		if o.Now().Sub(started) > snapshotTimeout {
			_ = c.SnapshotDelete(id)
			return SnapshotReport{}, fmt.Errorf("%w: die Site ist nach %s nicht fertig", ErrSnapshot, snapshotTimeout)
		}
		o.Sleep(snapshotPoll)
		if st, err = c.SnapshotStatus(id); err != nil {
			return SnapshotReport{}, err
		}
	}
	if st.State != agentapi.SnapshotDone {
		_ = c.SnapshotDelete(id)
		return o.fallback("failed:"+reasonToken(st.Code), "Schnappschuss auf der Site gescheitert ("+agentapi.Printable(st.Code+": "+st.Error)+")")
	}
	if !plausibleSnapshot(st) {
		_ = c.SnapshotDelete(id)
		return SnapshotReport{}, fmt.Errorf("%w: unplausible Angaben der Site (%d Blöcke, %d Bytes)", ErrSnapshot, st.Blocks, st.Size)
	}
	if err := fetchSnapshot(c, dir, id, st, o, len(tables), bytesTotal); err != nil {
		_ = c.SnapshotDelete(id)
		if errors.Is(err, errSnapshotChecksum) {
			return o.fallback("checksum", "Prüfsumme des Schnappschusses stimmt nicht")
		}
		return SnapshotReport{}, err
	}
	if err := unpackSnapshot(dir, tables, st.BytesPlain); err != nil {
		_ = c.SnapshotDelete(id)
		return SnapshotReport{}, err
	}
	if err := c.SnapshotDelete(id); err != nil {
		fmt.Fprintln(o.Out, "  ! Schnappschuss-Datei auf der Site nicht gelöscht – der Agent entfernt sie spätestens nach einer Stunde")
	}
	inconsistent := make([]string, 0, len(st.Inconsistent))
	for _, n := range st.Inconsistent {
		inconsistent = append(inconsistent, agentapi.Printable(n))
	}
	if len(inconsistent) > 0 {
		fmt.Fprintf(o.Out, "  ! Ohne InnoDB, nicht im Zeitpunkt des Schnappschusses gelesen: %s\n", strings.Join(firstN(inconsistent, 10), ", "))
	}
	o.Progress(DBProgress{Done: len(tables), Total: len(tables), BytesDone: bytesTotal, BytesTotal: bytesTotal})
	return SnapshotReport{Used: true, Inconsistent: inconsistent, Seconds: o.Now().Sub(started).Seconds()}, nil
}

// reasonToken is a site-chosen string as a machine-readable token in the report: escaped and cut
// like Printable, without the quotes.
func reasonToken(s string) string {
	q := agentapi.Printable(s)
	q = strings.TrimPrefix(q, `"`)
	if strings.HasSuffix(q, `"…`) {
		return strings.TrimSuffix(q, `"…`) + "…"
	}
	return strings.TrimSuffix(q, `"`)
}

func plausibleSnapshot(st *agentapi.SnapshotStatus) bool {
	if st.BlockSize != agentapi.SnapshotBlockSize || st.Blocks < 1 || len(st.SHA256) != 64 || st.BytesPlain < 1 {
		return false
	}
	return st.Size > int64(st.Blocks-1)*st.BlockSize && st.Size <= int64(st.Blocks)*st.BlockSize
}

var errSnapshotChecksum = errors.New("snapshot checksum mismatch")

// fetchSnapshot downloads all blocks into snapshotPart and checks size and SHA-256. After a failed
// request it cuts the file back to the last complete block and continues there (Spec E3).
func fetchSnapshot(c *agentapi.Client, dir *os.Root, id string, st *agentapi.SnapshotStatus, o SnapshotOptions, tablesTotal int, bytesTotal int64) error {
	f, err := createFresh(dir, snapshotPart)
	if err != nil {
		return err
	}
	err = fetchBlocks(c, f, id, st, o, tablesTotal, bytesTotal)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err == nil {
		err = verifySnapshot(dir, st)
	}
	if err != nil {
		_ = dir.Remove(snapshotPart)
	}
	return err
}

func fetchBlocks(c *agentapi.Client, f *os.File, id string, st *agentapi.SnapshotStatus, o SnapshotOptions, tablesTotal int, bytesTotal int64) error {
	from, failures := 0, 0
	for from < st.Blocks {
		n, err := c.SnapshotBlocks(id, from, min(snapshotBlocksPerRequest, st.Blocks-from), f)
		var pos int64
		if err == nil {
			pos, err = f.Seek(0, io.SeekCurrent)
		}
		if err == nil {
			want := int64(from+n) * st.BlockSize
			if from+n == st.Blocks {
				want = st.Size
			}
			if pos != want {
				err = fmt.Errorf("snapshot: %d bytes after block %d, want %d", pos, from+n, want)
			}
		}
		if err != nil {
			if failures++; failures > snapshotRetries {
				return err
			}
			start := int64(from) * st.BlockSize
			if terr := f.Truncate(start); terr != nil {
				return terr
			}
			if _, serr := f.Seek(start, io.SeekStart); serr != nil {
				return serr
			}
			continue
		}
		failures = 0
		from += n
		if bytesTotal > 0 {
			done := min(bytesTotal*pos/st.Size, bytesTotal-(bytesTotal+99)/100)
			o.Progress(DBProgress{Done: tablesTotal, Total: tablesTotal, BytesDone: done, BytesTotal: bytesTotal})
		}
	}
	return nil
}

func verifySnapshot(dir *os.Root, st *agentapi.SnapshotStatus) error {
	f, err := safefs.Open(dir, snapshotPart)
	if err != nil {
		return err
	}
	defer f.Close()
	h := sha256.New()
	n, err := io.Copy(h, f)
	if err != nil {
		return err
	}
	if n != st.Size || hex.EncodeToString(h.Sum(nil)) != strings.ToLower(st.SHA256) {
		return errSnapshotChecksum
	}
	return nil
}

// unpackSnapshot splits the downloaded snapshot into the table files of the cache, with markers:
// exactly the tables asked for, each once, in consecutive frames, at most plainBytes (Spec E4).
func unpackSnapshot(dir *os.Root, tables []agentapi.Table, plainBytes int64) error {
	f, err := safefs.Open(dir, snapshotPart)
	if err != nil {
		return err
	}
	defer f.Close()
	zr, err := gzip.NewReader(f)
	if err != nil {
		return fmt.Errorf("%w: %v", ErrSnapshot, err)
	}
	defer zr.Close()
	u := &unpacker{dir: dir, want: map[string]agentapi.Table{}, done: map[string]bool{}}
	for _, t := range tables {
		u.want[t.Name] = t
	}
	_, err = agentapi.ReadTableFrames(&capReader{r: zr, left: plainBytes}, u.frame)
	if err == nil {
		err = u.finish()
	} else {
		u.abort()
	}
	if err != nil {
		if !errors.Is(err, ErrSnapshot) {
			err = fmt.Errorf("%w: %w", ErrSnapshot, err)
		}
		return err
	}
	for _, t := range tables {
		if !u.done[t.Name] {
			return fmt.Errorf("%w: Tabelle %s fehlt", ErrSnapshot, t.Name)
		}
	}
	return dir.Remove(snapshotPart)
}

type unpacker struct {
	dir  *os.Root
	want map[string]agentapi.Table
	done map[string]bool
	cur  *os.File
	t    agentapi.Table
}

func (u *unpacker) frame(name string, _ int, sql io.Reader) error {
	if u.cur == nil || name != u.t.Name {
		if err := u.finish(); err != nil {
			return err
		}
		t, ok := u.want[name]
		if !ok || u.done[name] {
			return fmt.Errorf("%w: unerwartete Tabelle %s", ErrSnapshot, agentapi.Printable(name))
		}
		part, err := tableFile(name, partSuffix)
		if err != nil {
			return err
		}
		f, err := createFresh(u.dir, part)
		if err != nil {
			return err
		}
		u.cur, u.t = f, t
	}
	_, err := io.Copy(u.cur, sql)
	return err
}

func (u *unpacker) finish() error {
	if u.cur == nil {
		return nil
	}
	f := u.cur
	u.cur = nil
	if err := f.Close(); err != nil {
		return err
	}
	part, err := tableFile(u.t.Name, partSuffix)
	if err != nil {
		return err
	}
	final, err := tableFile(u.t.Name, sqlSuffix)
	if err != nil {
		return err
	}
	if err := u.dir.Rename(part, final); err != nil {
		return err
	}
	u.done[u.t.Name] = true
	return writeMarker(u.dir, u.t)
}

func (u *unpacker) abort() {
	if u.cur == nil {
		return
	}
	u.cur.Close()
	u.cur = nil
	if part, err := tableFile(u.t.Name, partSuffix); err == nil {
		_ = u.dir.Remove(part)
	}
}

// capReader fails beyond left bytes: a gzip stream that unpacks to more than the site announced
// is refused instead of filling the disk.
type capReader struct {
	r    io.Reader
	left int64
}

func (c *capReader) Read(p []byte) (int, error) {
	if c.left <= 0 {
		var one [1]byte
		n, err := c.r.Read(one[:])
		if n > 0 {
			return 0, fmt.Errorf("%w: mehr Daten als angekündigt", ErrSnapshot)
		}
		if err == nil {
			err = io.ErrNoProgress
		}
		return 0, err
	}
	if int64(len(p)) > c.left {
		p = p[:c.left]
	}
	n, err := c.r.Read(p)
	c.left -= int64(n)
	return n, err
}
