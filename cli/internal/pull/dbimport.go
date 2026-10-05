package pull

import (
	"errors"
	"fmt"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/ddev"
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

// importTables pipes header, the downloaded table files and footer into one hardened client run.
func importTables(r ddev.Runner, dir string, tables []agentapi.Table) error {
	reader, closeAll, err := ImportReader(dir, tables)
	if err != nil {
		return err
	}
	importErr := r.RunStdin(reader, importArgs()...)
	closeAll()
	if importErr != nil {
		return fmt.Errorf("%w\n  (%w)", ErrDBImport, importErr)
	}
	return nil
}
