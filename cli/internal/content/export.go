package content

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
)

var (
	// ErrNoStream: the driver's runner cannot feed stdin and deliver stdout at once.
	ErrNoStream = errors.New("die lokale Umgebung kann kein Skript über stdin ausführen")
	// ErrExport: the export script did not finish; its output is incomplete.
	ErrExport = errors.New("der Export der Arbeitskopie ist unvollständig")
)

// Export runs the export script in the local site and writes one line per record to w:
// {t, k, h[, row]} in normalized form (Spec Content-Push §4.4). tables narrows it to content tables
// without prefix; nil means all seven. It returns the number of records.
func Export(r localenv.Runner, localURL string, tables []string, w io.Writer) (int, error) {
	s, ok := r.(localenv.Streamer)
	if !ok {
		return 0, ErrNoStream
	}
	which := "all"
	if len(tables) > 0 {
		which = strings.Join(tables, ",")
	}
	pr, pw := io.Pipe()
	done := make(chan error, 1)
	go func() {
		err := s.Stream(bytes.NewReader(Script("export.php")), pw, "wp", "eval-file", "-", localURL, which, "--skip-plugins", "--skip-themes")
		pw.CloseWithError(err)
		done <- err
	}()
	rows, end, noise, copyErr := copyRecords(pr, w)
	pr.CloseWithError(copyErr) // releases the script if writing to w failed
	runErr := <-done
	switch {
	case copyErr != nil && !errors.Is(copyErr, runErr):
		return rows, copyErr
	case runErr != nil:
		return rows, fmt.Errorf("%w: %v%s", ErrExport, runErr, noise)
	case end < 0:
		return rows, fmt.Errorf("%w: die Schlusszeile fehlt%s", ErrExport, noise)
	case end != rows:
		return rows, fmt.Errorf("%w: %d von %d Zeilen%s", ErrExport, rows, end, noise)
	}
	return rows, nil
}

// copyRecords copies the record lines of the script to w. end is the count of its last line, -1
// without one; noise are the first lines that are neither (PHP notices, a fatal error), cleaned.
func copyRecords(r io.Reader, w io.Writer) (rows, end int, noise string, err error) {
	end = -1
	var other []string
	br := bufio.NewReaderSize(r, 1<<20)
	for {
		line, readErr := br.ReadBytes('\n')
		line = bytes.TrimRight(line, "\r\n")
		switch {
		case bytes.HasPrefix(line, []byte(`{"t":`)):
			if _, err := w.Write(append(line, '\n')); err != nil {
				return rows, end, "", err
			}
			rows++
		case bytes.HasPrefix(line, []byte(`{"end":true`)):
			var last struct {
				Rows int `json:"rows"`
			}
			if json.Unmarshal(line, &last) == nil {
				end = last.Rows
			}
		case len(bytes.TrimSpace(line)) > 0 && len(other) < 3:
			if len(line) > 200 {
				line = line[:200]
			}
			// Cut first, clean afterwards: a rune cut in half becomes U+FFFD.
			other = append(other, agentapi.CleanText(string(line)))
		}
		if readErr != nil {
			if len(other) > 0 {
				noise = " – " + strings.Join(other, " | ")
			}
			if errors.Is(readErr, io.EOF) {
				return rows, end, noise, nil
			}
			return rows, end, noise, readErr
		}
	}
}
