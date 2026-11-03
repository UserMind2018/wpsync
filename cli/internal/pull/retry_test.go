package pull

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/safefs"
	"github.com/usermind/wpsync/internal/sites"
)

// cutServer answers /files with frames of "<path>" repeated to size. behave decides per request
// (call counts from 1): cut names a path whose body is cut after cutAfter bytes – the connection
// then drops with the announced Content-Length unmet; status != 0 answers with that HTTP status.
type cutServer struct {
	*httptest.Server
	mu       sync.Mutex
	requests [][]string
}

type behaviour struct {
	cut      string
	cutAfter int
	status   int
}

func newCutServer(t *testing.T, sizes map[string]int, behave func(call int, paths []string) behaviour) *cutServer {
	t.Helper()
	cs := &cutServer{}
	cs.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Paths []string `json:"paths"`
		}
		json.NewDecoder(r.Body).Decode(&req)
		cs.mu.Lock()
		cs.requests = append(cs.requests, req.Paths)
		call := len(cs.requests)
		cs.mu.Unlock()
		b := behaviour{}
		if behave != nil {
			b = behave(call, req.Paths)
		}
		if b.status != 0 {
			w.WriteHeader(b.status)
			fmt.Fprintf(w, `{"code":"wpsync_test","message":"status %d"}`, b.status)
			return
		}
		var full bytes.Buffer
		cutAt := -1
		for _, p := range req.Paths {
			fmt.Fprintf(&full, "F %s\t%d\t1700000000\n", p, sizes[p])
			if p == b.cut {
				cutAt = full.Len() + b.cutAfter
			}
			full.WriteString(strings.Repeat(string(filepath.Base(p)[0]), sizes[p]) + "\n")
		}
		full.WriteString("E\n")
		if cutAt < 0 {
			w.Write(full.Bytes())
			return
		}
		conn, buf, err := w.(http.Hijacker).Hijack()
		if err != nil {
			t.Errorf("hijack: %v", err)
			return
		}
		fmt.Fprintf(buf, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: %d\r\n\r\n", full.Len())
		buf.Write(full.Bytes()[:cutAt])
		buf.Flush()
		conn.Close()
	}))
	t.Cleanup(cs.Close)
	return cs
}

func (cs *cutServer) calls() []string {
	cs.mu.Lock()
	defer cs.mu.Unlock()
	var out []string
	for _, r := range cs.requests {
		out = append(out, strings.Join(r, ","))
	}
	return out
}

func files(sizes map[string]int, order ...string) []agentapi.File {
	var out []agentapi.File
	for _, p := range order {
		out = append(out, agentapi.File{Path: p, Size: int64(sizes[p]), MTime: 1700000000})
	}
	return out
}

// noTmp fails when a temporary file of an aborted write is left below docroot.
func noTmp(t *testing.T, docroot string) {
	t.Helper()
	filepath.WalkDir(docroot, func(path string, d fs.DirEntry, err error) error {
		if err == nil && strings.HasSuffix(path, safefs.TmpSuffix) {
			t.Errorf("half file left: %s", path)
		}
		return nil
	})
}

func readFile(t *testing.T, docroot, rel string) string {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(docroot, rel))
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

var twoFiles = map[string]int{"wp-content/uploads/a.jpg": 5000, "wp-content/uploads/b.jpg": 5000}

