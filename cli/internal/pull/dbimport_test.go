package pull

import (
	"errors"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

// importRunner records RunStdin calls with the full stream and returns a configurable error.
type importRunner struct {
	calls   [][]string
	streams []string
	err     error
}

func (r *importRunner) Run(args ...string) error { return errors.New("unexpected Run") }
func (r *importRunner) Output(args ...string) (string, error) {
	return "", errors.New("unexpected Output")
}
func (r *importRunner) RunStdin(stdin io.Reader, args ...string) error {
	r.calls = append(r.calls, args)
	data, err := io.ReadAll(stdin)
	if err != nil {
		return err
	}
	r.streams = append(r.streams, string(data))
	return r.err
}

func writeTableFiles(t *testing.T, files map[string]string) (string, []agentapi.Table) {
	t.Helper()
	dir := t.TempDir()
	var tables []agentapi.Table
	for _, name := range []string{"wp_options", "wp_posts"} {
		body, ok := files[name]
		if !ok {
			continue
		}
		if err := os.WriteFile(filepath.Join(dir, name+".sql"), []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
		tables = append(tables, agentapi.Table{Name: name})
	}
	return dir, tables
}

func TestImportArgsHardened(t *testing.T) {
	want := []string{"mysql", "--user=db", "--password=db", "--database=db", "--binary-mode", "--local-infile=0"}
	got := importArgs()
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("importArgs() = %q, want %q", got, want)
	}
	for _, a := range got {
		if strings.Contains(a, "root") {
			t.Errorf("import must not connect as root, got %q", a)
		}
	}
}

func TestImportTablesSingleHardenedCall(t *testing.T) {
	dir, tables := writeTableFiles(t, map[string]string{
		"wp_options": "INSERT INTO `wp_options` VALUES (1,'siteurl','https://kunde.example');\n",
		"wp_posts":   "INSERT INTO `wp_posts` VALUES (1,'Hallo');\n",
	})
	r := &importRunner{}
	if err := importTables(r, dir, tables); err != nil {
		t.Fatal(err)
	}
	if len(r.calls) != 1 {
		t.Fatalf("want exactly one RunStdin call, got %d", len(r.calls))
	}
	if !reflect.DeepEqual(r.calls[0], importArgs()) {
		t.Errorf("RunStdin args = %q, want %q", r.calls[0], importArgs())
	}
	s := r.streams[0]
	if !strings.HasPrefix(s, "SET NAMES utf8mb4;") || !strings.HasPrefix(s, importHeader) {
		t.Errorf("stream does not start with header:\n%s", s)
	}
	if !strings.HasSuffix(s, importFooter) {
		t.Errorf("stream does not end with footer:\n%s", s)
	}
	for _, want := range []string{"https://kunde.example", "'Hallo'"} {
		if !strings.Contains(s, want) {
			t.Errorf("stream misses table content %q", want)
		}
	}
}

func TestImportTablesFailsClosedWithoutRetry(t *testing.T) {
	cause := errors.New("exit status 2")
	dir, tables := writeTableFiles(t, map[string]string{"wp_options": "\\! touch /tmp/x\n"})
	r := &importRunner{err: cause}
	err := importTables(r, dir, tables)
	if err == nil {
		t.Fatal("want error when the client fails")
	}
	if len(r.calls) != 1 {
		t.Fatalf("want exactly one RunStdin call (no retry with fewer options), got %d", len(r.calls))
	}
	if !errors.Is(err, ErrDBImport) {
		t.Errorf("want errors.Is(err, ErrDBImport), got %v", err)
	}
	if !errors.Is(err, cause) {
		t.Errorf("original client error not reachable via errors.Is: %v", err)
	}
}

func TestImportErrorMessageGerman(t *testing.T) {
	msg := ErrDBImport.Error()
	for _, want := range []string{"Datenbank-Import", "unvollständig"} {
		if !strings.Contains(msg, want) {
			t.Errorf("ErrDBImport message misses %q: %s", want, msg)
		}
	}
}

func TestImportTablesMissingFileNoClientRun(t *testing.T) {
	dir, tables := writeTableFiles(t, map[string]string{"wp_options": "SELECT 1;\n"})
	tables = append(tables, agentapi.Table{Name: "wp_missing"})
	r := &importRunner{}
	err := importTables(r, dir, tables)
	if err == nil {
		t.Fatal("want error for a missing table file")
	}
	if len(r.calls) != 0 {
		t.Errorf("want no RunStdin call, got %d", len(r.calls))
	}
	if errors.Is(err, ErrDBImport) {
		t.Errorf("missing file must not be reported as client rejection: %v", err)
	}
	if !strings.Contains(err.Error(), "wp_missing") {
		t.Errorf("error should name the table: %v", err)
	}
}

func importNames(ts []agentapi.Table) []string {
	out := make([]string, len(ts))
	for i, t := range ts {
		out[i] = t.Name
	}
	return out
}

// writeTables writes one file per table and returns the folder.
func writeTables(t *testing.T, tables []agentapi.Table, body map[string]string) string {
	t.Helper()
	dir := t.TempDir()
	for _, tb := range tables {
		if err := os.WriteFile(filepath.Join(dir, tb.Name+".sql"), []byte(body[tb.Name]), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return dir
}

func TestImportOrderPutsStructureTablesFirst(t *testing.T) {
	in := []agentapi.Table{
		{Name: "wp_a", Mode: profile.ModeFull},
		{Name: "wp_options", Mode: "anon:2.abc+full"},
		{Name: "wp_ssa", Mode: profile.ModeStructure},
		{Name: "wp_z", Mode: profile.ModeStructure},
	}
	got := importNames(importOrder(in))
	want := []string{"wp_ssa", "wp_z", "wp_a", "wp_options"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("importOrder = %v, want %v", got, want)
	}
	if importNames(in)[0] != "wp_a" {
		t.Fatal("importOrder must not reorder its input")
	}
}

func TestImportStreamHasStructureFirstAndAMarkerPerTable(t *testing.T) {
	tables := []agentapi.Table{
		{Name: "wp_options", Mode: profile.ModeFull},
		{Name: "wp_ssa", Mode: profile.ModeStructure},
	}
	dir := writeTables(t, tables, map[string]string{
		"wp_options": "INSERT INTO `wp_options` VALUES (1);\n",
		"wp_ssa":     "DROP TABLE IF EXISTS `wp_ssa`;\nCREATE TABLE `wp_ssa` (x int);\n",
	})
	r := &importRunner{}
	if err := importTables(r, dir, tables); err != nil {
		t.Fatal(err)
	}
	s := r.streams[0]
	ssa := strings.Index(s, "SELECT 'wpsync-table:wp_ssa' AS wpsync_marker;\nDROP TABLE IF EXISTS `wp_ssa`")
	opt := strings.Index(s, "SELECT 'wpsync-table:wp_options' AS wpsync_marker;\nINSERT INTO `wp_options`")
	if ssa < 0 || opt < 0 || ssa > opt {
		t.Fatalf("want marker+structure table before marker+options, got:\n%s", s)
	}
}

// importStreamRunner is importRunner with localenv.Streamer: like the client it prints out, then fails.
type importStreamRunner struct {
	importRunner
	out string
}

func (r *importStreamRunner) Stream(stdin io.Reader, stdout io.Writer, args ...string) error {
	r.calls = append(r.calls, args)
	data, err := io.ReadAll(stdin)
	if err != nil {
		return err
	}
	r.streams = append(r.streams, string(data))
	if _, err := io.WriteString(stdout, r.out); err != nil {
		return err
	}
	return r.err
}

func abortTables(t *testing.T) (string, []agentapi.Table) {
	tables := []agentapi.Table{
		{Name: "wp_options", Mode: profile.ModeFull},
		{Name: "wp_posts", Mode: profile.ModeFull},
		{Name: "wp_ssa", Mode: profile.ModeStructure},
	}
	return writeTables(t, tables, map[string]string{
		"wp_options": "INSERT INTO `wp_options` VALUES (1);\n",
		"wp_posts":   "INSERT INTO `wp_posts` VALUES (1);\n",
		"wp_ssa":     "CREATE TABLE `wp_ssa` (x int);\n",
	}), tables
}

func TestImportErrorNamesTableAndNotReplaced(t *testing.T) {
	dir, tables := abortTables(t)
	cause := errors.New("exit status 1")
	r := &importStreamRunner{importRunner: importRunner{err: cause},
		out: "wpsync_marker\nwpsync-table:wp_ssa\nwpsync_marker\nwpsync-table:wp_options\n"}
	err := importTables(r, dir, tables)
	var ie *ImportError
	if !errors.As(err, &ie) {
		t.Fatalf("want *ImportError, got %v", err)
	}
	if ie.Table != "wp_options" || !reflect.DeepEqual(ie.NotReplaced, []string{"wp_posts"}) {
		t.Fatalf("ImportError = %+v", ie)
	}
	if !errors.Is(err, ErrDBImport) || !errors.Is(err, cause) {
		t.Fatalf("errors.Is ErrDBImport/cause must hold: %v", err)
	}
	for _, want := range []string{"in Tabelle wp_options", "Nicht ersetzt", "(1): wp_posts", "erneut"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("message misses %q: %s", want, err)
		}
	}
	if !reflect.DeepEqual(r.calls[0], importArgs()) {
		t.Errorf("Stream args = %q, want %q", r.calls[0], importArgs())
	}
}

func TestImportErrorWithoutMarkerStaysPlain(t *testing.T) {
	dir, tables := abortTables(t)
	r := &importStreamRunner{importRunner: importRunner{err: errors.New("exit status 1")}}
	err := importTables(r, dir, tables)
	var ie *ImportError
	if errors.As(err, &ie) || !errors.Is(err, ErrDBImport) {
		t.Fatalf("want plain ErrDBImport, got %v", err)
	}
}

func TestImportErrorOnLastTableListsNothingUnreplaced(t *testing.T) {
	dir, tables := abortTables(t)
	r := &importStreamRunner{importRunner: importRunner{err: errors.New("exit status 1")},
		out: "wpsync-table:wp_ssa\nwpsync-table:wp_options\nwpsync-table:wp_posts\n"}
	err := importTables(r, dir, tables)
	var ie *ImportError
	if !errors.As(err, &ie) || ie.Table != "wp_posts" || len(ie.NotReplaced) != 0 {
		t.Fatalf("ImportError = %+v (%v)", ie, err)
	}
	if strings.Contains(err.Error(), "Nicht ersetzt") {
		t.Errorf("no unreplaced tables, message must not say so: %s", err)
	}
}

func TestMarkerWriterKeepsLastMarkerAcrossWrites(t *testing.T) {
	m := &markerWriter{}
	for _, part := range []string{"wpsync_marker\nwpsync-ta", "ble:wp_a\nwpsync_marker\n", "wpsync-table:wp_b\n", "Some output\n"} {
		if _, err := m.Write([]byte(part)); err != nil {
			t.Fatal(err)
		}
	}
	if m.last != "wp_b" {
		t.Fatalf("last = %q, want wp_b", m.last)
	}
}
