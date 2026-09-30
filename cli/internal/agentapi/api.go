package agentapi

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
)

// Ping returns the source environment.
func (c *Client) Ping() (*Env, error) {
	var env Env
	if err := c.PostJSON("/wpsync/v1/ping", map[string]any{}, &env); err != nil {
		return nil, err
	}
	return &env, nil
}

// Infosheet returns the precomputed inventory (nil while none exists yet) and the job status (AC-7).
func (c *Client) Infosheet() (*Infosheet, InfosheetStatus, error) {
	var res struct {
		Sheet *Infosheet      `json:"sheet"`
		Job   InfosheetStatus `json:"job"`
	}
	if err := c.PostJSON("/wpsync/v1/infosheet", map[string]any{}, &res); err != nil {
		return nil, InfosheetStatus{}, err
	}
	return res.Sheet, res.Job, nil
}

// RefreshInfosheet runs one slice of the inventory on the agent; start begins a new one (AC-9).
func (c *Client) RefreshInfosheet(start bool) (InfosheetStatus, error) {
	var st InfosheetStatus
	err := c.PostJSON("/wpsync/v1/infosheet/refresh", map[string]any{"start": start}, &st)
	return st, err
}

// Delta fetches tables with checksums and the file list within scope, page by page.
func (c *Client) Delta(scope Scope) (*Delta, error) {
	d := &Delta{}
	cursor := ""
	for page := 0; page < 100000; page++ {
		var p deltaPage
		if err := c.PostJSON("/wpsync/v1/delta", map[string]any{"cursor": cursor, "scope": scope}, &p); err != nil {
			return nil, err
		}
		if p.Env != nil {
			d.Env = *p.Env
		}
		d.Tables = append(d.Tables, p.Tables...)
		d.Files = append(d.Files, p.Files...)
		d.Skipped = append(d.Skipped, p.Skipped...)
		if p.Next == nil {
			return d, nil
		}
		cursor = *p.Next
	}
	return nil, errors.New("delta: too many pages")
}

// Files streams the given files.
func (c *Client) Files(paths []string, onFile FileHandler, onMissing func(string)) error {
	resp, err := c.Post("/wpsync/v1/files", map[string]any{"paths": paths})
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return ReadFileFrames(resp.Body, onFile, onMissing)
}

// DBBundle requests several small tables within scope and returns which arrived.
func (c *Client) DBBundle(names []string, limit int, scope Scope, onTable TableHandler) ([]string, error) {
	resp, err := c.Post("/wpsync/v1/db-bundle", map[string]any{"tables": names, "limit": limit, "scope": scope})
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	return ReadTableFrames(resp.Body, onTable)
}

// DBChunk writes one chunk of a large table to w.
func (c *Client) DBChunk(req ChunkRequest, w io.Writer) (ChunkResult, error) {
	resp, err := c.Post("/wpsync/v1/db", req)
	if err != nil {
		return ChunkResult{}, err
	}
	defer resp.Body.Close()
	rows, err := strconv.Atoi(resp.Header.Get("X-Wpsync-Rows"))
	if err != nil {
		return ChunkResult{}, fmt.Errorf("table %s: missing X-Wpsync-Rows", req.Table)
	}
	res := ChunkResult{Rows: rows, Mode: resp.Header.Get("X-Wpsync-Mode")}
	if next := resp.Header.Get("X-Wpsync-Next"); next != "" {
		raw, err := base64.StdEncoding.DecodeString(next)
		if err != nil {
			return ChunkResult{}, fmt.Errorf("table %s: bad X-Wpsync-Next: %w", req.Table, err)
		}
		s := string(raw)
		res.Next = &s
	}
	if _, err := io.Copy(w, resp.Body); err != nil {
		return ChunkResult{}, fmt.Errorf("table %s: %w", req.Table, err)
	}
	return res, nil
}

// Discover follows redirects with a GET on the namespace index and returns the canonical base URL.
func Discover(hc *http.Client, rawURL string) (string, error) {
	req, err := http.NewRequest(http.MethodGet, strings.TrimRight(rawURL, "/")+"/?rest_route=/wpsync/v1", nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("User-Agent", UserAgent())
	resp, err := hc.Do(req)
	if err != nil {
		return "", fmt.Errorf("discover %s: %w", rawURL, err)
	}
	defer resp.Body.Close()
	var index struct {
		Namespace string `json:"namespace"`
	}
	if resp.StatusCode != http.StatusOK || json.NewDecoder(resp.Body).Decode(&index) != nil || index.Namespace != "wpsync/v1" {
		return "", fmt.Errorf("discover %s: wpsync agent not found (HTTP %d)", rawURL, resp.StatusCode)
	}
	final := resp.Request.URL
	return final.Scheme + "://" + final.Host + strings.TrimRight(final.Path, "/"), nil
}

// Pair redeems a pairing code; the secret is returned exactly once.
func Pair(hc *http.Client, baseURL, code, device string) (*PairResult, error) {
	payload, _ := json.Marshal(map[string]string{"code": code, "device": device})
	req, err := http.NewRequest(http.MethodPost, strings.TrimRight(baseURL, "/")+"/?rest_route=/wpsync/v1/pair", bytes.NewReader(payload))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", UserAgent())
	resp, err := hc.Do(req)
	if err != nil {
		return nil, fmt.Errorf("pair: %w", err)
	}
	if resp.StatusCode != http.StatusOK {
		return nil, readAPIError(resp)
	}
	defer resp.Body.Close()
	var res PairResult
	if err := json.NewDecoder(resp.Body).Decode(&res); err != nil || res.KeyID == "" || res.Secret == "" {
		return nil, fmt.Errorf("pair: invalid response")
	}
	return &res, nil
}