// U-40: a connection cut in the middle of a body is repeated; the file finished before it is kept.
func TestDownloadRetriesABundleCutInTheMiddle(t *testing.T) {
	srv := newCutServer(t, twoFiles, func(call int, _ []string) behaviour {
		if call == 1 {
			return behaviour{cut: "wp-content/uploads/b.jpg", cutAfter: 1234}
		}
		return behaviour{}
	})
	docroot := t.TempDir() + "/public"
	var out bytes.Buffer
	var retries []Retry
	opts := DownloadOptions{BundleBytes: 1 << 20, Retries: 3, Out: &out, OnRetry: func(r Retry) { retries = append(retries, r) }}

	if _, err := DownloadFiles(quickClient(srv.URL), docroot, files(twoFiles, "wp-content/uploads/a.jpg", "wp-content/uploads/b.jpg"), opts); err != nil {
		t.Fatalf("DownloadFiles: %v\n%s", err, &out)
	}
	if got := strings.Join(srv.calls(), " | "); got != "wp-content/uploads/a.jpg,wp-content/uploads/b.jpg | wp-content/uploads/b.jpg" {
		t.Errorf("requests = %s – the second asks only for the file that is missing", got)
	}
	if a, b := readFile(t, docroot, "wp-content/uploads/a.jpg"), readFile(t, docroot, "wp-content/uploads/b.jpg"); len(a) != 5000 || len(b) != 5000 || a[0] != 'a' || b[0] != 'b' {
		t.Errorf("content: a %d bytes, b %d bytes", len(a), len(b))
	}
	noTmp(t, docroot)
	if len(retries) != 1 || retries[0].Attempt != 1 || retries[0].Max != 3 || retries[0].Files != 1 || retries[0].Path != "wp-content/uploads/b.jpg" || retries[0].WaitMS <= 0 || retries[0].Reason == "" {
		t.Errorf("retries = %+v", retries)
	}
	if !strings.Contains(out.String(), "Wiederholung 1/3") {
		t.Errorf("output lacks the repetition:\n%s", &out)
	}
}

// A cut that does not end: a clear error after the repetitions, no half file, a wrapped cause.
func TestDownloadGivesUpAfterTheRetries(t *testing.T) {
	sizes := map[string]int{"wp-content/uploads/big.jpg": 5000}
	srv := newCutServer(t, sizes, func(int, []string) behaviour {
		return behaviour{cut: "wp-content/uploads/big.jpg", cutAfter: 3000}
	})
	docroot := t.TempDir() + "/public"
	var out bytes.Buffer
	opts := DownloadOptions{BundleBytes: 1 << 20, Retries: 3, Out: &out}

	_, err := DownloadFiles(quickClient(srv.URL), docroot, files(sizes, "wp-content/uploads/big.jpg"), opts)

	if err == nil {
		t.Fatal("a file that is always cut must fail")
	}
	for _, want := range []string{"wp-content/uploads/big.jpg", "nach 3 Wiederholungen aufgegeben", "unexpected EOF"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error %q lacks %q", err, want)
		}
	}
	if !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Errorf("the cause must stay in the chain: %v", err)
	}
	if n := len(srv.calls()); n != 4 {
		t.Errorf("%d requests, want the first and 3 repetitions", n)
	}
	if _, err := os.Stat(filepath.Join(docroot, "wp-content/uploads/big.jpg")); !os.IsNotExist(err) {
		t.Errorf("half file stands: %v", err)
	}
	noTmp(t, docroot)
}

// --retries 0 keeps the old behaviour: the first cut ends the download.
func TestDownloadWithoutRetries(t *testing.T) {
	sizes := map[string]int{"wp-content/uploads/a.jpg": 100}
	srv := newCutServer(t, sizes, func(int, []string) behaviour { return behaviour{cut: "wp-content/uploads/a.jpg", cutAfter: 10} })
	_, err := DownloadFiles(quickClient(srv.URL), t.TempDir()+"/public", files(sizes, "wp-content/uploads/a.jpg"), DownloadOptions{BundleBytes: 1 << 20, Out: io.Discard})
	if err == nil || len(srv.calls()) != 1 || strings.Contains(err.Error(), "Wiederholung") {
		t.Fatalf("err = %v after %d requests", err, len(srv.calls()))
	}
}

// A bundle cut again after its repetition is split: the cut file travels alone, the others are no longer hostage.
func TestDownloadSplitsABundleThatKeepsBreaking(t *testing.T) {
	sizes := map[string]int{"wp-content/a": 100, "wp-content/b": 100, "wp-content/c": 100}
	srv := newCutServer(t, sizes, func(call int, paths []string) behaviour {
		if len(paths) > 1 {
			return behaviour{cut: paths[0], cutAfter: 50} // only a bundle breaks, never a single file
		}
		return behaviour{}
	})
	docroot := t.TempDir() + "/public"
	var out bytes.Buffer

	if _, err := DownloadFiles(quickClient(srv.URL), docroot, files(sizes, "wp-content/a", "wp-content/b", "wp-content/c"), DownloadOptions{BundleBytes: 1 << 20, Retries: 3, Out: &out}); err != nil {
		t.Fatalf("DownloadFiles: %v\n%s", err, &out)
	}
	want := "wp-content/a,wp-content/b,wp-content/c | wp-content/a,wp-content/b,wp-content/c | wp-content/a | wp-content/b | wp-content/c"
	if got := strings.Join(srv.calls(), " | "); got != want {
		t.Errorf("requests = %s\nwant      %s", got, want)
	}
	for _, f := range []string{"a", "b", "c"} {
		if len(readFile(t, docroot, "wp-content/"+f)) != 100 {
			t.Errorf("file %s incomplete", f)
		}
	}
	if !strings.Contains(out.String(), "einzeln geholt") {
		t.Errorf("the split is not announced:\n%s", &out)
	}
	noTmp(t, docroot)
}

