package agentapi

import (
	"bytes"
	"compress/gzip"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

const testKey, testSecret = "0123456789abcdef", "s3cret-s3cret-s3cret-s3cret-s3cret"

func verify(t *testing.T, r *http.Request) {
	t.Helper()
	body, _ := io.ReadAll(r.Body)
	r.Body = io.NopCloser(bytes.NewReader(body))
	ts, _ := strconv.ParseInt(r.Header.Get("X-Wpsync-Timestamp"), 10, 64)
	route := r.URL.Query().Get("rest_route")
	want := Sign(testSecret, Payload(r.Method, route, ts, r.Header.Get("X-Wpsync-Nonce"), body))
	if r.Header.Get("X-Wpsync-Signature") != want || r.Header.Get("X-Wpsync-Key") != testKey {
		t.Errorf("bad signature for %s", route)
	}
	if !strings.HasPrefix(r.Header.Get("User-Agent"), "wpsync/") {
		t.Errorf("User-Agent = %q", r.Header.Get("User-Agent"))
	}
}

func newTestClient(url string) (*Client, *[]time.Duration) {
	c := New(url, testKey, testSecret, 1)
	var sleeps []time.Duration
	c.Sleep = func(d time.Duration) { sleeps = append(sleeps, d) }
	return c, &sleeps
}

func TestPostSignsAndDecodesJSON(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		w.Write([]byte(`{"wp_version":"7.1.2"}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	env, err := c.Ping()
	if err != nil || env.WPVersion != "7.1.2" {
		t.Fatalf("env = %+v, err = %v", env, err)
	}
}

func TestThrottleWaitsBetweenRequests(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{}`)) }))
	defer srv.Close()
	c, sleeps := newTestClient(srv.URL)
	now := time.Unix(1000, 0)
	c.Now = func() time.Time { return now }
	c.Ping()
	c.Ping()
	if len(*sleeps) != 1 || (*sleeps)[0] != time.Second {
		t.Fatalf("sleeps = %v, want [1s]", *sleeps)
	}
}

func TestBackoffOn503ThenSuccess(t *testing.T) {
	calls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if calls < 3 {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()
	c, sleeps := newTestClient(srv.URL)
	if _, err := c.Ping(); err != nil {
		t.Fatal(err)
	}
	backoffs := 0
	for _, d := range *sleeps {
		if d >= 10*time.Second {
			backoffs++
		}
	}
	if backoffs != 2 {
		t.Fatalf("sleeps = %v, want two backoffs", *sleeps)
	}
}

func TestSuspectedBanAfterSuccess(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{}`)) }))
	c, _ := newTestClient(srv.URL)
	if _, err := c.Ping(); err != nil {
		t.Fatal(err)
	}
	srv.Close()
	_, err := c.Ping()
	if !errors.Is(err, ErrSuspectedBan) {
		t.Fatalf("err = %v, want ErrSuspectedBan", err)
	}
	if c.Stats.Requests != 2 {
		t.Fatalf("no retry allowed, requests = %d", c.Stats.Requests)
	}
}

func TestAPIErrorFromWordPressJSON(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		w.Write([]byte(`{"code":"wpsync_unpaired","message":"unknown or revoked pairing"}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	_, err := c.Ping()
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Code != "wpsync_unpaired" || apiErr.Status != 401 {
		t.Fatalf("err = %v", err)
	}
}

func TestRedirectIsNotFollowed(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "https://www.example.invalid/", http.StatusMovedPermanently)
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	_, err := c.Ping()
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Code != "redirect" {
		t.Fatalf("err = %v, want redirect APIError", err)
	}
}

func TestGzipCountsWireBytes(t *testing.T) {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	gz.Write([]byte(strings.Repeat("INSERT;", 1000)))
	gz.Close()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Accept-Encoding") != "gzip" {
			t.Error("client must request gzip")
		}
		w.Header().Set("Content-Encoding", "gzip")
		w.Header().Set("X-Wpsync-Rows", "7")
		w.Header().Set("X-Wpsync-Mode", "keyset")
		w.Header().Set("X-Wpsync-Next", base64.StdEncoding.EncodeToString([]byte("42")))
		w.Write(buf.Bytes())
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	var out bytes.Buffer
	res, err := c.DBChunk(ChunkRequest{Table: "wp_posts", Limit: 2000}, &out)
	if err != nil {
		t.Fatal(err)
	}
	if out.Len() != 7000 || res.Rows != 7 || res.Mode != "keyset" || res.Next == nil || *res.Next != "42" {
		t.Fatalf("out=%d res=%+v", out.Len(), res)
	}
	if c.Stats.BytesIn != int64(buf.Len()) {
		t.Fatalf("BytesIn = %d, want compressed size %d", c.Stats.BytesIn, buf.Len())
	}
}

