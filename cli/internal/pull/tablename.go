package pull

import (
	"errors"
	"fmt"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/sites"
)

// tableNameRe is the one rule for table names. They are chosen by the paired site (/delta) and
// become file names below .wpsync/db/tables; the request signature authenticates our request,
// not the answer. The pattern covers every prefix WordPress accepts and leaves no room for path
// separators, dots, whitespace, control or non-ASCII characters.
var tableNameRe = regexp.MustCompile(`^[A-Za-z0-9_$]{1,64}$`)

// File name suffixes of one table below the tables directory.
const (
	sqlSuffix  = ".sql"
	partSuffix = ".sql.part"
	doneSuffix = ".done"
)

// maxNamesInError keeps the message short when a site sends many invalid names.
const maxNamesInError = 10

// ErrInvalidTableName: the site delivered a table name outside tableNameRe.
var ErrInvalidTableName = errors.New("invalid table name")

func validTableName(name string) bool { return tableNameRe.MatchString(name) }

// tableFile is the only way a table name becomes a file name in the tables directory. It checks
// the name itself, so DownloadTables and ImportReader stay contained even without the /delta check.
func tableFile(name, suffix string) (string, error) {
	if !validTableName(name) {
		return "", fmt.Errorf("%w: refusing %s", ErrInvalidTableName, agentapi.Printable(name))
	}
	file := name + suffix
	if filepath.Base(file) != file {
		return "", fmt.Errorf("%w: %s would leave the tables directory", ErrInvalidTableName, agentapi.Printable(name))
	}
	return file, nil
}

// tablePath is tableFile below dir.
func tablePath(dir, name, suffix string) (string, error) {
	file, err := tableFile(name, suffix)
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, file), nil
}

// checkDelta is the gate for everything from /delta that later reaches a path or a command line.
// Table names and Env values are reported together, so one run shows every problem.
func checkDelta(d *agentapi.Delta, site string) error {
	return errors.Join(checkTableNames(d.Tables, site), checkEnv(d.Env))
}

// invalidEnvError starts in German for the user and still matches agentapi.ErrInvalidEnv.
type invalidEnvError struct{ fields []agentapi.EnvField }

func (e *invalidEnvError) Is(target error) bool { return target == agentapi.ErrInvalidEnv }

func (e *invalidEnvError) Error() string {
	shown := make([]string, len(e.fields))
	for i, f := range e.fields {
		shown[i] = f.Key + " = " + agentapi.Printable(f.Value)
	}
	return "die Site meldet Angaben in unzulässiger Form: " + strings.Join(shown, ", ") + "\n" +
		"  Erwartet: Tabellenpräfix aus Buchstaben, Ziffern und _ (höchstens 64 Zeichen), Adressen als http(s)-URL, PHP-Version als <Major>.<Minor>[.…].\n" +
		"  Die Angaben stammen von der Site, deshalb läuft der Pull nicht. Es wurden keine Daten geladen und im Site-Ordner nichts verändert.\n" +
		"  Auf der Site $table_prefix in wp-config.php und unter Einstellungen → Allgemein die WordPress- und Website-Adresse prüfen."
}

// checkEnv checks the Env values that later become WP-CLI arguments (agentapi.Env.InvalidArgs).
func checkEnv(env agentapi.Env) error {
	if bad := env.InvalidArgs(); len(bad) > 0 {
		return &invalidEnvError{fields: bad}
	}
	return nil
}

// invalidTableNamesError starts in German for the user and still matches ErrInvalidTableName.
type invalidTableNamesError struct {
	names  []string
	config string // the site's config file, where the user sets the override
}

func (e *invalidTableNamesError) Is(target error) bool { return target == ErrInvalidTableName }

func (e *invalidTableNamesError) Error() string {
	shown := make([]string, 0, maxNamesInError)
	for _, name := range e.names[:min(len(e.names), maxNamesInError)] {
		shown = append(shown, agentapi.Printable(name))
	}
	list := strings.Join(shown, ", ")
	if len(e.names) > maxNamesInError {
		list += fmt.Sprintf(" … (%d insgesamt)", len(e.names))
	}
	return fmt.Sprintf("die Site liefert Tabellennamen mit unzulässigen Zeichen: %s\n"+
		"  Erlaubt sind Buchstaben A–Z, Ziffern, _ und $, höchstens 64 Zeichen. Es wurden keine Daten geladen und im Site-Ordner nichts verändert.\n"+
		"  Gehört die Tabelle zur Site: in %s unter profile → tables.overrides\n"+
		"  auf skip setzen (<name>: skip) und erneut ziehen. Sonst die Site prüfen – so ein Name ist ungewöhnlich.",
		list, e.config)
}

// checkTableNames reports all invalid names at once, so the profile can be fixed in one go.
func checkTableNames(tables []agentapi.Table, site string) error {
	var bad []string
	for _, t := range tables {
		if !validTableName(t.Name) {
			bad = append(bad, t.Name)
		}
	}
	if len(bad) == 0 {
		return nil
	}
	config := "der Site-Konfiguration (sites/" + site + ".yaml)"
	if dir, err := sites.ConfigDir(); err == nil {
		config = filepath.Join(dir, "sites", site+".yaml")
	}
	return &invalidTableNamesError{names: bad, config: config}
}