// A repetition that brings files in resets the count: three cuts at three different files are no failure.
func TestDownloadProgressResetsTheRetryCount(t *testing.T) {
	sizes := map[string]int{"wp-content/a": 100, "wp-content/b": 100, "wp-content/c": 100, "wp-content/d": 100}
	order := []string{"wp-content/a", "wp-content/b", "wp-content/c", "wp-content/d"}
	srv := newCutServer(t, sizes, func(call int, paths []string) behaviour {
		if call <= 3 {
			return behaviour{cut: paths[1%len(paths)], cutAfter: 10} // each request delivers the first file, then breaks
		}
		return behaviour{}
	})
	docroot := t.TempDir() + "/public"
	if _, err := DownloadFiles(quickClient(srv.URL), docroot, files(sizes, order...), DownloadOptions{BundleBytes: 1 << 20, Retries: 1, Out: io.Discard}); err != nil {
		t.Fatalf("DownloadFiles: %v", err)
	}
	for _, p := range order {
		if len(readFile(t, docroot, p)) != 100 {
			t.Errorf("%s incomplete", p)
		}
	}
}

// 502/503/504 are transient (after the client's own backoff), a 403 is final.
func TestDownloadRetriesGatewayErrorsButNotRefusals(t *testing.T) {
	sizes := map[string]int{"wp-content/a": 10}
	// The client itself backs off 3 times per request; the 5th request is the first repetition of ours.
	srv := newCutServer(t, sizes, func(call int, _ []string) behaviour {
		if call <= 4 {
			return behaviour{status: 503}
		}
		return behaviour{}
	})
	docroot := t.TempDir() + "/public"
	if _, err := DownloadFiles(quickClient(srv.URL), docroot, files(sizes, "wp-content/a"), DownloadOptions{BundleBytes: 1 << 20, Retries: 3, Out: io.Discard}); err != nil {
		t.Fatalf("503: %v", err)
	}
	if len(srv.calls()) != 5 || len(readFile(t, docroot, "wp-content/a")) != 10 {
		t.Errorf("calls = %v", srv.calls())
	}

	forbidden := newCutServer(t, sizes, func(int, []string) behaviour { return behaviour{status: 403} })
	_, err := DownloadFiles(quickClient(forbidden.URL), t.TempDir()+"/public", files(sizes, "wp-content/a"), DownloadOptions{BundleBytes: 1 << 20, Retries: 3, Out: io.Discard})
	var api *agentapi.APIError
	if !errors.As(err, &api) || api.Status != 403 || len(forbidden.calls()) != 1 {
		t.Fatalf("403: err = %v after %d requests", err, len(forbidden.calls()))
	}
}

