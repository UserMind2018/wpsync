package agentapi

import (
	"bytes"
	"compress/gzip"
	"context"
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
var Version = "0.8.0"

// UserAgent is fixed and documented so admins can allow it explicitly (Spike B23).
func UserAgent() string { return "wpsync/" + Version }

// ErrSuspectedBan: the server stopped answering after earlier success. Never retried –
// retries extend fail2ban bans (Konzept E16, AC-26).
var ErrSuspectedBan = errors.New("server stopped responding after earlier success – suspected IP ban")

// ErrUnreachable: the agent could not be reached (DNS, connection, TLS) – exit code agent_unreachable.
var ErrUnreachable = errors.New("wpsync-Agent nicht erreichbar")

// APIError is a non-200 answer from the agent.
type APIError struct {
	Status  int
	Code    string
	Message string
	// Keys and Paths: details of a refusal of the content channel (code wpsync_content_…), from
	// the error data of the agent. Never values of rows.
	Keys  []ContentKey
	Paths []string
	// Total, StateBytes, Tables: what such a refusal names beyond that – how many keys there are (Keys
	// holds at most 200), the size of the rows a package meets (package_too_large) and the tables
	// that are not InnoDB (engine_unsupported).
	Total      int
	StateBytes int64
	Tables     []string
	// Plugins and Detail: details of a refusal of the plugin state (code wpsync_plugins_…): the units
	// it is about, and – without a rescue envelope – why there is none. Never a value of the option.
	Plugins []PluginRefusal
	Detail  string
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
	// Ctx aborts running requests and backoff waits (SIGTERM in the server mode); nil = never.
	Ctx context.Context

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
		req, err := http.NewRequestWithContext(c.ctx(), http.MethodPost, c.BaseURL+"/?rest_route="+route, bytes.NewReader(payload))
		if err != nil {
			return nil, err
		}
		c.sign(req, route, payload)

		c.Stats.Requests++
		resp, err := c.HTTP.Do(req)
		if err != nil {
			if ctxErr := c.ctx().Err(); ctxErr != nil {
				return nil, fmt.Errorf("request %s: %w", route, ctxErr)
			}
			if c.successes > 0 && isConnectionFailure(err) {
				return nil, fmt.Errorf("%w: %v", ErrSuspectedBan, err)
			}
			return nil, fmt.Errorf("%w: request %s: %w", ErrUnreachable, route, err)
		}
		if backoffStatus[resp.StatusCode] && attempt < maxBackoffs {
			wait := retryAfter(resp, time.Duration(10<<attempt)*time.Second)
			resp.Body.Close()
			if err := c.pause(wait); err != nil {
				return nil, fmt.Errorf("request %s: %w", route, err)
			}
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
	// After decompression: a small gzip answer of a hostile site can unpack to anything.
	limit := jsonLimit(route)
	if err := json.NewDecoder(&boundedReader{r: resp.Body, left: limit}).Decode(out); err != nil {
		if errors.Is(err, ErrAnswerTooLarge) {
			return fmt.Errorf("%s: %w (mehr als %d MiB)", route, ErrAnswerTooLarge, limit>>20)
		}
		return fmt.Errorf("decode %s: %w", route, err)
	}
	return nil
}

// MaxJSONBytes bounds one JSON answer of the agent, counted after decompression (Security-Review P4
// S5, NR-5): plans of small sets, lists, confirmations, status. json.Decoder keeps the whole value in
// memory, and the CLI also runs in containers with a few hundred MB – so the bound is small, and only
// the routes with large legitimate answers get more (jsonLimits). Files, tables and the content
// manifest are streamed by their own readers and are not bounded here.
var MaxJSONBytes int64 = 16 << 20

// jsonLimits are the routes whose answer may legitimately be larger than MaxJSONBytes:
//   - /delta names every file of the profile a page of the walk reaches within the agent's time
//     budget, roughly 150 bytes each – a fast server lists some hundred thousand in one page;
//   - /infosheet is the whole inventory (tables, plugins, themes, upload folders, findings);
//   - /push/begin and /push/commit name every file of the pushed units (need, conflicts, stamps),
//     about 100 bytes each.
var jsonLimits = map[string]int64{
	"/wpsync/v1/delta":       256 << 20,
	"/wpsync/v1/infosheet":   64 << 20,
	"/wpsync/v1/push/begin":  64 << 20,
	"/wpsync/v1/push/commit": 64 << 20,
}

// jsonLimit is the bound for the JSON answer of one route.
func jsonLimit(route string) int64 {
	if limit, ok := jsonLimits[route]; ok {
		return limit
	}
	return MaxJSONBytes
}

// smallAnswerBytes bounds the answers of the two unsigned requests, discover and pair.
const smallAnswerBytes = 1 << 20

// ErrAnswerTooLarge: a JSON answer of the site is larger than MaxJSONBytes. Nothing of it was used.
var ErrAnswerTooLarge = errors.New("die Antwort der Site ist grösser, als wpsync für diese Abfrage annimmt")

// boundedReader reads at most left bytes and fails beyond – it never ends quietly like
// io.LimitReader, which would let a cut answer pass as a complete one.
type boundedReader struct {
	r    io.Reader
	left int64
}

func (b *boundedReader) Read(p []byte) (int, error) {
	if b.left <= 0 {
		// Only a byte beyond the bound is too much: an answer of exactly the bound ends with EOF here.
		var one [1]byte
		if n, err := b.r.Read(one[:]); n == 0 {
			if err == nil {
				err = io.ErrNoProgress
			}
			return 0, err
		}
		return 0, ErrAnswerTooLarge
	}
	if int64(len(p)) > b.left {
		p = p[:b.left]
	}
	n, err := b.r.Read(p)
	b.left -= int64(n)
	return n, err
}

func (c *Client) ctx() context.Context {
	if c.Ctx == nil {
		return context.Background()
	}
	return c.Ctx
}

// pause waits for a backoff; with Ctx it ends early on cancellation.
func (c *Client) pause(d time.Duration) error {
	if c.Ctx == nil {
		c.Sleep(d)
		return nil
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-c.Ctx.Done():
		return c.Ctx.Err()
	case <-t.C:
		return nil
	}
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

// readAPIError reads a non-200 answer. Code and message come from the site; they are cleaned here,
// once for every caller, so no error output can steer the terminal (M3).
func readAPIError(resp *http.Response) error {
	defer resp.Body.Close()
	if resp.StatusCode >= 300 && resp.StatusCode < 400 {
		return &APIError{Status: resp.StatusCode, Code: "redirect", Message: CleanText(resp.Header.Get("Location"))}
	}
	var body io.Reader = resp.Body
	if resp.Header.Get("Content-Encoding") == "gzip" {
		if gz, err := gzip.NewReader(resp.Body); err == nil {
			body = gz
		}
	}
	// A refusal of the content channel names up to 200 keys; anything else stays short.
	data, _ := io.ReadAll(io.LimitReader(body, 1<<20))
	var wpErr struct {
		Code    string `json:"code"`
		Message string `json:"message"`
		Data    struct {
			Keys       []ContentKey    `json:"keys"`
			Paths      []string        `json:"paths"`
			Total      int             `json:"total"`
			StateBytes int64           `json:"state_bytes"`
			Tables     []string        `json:"tables"`
			Plugins    []PluginRefusal `json:"plugins"`
			Detail     string          `json:"detail"`
		} `json:"data"`
	}
	if json.Unmarshal(data, &wpErr) == nil && wpErr.Code != "" {
		e := &APIError{Status: resp.StatusCode, Code: CleanText(short(wpErr.Code)), Message: CleanText(short(wpErr.Message))}
		if strings.HasPrefix(e.Code, ContentCodePrefix) {
			e.Keys = CleanKeys(wpErr.Data.Keys)
			if e.Paths = wpErr.Data.Paths; len(e.Paths) > maxContentKeys {
				e.Paths = e.Paths[:maxContentKeys]
			}
			e.Total, e.StateBytes, e.Tables = cleanRefusalNumbers(wpErr.Data.Total, len(e.Keys), wpErr.Data.StateBytes, wpErr.Data.Tables)
		}
		if strings.HasPrefix(e.Code, PluginsCodePrefix) {
			e.Plugins = cleanRefusals(wpErr.Data.Plugins)
			if wpErr.Data.Detail != "" {
				if e.Detail = wpErr.Data.Detail; !stepRe.MatchString(e.Detail) {
					e.Detail = "unknown"
				}
			}
		}
		return e
	}
	return &APIError{Status: resp.StatusCode, Message: CleanText(short(strings.TrimSpace(string(data))))}
}

// short cuts text from the site to what an error message carries.
func short(s string) string {
	if len(s) > 4000 {
		return strings.ToValidUTF8(s[:4000], "")
	}
	return s
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
