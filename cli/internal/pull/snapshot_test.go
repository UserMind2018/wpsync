package pull

import (
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

func frame(name, sql string) string {
	return fmt.Sprintf("T %s\t0\t%d\n%s\n", name, len(sql), sql)
}

func gz(t *testing.T, s string) []byte {
	t.Helper()
	var b bytes.Buffer
	w := gzip.NewWriter(&b)
	if _, err := io.WriteString(w, s); err != nil {
		t.Fatal(err)
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	return b.Bytes()
}

// fakeSnapshot is an agent that serves one snapshot job.
type fakeSnapshot struct {
	t          *testing.T
	payload    []byte // gzip stream
	plain      int64  // bytes_plain; 0 = real length
	sha        string // "" = real checksum
	state      string // after the running polls: done or failed
	code       string
	running    int    // status answers with running before state
	beginCode  string // error code of begin
	cutRequest int    // index of the blocks request answered short; -1 none
	routes     []string
	blockReqs  int
	deleted    bool
}

func (f *fakeSnapshot) view(state string) map[string]any {
	sum := sha256.Sum256(f.payload)
	sha := hex.EncodeToString(sum[:])
	if f.sha != "" {
		sha = f.sha
	}
	bs := agentapi.SnapshotBlockSize
	blocks := int((int64(len(f.payload)) + bs - 1) / bs)
	return map[string]any{"id": "0123456789abcdef0123456789abcdef", "state": state, "tables_done": 1, "tables_total": 2,
		"blocks": blocks, "block_size": bs, "size": len(f.payload), "sha256": sha, "bytes_plain": f.plain,
		"rows": map[string]int{}, "inconsistent": []string{}, "code": f.code, "error": "boom", "seconds": 1}
}

func (f *fakeSnapshot) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	route := r.URL.Query().Get("rest_route")
	f.routes = append(f.routes, route)
	var body map[string]any
	_ = json.NewDecoder(r.Body).Decode(&body)
	switch route {
	case "/wpsync/v1/db-snapshot/begin":
		if f.beginCode != "" {
			w.WriteHeader(http.StatusUnprocessableEntity)
			json.NewEncoder(w).Encode(map[string]any{"code": f.beginCode, "message": "disabled", "data": map[string]any{"status": 422}})
			return
		}
		json.NewEncoder(w).Encode(f.view(agentapi.SnapshotRunning))
	case "/wpsync/v1/db-snapshot/status":
		if f.running > 0 {
			f.running--
			json.NewEncoder(w).Encode(f.view(agentapi.SnapshotRunning))
			return
		}
		json.NewEncoder(w).Encode(f.view(f.state))
	case "/wpsync/v1/db-snapshot/blocks":
		from, count := int(body["from"].(float64)), int(body["count"].(float64))
		bs := agentapi.SnapshotBlockSize
		start := int64(from) * bs
		end := min(int64(from+count)*bs, int64(len(f.payload)))
		n := int((end - start + bs - 1) / bs)
		w.Header().Set("X-Wpsync-Block-From", strconv.Itoa(from))
		w.Header().Set("X-Wpsync-Blocks", strconv.Itoa(n))
		data := f.payload[start:end]
		if f.blockReqs == f.cutRequest {
			data = data[:len(data)/2]
		}
		f.blockReqs++
		w.Write(data)
	case "/wpsync/v1/db-snapshot/delete":
		f.deleted = true
		json.NewEncoder(w).Encode(map[string]any{"deleted": true})
	default:
		f.t.Errorf("unexpected route %s", route)
		w.WriteHeader(http.StatusNotFound)
	}
}

func snapshotFixture(t *testing.T, plain string) (*fakeSnapshot, *os.Root, []agentapi.Table) {
	t.Helper()
	f := &fakeSnapshot{t: t, payload: gz(t, plain), plain: int64(len(plain)), state: agentapi.SnapshotDone, cutRequest: -1}
	dir, err := os.OpenRoot(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { dir.Close() })
	sum := "123"
	tables := []agentapi.Table{
		{Name: "wp_options", Mode: profile.ModeFull, Checksum: &sum, Bytes: 100},
		{Name: "wp_ssa", Mode: profile.ModeStructure},
	}
	return f, dir, tables
}

func runSnapshot(t *testing.T, f *fakeSnapshot, dir *os.Root, tables []agentapi.Table, mode SnapshotMode, version string) (SnapshotReport, string, error) {
	t.Helper()
	srv := httptest.NewServer(f)
	t.Cleanup(srv.Close)
	var out bytes.Buffer
	rep, err := snapshotTables(quickClient(srv.URL), dir, tables, SnapshotOptions{
		Mode: mode, AgentVersion: version, Limit: 2000, Out: &out, Sleep: func(time.Duration) {},
		NewID: func() (string, error) { return "0123456789abcdef0123456789abcdef", nil },
	})
	return rep, out.String(), err
}

const goodPlain = "T wp_options\t2\t10\nDROP;PART1\nT wp_options\t1\t5\nPART2\nT wp_ssa\t0\t7\nCREATE;\nE\n"

func readCache(t *testing.T, dir *os.Root, name string) string {
	t.Helper()
	f, err := dir.Open(name)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	b, _ := io.ReadAll(f)
	return string(b)
}

func TestSnapshotStoresTablesMarkersAndDeletes(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	f.running = 2
	rep, _, err := runSnapshot(t, f, dir, tables, SnapshotAuto, "0.11.0")
	if err != nil {
		t.Fatal(err)
	}
	if !rep.Used || rep.Fallback != "" {
		t.Fatalf("report %+v", rep)
	}
	if got := readCache(t, dir, "wp_options.sql"); got != "DROP;PART1PART2" {
		t.Errorf("wp_options.sql = %q", got)
	}
	if got := readCache(t, dir, "wp_ssa.sql"); got != "CREATE;" {
		t.Errorf("wp_ssa.sql = %q", got)
	}
	if !markerMatches(dir, tables[0]) || !markerMatches(dir, tables[1]) {
		t.Error("markers missing")
	}
	if _, err := dir.Stat(snapshotPart); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("%s left behind: %v", snapshotPart, err)
	}
	if !f.deleted {
		t.Error("snapshot not deleted on the site")
	}
}