func TestIsTransient(t *testing.T) {
	timeout := &net_timeout{}
	for _, tc := range []struct {
		name string
		err  error
		want bool
	}{
		{"unexpected EOF", fmt.Errorf("write x: 1/2 bytes: %w", io.ErrUnexpectedEOF), true},
		{"truncated stream", fmt.Errorf("stream truncated: %w", io.EOF), true},
		{"short body", fmt.Errorf("write x: 1/2 bytes: %w", safefs.ErrShortBody), true},
		{"reset", fmt.Errorf("read: %w", syscall.ECONNRESET), true},
		{"timeout", fmt.Errorf("read: %w", timeout), true},
		{"deadline", fmt.Errorf("read: %w", context.DeadlineExceeded), true},
		{"502", &agentapi.APIError{Status: 502}, true},
		{"503", fmt.Errorf("x: %w", &agentapi.APIError{Status: 503}), true},
		{"504", &agentapi.APIError{Status: 504}, true},
		{"403", &agentapi.APIError{Status: 403}, false},
		{"429", &agentapi.APIError{Status: 429}, false},
		{"suspected ban", fmt.Errorf("%w: %w", agentapi.ErrSuspectedBan, io.ErrUnexpectedEOF), false},
		{"unreachable", fmt.Errorf("%w: %w", agentapi.ErrUnreachable, syscall.ECONNRESET), false},
		{"cancelled", context.Canceled, false},
		{"disk full", fmt.Errorf("write: %w", syscall.ENOSPC), false},
		{"forbidden path", errors.New(`refusing path outside wp-content: "x"`), false},
		{"checksum", errors.New("gzip: invalid checksum"), false},
		{"nil", nil, false},
	} {
		if got := isTransient(tc.err); got != tc.want {
			t.Errorf("%s: isTransient = %v", tc.name, got)
		}
	}
}

type net_timeout struct{}

func (net_timeout) Error() string   { return "i/o timeout" }
func (net_timeout) Timeout() bool   { return true }
func (net_timeout) Temporary() bool { return true }

// --- Run: nothing is lost by an abort ---

// resumeServer is a site whose files change between two pulls (version) and whose /files can be made to
// cut a file for good.
type resumeServer struct {
	*httptest.Server
	mu      sync.Mutex
	version int
	cutPath string
	fetched []string // paths asked for via /files
}

const resumeFileSize = 3000

func newResumeServer(t *testing.T) *resumeServer {
	t.Helper()
	rs := &resumeServer{version: 1}
	rs.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Paths []string `json:"paths"`
		}
		json.NewDecoder(r.Body).Decode(&req)
		rs.mu.Lock()
		version, cutPath := rs.version, rs.cutPath
		rs.mu.Unlock()
		mtime := 1700000000 + version
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/infosheet":
			w.Write([]byte(`{"sheet":{"env":{"table_prefix":"wp_"},"tables":[{"name":"wp_options","class":"config","essential":true}],
"post_types":[],"plugins":[],"themes":[],"uploads":[],"findings":[],"orphan_meta":{}},"job":{}}`))
		case "/wpsync/v1/delta":
			fmt.Fprintf(w, `{"env":{"php_version":"8.3.35","wp_version":"6.8.1","table_prefix":"wp_","home":"https://kunde.de","siteurl":"https://kunde.de","agent_version":"0.10.0","anon":"1.abcd1234"},
"tables":[{"name":"wp_options","checksum":"c1","rows":1,"bytes":40}],
"files":[{"path":"wp-content/uploads/one.jpg","size":%[1]d,"mtime":%[2]d},{"path":"wp-content/uploads/two.jpg","size":%[1]d,"mtime":%[2]d},{"path":"wp-content/uploads/three.jpg","size":%[1]d,"mtime":%[2]d}],"skipped":[],"next":null}`, resumeFileSize, mtime)
		case "/wpsync/v1/files":
			rs.mu.Lock()
			rs.fetched = append(rs.fetched, req.Paths...)
			rs.mu.Unlock()
			var full bytes.Buffer
			cutAt := -1
			for _, p := range req.Paths {
				fmt.Fprintf(&full, "F %s\t%d\t%d\n", p, resumeFileSize, mtime)
				if strings.HasSuffix(p, cutPath) && cutPath != "" {
					cutAt = full.Len() + 100
				}
				full.WriteString(strings.Repeat("x", resumeFileSize) + "\n")
			}
			full.WriteString("E\n")
			if cutAt < 0 {
				w.Write(full.Bytes())
				return
			}
			conn, buf, _ := w.(http.Hijacker).Hijack()
			fmt.Fprintf(buf, "HTTP/1.1 200 OK\r\nContent-Length: %d\r\n\r\n", full.Len())
			buf.Write(full.Bytes()[:cutAt])
			buf.Flush()
			conn.Close()
		case "/wpsync/v1/db-bundle":
			fmt.Fprintf(w, "T wp_options\t1\t%d\n%s\nE\n", len(tableSQL), tableSQL)
		default:
			t.Errorf("unexpected request %s", r.URL.Query().Get("rest_route"))
			http.Error(w, "unexpected", http.StatusNotFound)
		}
	}))
	t.Cleanup(rs.Close)
	return rs
}

