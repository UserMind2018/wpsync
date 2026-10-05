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