func TestSnapshotOldAgentFallsBackWithoutRequest(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	rep, out, err := runSnapshot(t, f, dir, tables, SnapshotAuto, "0.10.0")
	if err != nil || rep.Used || rep.Fallback != "agent_outdated" {
		t.Fatalf("rep=%+v err=%v", rep, err)
	}
	if len(f.routes) != 0 {
		t.Fatalf("old agent got requests %v", f.routes)
	}
	if !strings.Contains(out, "0.10.0") || !strings.Contains(out, "Häppchen") {
		t.Errorf("out = %q", out)
	}
}

func TestSnapshotRequireWithOldAgentFails(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	_, _, err := runSnapshot(t, f, dir, tables, SnapshotRequire, "0.10.0")
	var outdated *agentapi.OutdatedError
	if !errors.As(err, &outdated) || !errors.Is(err, ErrSnapshot) {
		t.Fatalf("want OutdatedError wrapping ErrSnapshot, got %v", err)
	}
}

func TestSnapshotOffMakesNoRequest(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	rep, _, err := runSnapshot(t, f, dir, tables, SnapshotOff, "0.11.0")
	if err != nil || rep.Used || rep.Fallback != "off" || len(f.routes) != 0 {
		t.Fatalf("rep=%+v err=%v routes=%v", rep, err, f.routes)
	}
}

func TestSnapshotUnsupportedFallsBackOrFails(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	f.beginCode = "wpsync_snapshot_unsupported"
	rep, out, err := runSnapshot(t, f, dir, tables, SnapshotAuto, "0.11.0")
	if err != nil || rep.Fallback != "unsupported:disabled" || !strings.Contains(out, "disabled") {
		t.Fatalf("rep=%+v err=%v out=%q", rep, err, out)
	}
	if _, _, err := runSnapshot(t, f, dir, tables, SnapshotRequire, "0.11.0"); !errors.Is(err, ErrSnapshot) {
		t.Fatalf("require: want ErrSnapshot, got %v", err)
	}
}