func (rs *resumeServer) set(version int, cutPath string) {
	rs.mu.Lock()
	defer rs.mu.Unlock()
	rs.version, rs.cutPath, rs.fetched = version, cutPath, nil
}

func resumeOptions(t *testing.T, url string) (Options, *bytes.Buffer) {
	t.Helper()
	sheet := &agentapi.Infosheet{Tables: []agentapi.TableInfo{{Name: "wp_options", Class: "config", Essential: true}}}
	prof, err := profile.New(sheet, profile.PresetFull)
	if err != nil {
		t.Fatal(err)
	}
	out := &bytes.Buffer{}
	return Options{
		Site:            sites.Site{Name: "kunde", URL: url, KeyID: "0123456789abcdef", RPS: 1000, Profile: prof},
		Secret:          "secret",
		SitesRoot:       t.TempDir(),
		Driver:          newFakeDriver(false),
		Out:             out,
		RowsPerChunk:    2000,
		FileBundleBytes: 1 << 20,
		DBBundleBytes:   8 << 20,
		FileRetries:     2,
		Sleep:           func(time.Duration) {},
	}, out
}

// U-40: a first pull that dies on a file for good – the next pull asks for that file only.
func TestRunAfterAnAbortedFirstPullFetchesOnlyTheMissingFiles(t *testing.T) {
	rs := newResumeServer(t)
	rs.set(1, "three.jpg")
	o, out := resumeOptions(t, rs.URL)

	err := Run(o)
	if err == nil || !strings.Contains(err.Error(), "nach 2 Wiederholungen aufgegeben") {
		t.Fatalf("Run: %v\n%s", err, out)
	}
	docroot := filepath.Join(o.SitesRoot, "kunde", "public")
	noTmp(t, docroot)

	rs.set(1, "")
	out.Reset()
	if err := Run(o); err != nil {
		t.Fatalf("second Run: %v\n%s", err, out)
	}
	if !strings.Contains(out.String(), "Dateien: 1 neu/geändert") {
		t.Errorf("the second pull must count only the missing file:\n%s", out)
	}
	if len(rs.fetched) != 1 || !strings.HasSuffix(rs.fetched[0], "three.jpg") {
		t.Errorf("fetched = %v", rs.fetched)
	}
}

// U-40: the same after a pull that has a baseline – files changed on the site, the pull breaks on the
// last one; the second pull fetches that one only (before the fix: all that had changed).
func TestRunAfterAnAbortedFollowUpPullFetchesOnlyTheMissingFiles(t *testing.T) {
	rs := newResumeServer(t)
	rs.set(1, "")
	o, out := resumeOptions(t, rs.URL)
	if err := Run(o); err != nil {
		t.Fatalf("first Run: %v\n%s", err, out)
	}

	rs.set(2, "three.jpg") // all three files changed; the pull breaks on the third
	out.Reset()
	if err := Run(o); err == nil {
		t.Fatalf("Run must fail\n%s", out)
	}

	rs.set(2, "")
	out.Reset()
	if err := Run(o); err != nil {
		t.Fatalf("third Run: %v\n%s", err, out)
	}
	if !strings.Contains(out.String(), "Dateien: 1 neu/geändert") {
		t.Errorf("the pull after the abort must count only the missing file:\n%s", out)
	}
	if len(rs.fetched) != 1 || !strings.HasSuffix(rs.fetched[0], "three.jpg") {
		t.Errorf("fetched = %v", rs.fetched)
	}
}

// pull --json: a repetition is a line of its own.
func TestRunReportsRetriesAsProgress(t *testing.T) {
	rs := newResumeServer(t)
	rs.set(1, "two.jpg")
	o, _ := resumeOptions(t, rs.URL)
	var retries []Retry
	o.Progress = func(p Progress) {
		if p.Retry != nil {
			retries = append(retries, *p.Retry)
		}
	}
	_ = Run(o)
	if len(retries) == 0 || retries[0].Phase != PhaseFiles || retries[0].Attempt != 1 || retries[0].Max != 2 {
		t.Fatalf("retries = %+v", retries)
	}
}

var _ localenv.Driver = (*fakeDriver)(nil)
