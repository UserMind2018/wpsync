package pull

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/profile"
)

// The import connects as DDEV's default database user. db/db is DDEV's public default, not a
// secret; wp-config-ddev.php connects the site with the same account.
const (
	importDBUser     = "db"
	importDBPassword = "db"
	importDBName     = "db"
)

// ErrDBImport: the local client rejected the dump; statements before the rejected line are applied.
var ErrDBImport = errors.New("Datenbank-Import abgebrochen – der lokale Datenbank-Client hat den Dump der Site abgelehnt (Meldung oben). " +
	"Die lokale Datenbank kann unvollständig sein; es wurde nichts als abgeschlossen gespeichert. " +
	"Der nächste Pull importiert dieselben Tabellen erneut.")

// importArgs is the only definition of the `ddev mysql` call for the dump. The dump is server
// content, so the client must treat it as SQL only:
//   - --binary-mode turns off client commands such as `\!` (shell), `source`, `tee` and `\q`;
//     without it a line in a table file runs a shell command in the db container.
//   - --local-infile=0 stops LOAD DATA LOCAL INFILE from reading files of the container.
//   - user db instead of DDEV's default root: db has no FILE privilege, so INTO OUTFILE,
//     LOAD DATA INFILE and LOAD_FILE() cannot touch files, and no other database is reachable.
//
// No version-specific options and no retry with fewer options: a client that rejects one of
// these fails the pull before anything is imported.
func importArgs() []string {
	return []string{
		"mysql",
		"--user=" + importDBUser,
		"--password=" + importDBPassword,
		"--database=" + importDBName,
		"--binary-mode",
		"--local-infile=0",
	}
}

// importOrder puts the structure-only tables first: their DROP/CREATE removes the rows the profile
// keeps off this machine (personal data, bookings). If the import aborts later, they are empty
// all the same. Otherwise the order of the site stays. The input is not changed.
func importOrder(tables []agentapi.Table) []agentapi.Table {
	out := make([]agentapi.Table, 0, len(tables))
	for _, t := range tables {
		if t.Mode == profile.ModeStructure {
			out = append(out, t)
		}
	}
	for _, t := range tables {
		if t.Mode != profile.ModeStructure {
			out = append(out, t)
		}
	}
	return out
}

// markerPrefix starts the line the client prints before each table.
const markerPrefix = "wpsync-table:"

// importMarker is a SELECT the client answers on stdout before it reads the table's SQL. Table
// names follow tableNameRe – there is no quote to escape.
func importMarker(name string) string {
	return "SELECT '" + markerPrefix + name + "' AS wpsync_marker;\n"
}

// ImportError: the client aborted in Table; NotReplaced still hold their state before this pull.
// errors.Is(err, ErrDBImport) holds, so the exit code stays.
type ImportError struct {
	Table       string
	NotReplaced []string
	Err         error
}

func (e *ImportError) Error() string {
	msg := fmt.Sprintf("Datenbank-Import abgebrochen in Tabelle %s – der lokale Datenbank-Client hat den Dump abgelehnt (Meldung oben).", e.Table)
	if n := len(e.NotReplaced); n > 0 {
		shown := e.NotReplaced
		list := ""
		if n > 10 {
			shown = shown[:10]
			list = ", …"
		}
		msg += fmt.Sprintf(" Nicht ersetzt, lokal noch der Stand vor diesem Pull (%d): %s%s.", n, strings.Join(shown, ", "), list)
	}
	return msg + " Es wurde nichts als abgeschlossen gespeichert; der nächste Pull importiert dieselben Tabellen erneut.\n  (" + e.Err.Error() + ")"
}

func (e *ImportError) Unwrap() []error { return []error{ErrDBImport, e.Err} }

// markerWriter keeps the last table marker of the client's stdout; everything else is dropped.
type markerWriter struct {
	partial []byte
	last    string
}

func (m *markerWriter) Write(p []byte) (int, error) {
	m.partial = append(m.partial, p...)
	for {
		i := bytes.IndexByte(m.partial, '\n')
		if i < 0 {
			break
		}
		line := strings.TrimSpace(string(m.partial[:i]))
		m.partial = m.partial[i+1:]
		if name, ok := strings.CutPrefix(line, markerPrefix); ok {
			m.last = name
		}
	}
	if len(m.partial) > 4096 { // a marker line is short; anything longer is other output
		m.partial = nil
	}
	return len(p), nil
}

// importTables pipes header, the downloaded table files and footer into one hardened client run.
func importTables(r localenv.Runner, dir string, tables []agentapi.Table) error {
	reader, closeAll, err := ImportReader(dir, tables)
	if err != nil {
		return err
	}
	return runImport(r, reader, closeAll, importOrder(tables))
}

// importTablesIn is importTables on the opened cache folder (pull: openTables).
func importTablesIn(r localenv.Runner, dir *os.Root, tables []agentapi.Table) error {
	order := importOrder(tables)
	reader, closeAll, err := importReader(dir, order)
	if err != nil {
		return err
	}
	return runImport(r, reader, closeAll, order)
}

// runImport feeds the stream to the client. With a Streamer the client's stdout is read for the
// table markers, so an abort names its table and the tables after it.
func runImport(r localenv.Runner, reader io.Reader, closeAll func() error, order []agentapi.Table) error {
	marks := &markerWriter{}
	var importErr error
	if s, ok := r.(localenv.Streamer); ok {
		importErr = s.Stream(reader, marks, importArgs()...)
	} else {
		importErr = r.RunStdin(reader, importArgs()...)
	}
	closeAll()
	if importErr == nil {
		return nil
	}
	for i, t := range order {
		if t.Name != marks.last {
			continue
		}
		var rest []string
		for _, n := range order[i+1:] {
			rest = append(rest, n.Name)
		}
		return &ImportError{Table: t.Name, NotReplaced: rest, Err: importErr}
	}
	return fmt.Errorf("%w\n  (%w)", ErrDBImport, importErr)
}