func TestDeltaFollowsCursor(t *testing.T) {
	page := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		page++
		if page == 1 {
			w.Write([]byte(`{"env":{"wp_version":"7"},"tables":[{"name":"wp_posts","checksum":"1"}],"files":[],"skipped":[],"next":"{\"phase\":\"files\",\"i\":0}"}`))
			return
		}
		w.Write([]byte(`{"tables":[],"files":[{"path":"wp-content/a","size":1,"mtime":2}],"skipped":[{"path":"wp-content/big","size":9}],"next":null}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	d, err := c.Delta(Scope{})
	if err != nil {
		t.Fatal(err)
	}
	if d.Env.WPVersion != "7" || len(d.Tables) != 1 || len(d.Files) != 1 || len(d.Skipped) != 1 || page != 2 {
		t.Fatalf("delta = %+v, pages = %d", d, page)
	}
}

func TestDeltaSendsScope(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Scope map[string]any `json:"scope"`
		}
		json.NewDecoder(r.Body).Decode(&body)
		tables, _ := body.Scope["tables"].(map[string]any)
		if body.Scope["uploads_since"] != "2025" || tables["wp_log"] != "structure" {
			t.Errorf("scope = %v", body.Scope)
		}
		w.Write([]byte(`{"tables":[],"files":[],"skipped":[],"next":null}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	if _, err := c.Delta(Scope{Tables: map[string]string{"wp_log": "structure"}, UploadsSince: "2025"}); err != nil {
		t.Fatal(err)
	}
}

func TestDiscoverFollowsRedirectToCanonicalURL(t *testing.T) {
	var canonical *httptest.Server
	canonical = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"namespace":"wpsync/v1","routes":{}}`))
	}))
	defer canonical.Close()
	redirecting := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, canonical.URL+r.URL.RequestURI(), http.StatusMovedPermanently)
	}))
	defer redirecting.Close()

	base, err := Discover(http.DefaultClient, redirecting.URL)
	if err != nil || base != canonical.URL {
		t.Fatalf("base = %q, err = %v, want %q", base, err, canonical.URL)
	}
}

const sheetJSON = `{"sheet":{"generated_at":1700000000,"duration":12.5,"steps":4,
"env":{"wp_version":"6.8.2","table_prefix":"wp_"},
"plugins":[{"slug":"elementor","name":"Elementor","version":"3.30.0","active":true,"files":10,"bytes":100}],
"themes":[{"slug":"astra","name":"Astra","version":"4.0.0","active":true,"files":3,"bytes":30}],
"tables":[{"name":"wp_posts","rows":3,"bytes":100,"class":"content","plugin":"core","essential":true},
          {"name":"wp_x","rows":1,"bytes":5,"class":"unknown","plugin":null,"essential":false}],
"post_types":[{"name":"revision","count":2,"bytes":10,"meta_rows":1,"meta_bytes":5,"class":"log"}],
"orphan_meta":{"rows":2,"bytes":20},
"uploads":[{"year":"2024","files":1,"bytes":9}],
"findings":[{"kind":"backup_dir","path":"wp-content/backups-dup-pro","bytes":7}]},
"job":{"running":false,"phase":"done","done":0,"total":0,"generated_at":1700000000}}`

func TestInfosheet(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		if route := r.URL.Query().Get("rest_route"); route != "/wpsync/v1/infosheet" {
			t.Errorf("route = %s", route)
		}
		w.Write([]byte(sheetJSON))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	sheet, job, err := c.Infosheet()
	if err != nil {
		t.Fatal(err)
	}
	if sheet == nil || sheet.GeneratedAt != 1700000000 || sheet.Env.TablePrefix != "wp_" || !sheet.Tables[0].Essential ||
		sheet.Tables[1].Plugin != "" || sheet.PostTypes[0].Class != "log" || sheet.OrphanMeta.Rows != 2 ||
		sheet.Uploads[0].Year != "2024" || sheet.Findings[0].Kind != "backup_dir" || sheet.Plugins[0].Bytes != 100 {
		t.Fatalf("sheet = %+v", sheet)
	}
	if job.Running || job.GeneratedAt != 1700000000 {
		t.Fatalf("job = %+v", job)
	}
}

func TestInfosheetMissing(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"sheet":null,"job":{"running":true,"phase":"posts","done":20000,"total":50000,"generated_at":0}}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	sheet, job, err := c.Infosheet()
	if err != nil || sheet != nil || !job.Running || job.Phase != "posts" || job.Total != 50000 {
		t.Fatalf("sheet = %+v, job = %+v, err = %v", sheet, job, err)
	}
}

func TestRefreshInfosheetSendsStart(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		var body map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		if r.URL.Query().Get("rest_route") != "/wpsync/v1/infosheet/refresh" || body["start"] != true {
			t.Errorf("route = %s, body = %v", r.URL.Query().Get("rest_route"), body)
		}
		w.Write([]byte(`{"running":true,"phase":"meta","done":0,"total":0,"generated_at":0}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	st, err := c.RefreshInfosheet(true)
	if err != nil || !st.Running || st.Phase != "meta" {
		t.Fatalf("status = %+v, err = %v", st, err)
	}
}
