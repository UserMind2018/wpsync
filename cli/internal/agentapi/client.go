package agentapi

import (
	"bytes"
	"compress/gzip"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Version is the CLI version, sent in the User-Agent. Release builds override it
// via -ldflags "-X github.com/usermind/wpsync/internal/agentapi.Version=…".
var Version = "0.1.5"

// UserAgent is fixed and documented so admins can allow it explicitly (Spike B23).
func UserAgent() string { return "wpsync/" + Version }

// ErrSuspectedBan: the server stopped answering after earlier success. Never retried –
// retries extend fail2ban bans (Konzept E16, AC-26).
var ErrSuspectedBan = errors.New("server stopped responding after earlier success – suspected IP ban")

// APIError is a non-200 answer from the agent.
type APIError struct {
	Status  int
	Code    string
	Message string
}

func (e *APIError) Error() string {
	if e.Code != "" {
		return fmt.Sprintf("HTTP %d %s: %s", e.Status, e.Code, e.Message)
	}
	return fmt.Sprintf("HTTP %d: %s", e.Status, e.Message)
}

// Stats counts requests and bytes on the wire (before decompression).
type Stats struct {
	Requests int
	BytesIn  int64
}

// Client sends signed, throttled requests to one paired agent.
type Client struct {
	BaseURL     string
	KeyID       string
	Secret      string
	MinInterval time.Duration
	HTTP        *http.Client
	Now         func() time.Time
	Sleep       func(time.Duration)
	Stats       Stats

	last      time.Time
	successes int
}

// New creates a client limited to rps requests per second.
func New(baseURL, keyID, secret string, rps float64) *Client {
	if rps <= 0 {
		rps = 1
	}
	return &Client{
		BaseURL:     strings.TrimRight(baseURL, "/"),
		KeyID:       keyID,
		Secret:      secret,
		MinInterval: time.Duration(float64(time.Second) / rps),
		HTTP:        &http.Client{Timeout: 10 * time.Minute, CheckRedirect: noRedirect},
		Now:         time.Now,
		Sleep:       time.Sleep,
	}
}

// Signed POSTs never follow redirects: a 301 would turn them into GETs (Spike B24).
func noRedirect(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }

var backoffStatus = map[int]bool{429: true, 502: true, 503: true, 504: true}

const maxBackoffs = 3

// Post sends body to route (e.g. "/wpsync/v1/ping"). The caller closes the body.
func (c *Client) Post(route string, body any) (*http.Response, error) {
	payload, err := json.Marshal(body)
	if err != nil {
		return nil, fmt.Errorf("encode body: %w", err)
	}
	for attempt := 0; ; attempt++ {
		c.throttle()
		req, err := http.NewRequest(http.MethodPost, c.BaseURL+"/?rest_route="+route, bytes.NewReader(payload))
		if err != nil {
			return nil, err
		}
		c.sign(req, route, payload)

		c.Stats.Requests++
		resp, err := c.HTTP.Do(req)
		if err != nil {
			if c.successes > 0 && isConnectionFailure(err) {
				return nil, fmt.Errorf("%w: %v", ErrSuspectedBan, err)
			}
			return nil, fmt.Errorf("request %s: %w", route, err)
		}
		if backoffStatus[resp.StatusCode] && attempt < maxBackoffs {
			wait := retryAfter(resp, time.Duration(10<<attempt)*time.Second)
			resp.Body.Close()
			c.Sleep(wait)
			continue
		}
		if resp.StatusCode != http.StatusOK {
			return nil, readAPIError(resp)
		}
		c.successes++
		return c.wrapBody(resp, route)
	}
}

// PostJSON posts body and decodes the JSON answer into out.
func (c *Client) PostJSON(route string, body, out any) error {
	resp, err := c.Post(route, body)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if err := json.NewDecoder(resp.Body).Decode(out); err != nil {
		return fmt.Errorf("decode %s: %w", route, err)
	}
	return nil
}

func (c *Client) throttle() {
	if !c.last.IsZero() {
		if wait := c.MinInterval - c.Now().Sub(c.last); wait > 0 {
			c.Sleep(wait)
		}
	}
	c.last = c.Now()
}

func (c *Client) sign(req *http.Request, route string, payload []byte) {
	raw := make([]byte, 16)
	_, _ = rand.Read(raw)
	nonce := hex.EncodeToString(raw)
	ts := c.Now().Unix()

	req.Header.Set("Content-Type", "application/json")
	// Explicit: Go then does not decompress itself and we can count wire bytes.
	req.Header.Set("Accept-Encoding", "gzip")
	req.Header.Set("User-Agent", UserAgent())
	req.Header.Set("X-Wpsync-Key", c.KeyID)
	req.Header.Set("X-Wpsync-Timestamp", strconv.FormatInt(ts, 10))
	req.Header.Set("X-Wpsync-Nonce", nonce)
	req.Header.Set("X-Wpsync-Signature", Sign(c.Secret, Payload(http.MethodPost, route, ts, nonce, payload)))
}

func (c *Client) wrapBody(resp *http.Response, route string) (*http.Response, error) {
	counted := &countingReader{ReadCloser: resp.Body, n: &c.Stats.BytesIn}
	resp.Body = counted
	if resp.Header.Get("Content-Encoding") == "gzip" {
		gz, err := gzip.NewReader(counted)
		if err != nil {
			counted.Close()
			return nil, fmt.Errorf("gzip %s: %w", route, err)
		}
		resp.Body = &gzipBody{Reader: gz, raw: counted}
	}
	return resp, nil
}

func readAPIError(resp *http.Response) error {
	defer resp.Body.Close()
	if resp.StatusCode >= 300 && resp.StatusCode < 400 {
		return &APIError{Status: resp.StatusCode, Code: "redirect", Message: resp.Header.Get("Location")}
	}
	var body io.Reader = resp.Body
	if resp.Header.Get("Content-Encoding") == "gzip" {
		if gz, err := gzip.NewReader(resp.Body); err == nil {
			body = gz
		}
	}
	data, _ := io.ReadAll(io.LimitReader(body, 4000))
	var wpErr struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	}
	if json.Unmarshal(data, &wpErr) == nil && wpErr.Code != "" {
		return &APIError{Status: resp.StatusCode, Code: wpErr.Code, Message: wpErr.Message}
	}
	return &APIError{Status: resp.StatusCode, Message: strings.TrimSpace(string(data))}
}

func isConnectionFailure(err error) bool {
	var netErr net.Error
	return errors.Is(err, syscall.ECONNREFUSED) || errors.Is(err, syscall.ECONNRESET) ||
		errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) ||
		(errors.As(err, &netErr) && netErr.Timeout())
}

func retryAfter(resp *http.Response, fallback time.Duration) time.Duration {
	if s, err := strconv.Atoi(resp.Header.Get("Retry-After")); err == nil && s > 0 && s <= 300 {
		return time.Duration(s) * time.Second
	}
	return fallback
}

type countingReader struct {
	io.ReadCloser
	n *int64
}

func (r *countingReader) Read(p []byte) (int, error) {
	n, err := r.ReadCloser.Read(p)
	*r.n += int64(n)
	return n, err
}

type gzipBody struct {
	*gzip.Reader
	raw io.Closer
}

func (g *gzipBody) Close() error {
	g.Reader.Close()
	return g.raw.Close()
}
