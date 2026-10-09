package agentapi

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
)

// MinAgentContent is the first agent with /content/manifest (Spec Content-Push §4).
const MinAgentContent = "0.7.0"

// ContentOrigins are home and siteurl of the live site, without a trailing slash.
type ContentOrigins struct {
	Home    string `json:"home"`
	SiteURL string `json:"siteurl"`
}

// ContentHead is the first line of the manifest (Spec Content-Push §4.2). Pseudonym and Lists are
// kept as delivered: the studio reads them, the CLI does not interpret them.
type ContentHead struct {
	CanonVersion int               `json:"canon_version"`
	ListVersion  int               `json:"list_version"`
	Variants     []string          `json:"variants"`
	Origins      ContentOrigins    `json:"origins"`
	IDMax        map[string]int64  `json:"id_max"`
	Engines      map[string]string `json:"engines"`
	Tables       []string          `json:"tables"`
	Pseudonym    json.RawMessage   `json:"pseudonym"`
	Prefix       string            `json:"prefix"`
	Charset      string            `json:"charset"`
	Pushable     bool              `json:"pushable"`
	Why          string            `json:"why,omitempty"`
	Lists        json.RawMessage   `json:"lists"`
}

// ErrManifest: the agent's manifest is incomplete or not in the expected form.
var ErrManifest = errors.New("das Inhalts-Manifest des Agents ist unvollständig")

// ContentManifest fetches the row manifest of the content tables within scope, page by page, and
// writes it to w as delivered: the head line, then one line {t, k, h} per row. It returns the head
// and the number of rows.
func (c *Client) ContentManifest(scope Scope, w io.Writer) (*ContentHead, int, error) {
	var head *ContentHead
	cursor := json.RawMessage("null")
	rows := 0
	for page := 0; page < 100000; page++ {
		resp, err := c.Post("/wpsync/v1/content/manifest", map[string]any{"scope": scope, "cursor": cursor})
		if err != nil {
			return nil, rows, err
		}
		h, n, next, err := readManifestPage(resp.Body, w, page == 0)
		resp.Body.Close()
		if err != nil {
			return nil, rows, err
		}
		if h != nil {
			head = h
		}
		rows += n
		if next == nil {
			return head, rows, nil
		}
		if bytes.Equal(next, cursor) {
			return nil, rows, fmt.Errorf("%w: der Cursor des Agents rückt nicht vor", ErrManifest)
		}
		cursor = next
	}
	return nil, rows, fmt.Errorf("%w: zu viele Seiten", ErrManifest)
}

// readManifestPage copies the row lines of one page to w. The first page starts with the head; every
// page ends with {"next": cursor|null}. next is nil when the manifest is complete.
func readManifestPage(r io.Reader, w io.Writer, first bool) (head *ContentHead, rows int, next json.RawMessage, err error) {
	br := bufio.NewReaderSize(r, 1<<16)
	ended := false
	for line := 0; ; line++ {
		data, readErr := br.ReadBytes('\n')
		data = bytes.TrimRight(data, "\r\n")
		switch {
		case len(data) == 0:
		case ended:
			return nil, rows, nil, fmt.Errorf("%w: Daten nach dem Seitenende", ErrManifest)
		case bytes.HasPrefix(data, []byte(`{"head":`)):
			if !first || line != 0 {
				return nil, rows, nil, fmt.Errorf("%w: Kopf an unerwarteter Stelle", ErrManifest)
			}
			var wrap struct {
				Head ContentHead `json:"head"`
			}
			if err := json.Unmarshal(data, &wrap); err != nil {
				return nil, rows, nil, fmt.Errorf("%w: Kopf unlesbar (%v)", ErrManifest, err)
			}
			head = &wrap.Head
			if _, err := w.Write(append(data, '\n')); err != nil {
				return nil, rows, nil, err
			}
		case bytes.HasPrefix(data, []byte(`{"t":`)):
			if first && head == nil {
				return nil, rows, nil, fmt.Errorf("%w: der Kopf fehlt", ErrManifest)
			}
			if _, err := w.Write(append(data, '\n')); err != nil {
				return nil, rows, nil, err
			}
			rows++
		case bytes.HasPrefix(data, []byte(`{"next":`)):
			var wrap struct {
				Next json.RawMessage `json:"next"`
			}
			if err := json.Unmarshal(data, &wrap); err != nil {
				return nil, rows, nil, fmt.Errorf("%w: Seitenende unlesbar (%v)", ErrManifest, err)
			}
			if string(wrap.Next) != "null" {
				next = wrap.Next
			}
			ended = true
		case bytes.HasPrefix(data, []byte(`{"error":`)):
			return nil, rows, nil, fmt.Errorf("%w: der Agent konnte die Tabellen nicht lesen", ErrManifest)
		default:
			return nil, rows, nil, fmt.Errorf("%w: unerwartete Zeile %s", ErrManifest, Printable(string(data[:min(len(data), 80)])))
		}
		if readErr != nil {
			if !errors.Is(readErr, io.EOF) {
				return nil, rows, nil, readErr
			}
			break
		}
	}
	if !ended || (first && head == nil) {
		return nil, rows, nil, fmt.Errorf("%w: die Seite endet ohne Abschluss", ErrManifest)
	}
	return head, rows, next, nil
}