func TestSnapshotFailedJobFallsBackAndDeletes(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	f.state, f.code = agentapi.SnapshotFailed, "timeout"
	rep, _, err := runSnapshot(t, f, dir, tables, SnapshotAuto, "0.11.0")
	if err != nil || rep.Fallback != "failed:timeout" || !f.deleted {
		t.Fatalf("rep=%+v err=%v deleted=%v", rep, err, f.deleted)
	}
}

func TestSnapshotChecksumMismatch(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	f.sha = strings.Repeat("0", 64)
	rep, _, err := runSnapshot(t, f, dir, tables, SnapshotAuto, "0.11.0")
	if err != nil || rep.Fallback != "checksum" {
		t.Fatalf("rep=%+v err=%v", rep, err)
	}
	if _, err := dir.Stat("wp_options.sql"); !errors.Is(err, os.ErrNotExist) {
		t.Error("table file written from a snapshot with a wrong checksum")
	}
	if _, _, err := runSnapshot(t, f, dir, tables, SnapshotRequire, "0.11.0"); !errors.Is(err, ErrSnapshot) {
		t.Fatalf("require: want ErrSnapshot, got %v", err)
	}
}

func TestSnapshotResumesAfterACutBlockResponse(t *testing.T) {
	old := agentapi.SnapshotBlockSize
	agentapi.SnapshotBlockSize = 16
	defer func() { agentapi.SnapshotBlockSize = old }()
	big := "T wp_options\t9\t400\n" + strings.Repeat("0123456789", 40) + "\nT wp_ssa\t0\t7\nCREATE;\nE\n"
	f, dir, tables := snapshotFixture(t, big)
	if len(f.payload) <= 32 {
		t.Fatalf("payload of %d bytes is less than three blocks", len(f.payload))
	}
	f.cutRequest = 0 // the first answer arrives half: cut back to block 0 and ask again
	rep, _, err := runSnapshot(t, f, dir, tables, SnapshotRequire, "0.11.0")
	if err != nil || !rep.Used {
		t.Fatalf("rep=%+v err=%v", rep, err)
	}
	if got := readCache(t, dir, "wp_options.sql"); got != strings.Repeat("0123456789", 40) {
		t.Errorf("wp_options.sql has %d bytes", len(got))
	}
	if f.blockReqs != 2 {
		t.Errorf("want one repeated block request, got %d requests", f.blockReqs)
	}
}

func TestSnapshotRefusesUnexpectedOrSplitTables(t *testing.T) {
	for name, plain := range map[string]string{
		"unexpected": frame("wp_options", "A") + frame("wp_secret", "S") + frame("wp_ssa", "C") + "E\n",
		"split":      frame("wp_options", "A") + frame("wp_ssa", "C") + frame("wp_options", "B") + "E\n",
		"missing":    frame("wp_options", "A") + "E\n",
	} {
		t.Run(name, func(t *testing.T) {
			f, dir, tables := snapshotFixture(t, plain)
			_, _, err := runSnapshot(t, f, dir, tables, SnapshotAuto, "0.11.0")
			if !errors.Is(err, ErrSnapshot) {
				t.Fatalf("want ErrSnapshot, got %v", err)
			}
			if _, err := dir.Stat("wp_secret.sql"); !errors.Is(err, os.ErrNotExist) {
				t.Error("unexpected table stored")
			}
		})
	}
}

func TestSnapshotRefusesMoreDataThanAnnounced(t *testing.T) {
	f, dir, tables := snapshotFixture(t, goodPlain)
	f.plain = 10
	if _, _, err := runSnapshot(t, f, dir, tables, SnapshotAuto, "0.11.0"); !errors.Is(err, ErrSnapshot) {
		t.Fatalf("want ErrSnapshot, got %v", err)
	}
}

func TestParseSnapshotMode(t *testing.T) {
	for in, want := range map[string]SnapshotMode{"": SnapshotAuto, "auto": SnapshotAuto, "require": SnapshotRequire, "off": SnapshotOff} {
		if got, err := ParseSnapshotMode(in); err != nil || got != want {
			t.Errorf("%q → %q, %v", in, got, err)
		}
	}
	if _, err := ParseSnapshotMode("nein"); err == nil {
		t.Error("want error for nein")
	}
}
