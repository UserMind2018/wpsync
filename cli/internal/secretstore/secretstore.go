// Package secretstore holds the pairing secret: the macOS keychain on the Mac, stdin on the
// server (Spec Server-Modus §5). Secrets never come from arguments or environment variables.
package secretstore

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"strings"

	"github.com/usermind/wpsync/internal/keychain"
	"github.com/usermind/wpsync/internal/sites"
)

// ErrNotFound: no secret stored for the site.
var ErrNotFound = errors.New("kein Secret gespeichert")

// ErrReadOnly: the stdin store cannot keep a secret – pair --json --secret-out hands it to the caller.
var ErrReadOnly = errors.New("Secrets über stdin werden nicht gespeichert – pair --json --secret-out verwenden")

// maxLine bounds one stdin line; secrets are far shorter.
const maxLine = 4096

// Store holds the pairing secret of a site.
type Store interface {
	Get(site string) (string, error)
	Set(site, secret string) error
	Delete(site string) error
}

// Keychain stores secrets as service wpsync:<site>, account <site> (behaviour before the server mode).
type Keychain struct{ KC keychain.Store }

// Get returns the site's secret.
func (k Keychain) Get(site string) (string, error) {
	s, err := k.KC.Get(sites.KeychainService(site), site)
	if errors.Is(err, keychain.ErrNotFound) {
		return "", ErrNotFound
	}
	return s, err
}

// Set stores the site's secret.
func (k Keychain) Set(site, secret string) error {
	return k.KC.Set(sites.KeychainService(site), site, secret)
}

// Delete removes the site's secret; a missing entry is not an error.
func (k Keychain) Delete(site string) error { return k.KC.Delete(sites.KeychainService(site), site) }

// Stdin reads the pairing secret from the first line of stdin and the DB password of the
// container mode from the second. Each line is read once, when it is first needed.
type Stdin struct {
	r     *bufio.Reader
	lines []string
}

// NewStdin reads from r (os.Stdin in main).
func NewStdin(r io.Reader) *Stdin { return &Stdin{r: bufio.NewReaderSize(r, maxLine)} }

func (s *Stdin) line(n int) (string, error) {
	for len(s.lines) <= n {
		raw, err := s.r.ReadSlice('\n')
		if errors.Is(err, bufio.ErrBufferFull) {
			return "", fmt.Errorf("stdin: Zeile %d ist länger als %d Zeichen", len(s.lines)+1, maxLine)
		}
		if err != nil && (err != io.EOF || len(raw) == 0) {
			return "", fmt.Errorf("stdin: Zeile %d fehlt", n+1)
		}
		s.lines = append(s.lines, strings.TrimRight(string(raw), "\r\n"))
	}
	if s.lines[n] == "" {
		return "", fmt.Errorf("stdin: Zeile %d ist leer", n+1)
	}
	return s.lines[n], nil
}

// Get returns the first line; the site name does not matter.
func (s *Stdin) Get(string) (string, error) { return s.line(0) }

// DBPassword returns the second line.
func (s *Stdin) DBPassword() (string, error) { return s.line(1) }

// Set refuses: the server mode stores no secrets.
func (s *Stdin) Set(string, string) error { return ErrReadOnly }

// Delete has nothing to delete.
func (s *Stdin) Delete(string) error { return nil }
