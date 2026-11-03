package agentapi

import (
	"bytes"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func snapshotClient(url string) *Client {
	c := New(url, "0123456789abcdef", "secret", 1000)
	c.Sleep = func(time.Duration) {}
	return c
}

func TestSnapshotBlocksChecksHeadersAndBoundsTheBody(t *testing.T) {
	old := SnapshotBlockSize
	SnapshotBlockSize = 4
	defer func() { SnapshotBlockSize = old }()
	var body []byte
	from, blocks := "2", "2"
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if got := r.URL.Query().Get("rest_route"); got != "/wpsync/v1/db-snapshot/blocks" {
			t.Errorf("route %s", got)
		}
		var req map[string]any
		_ = json.NewDecoder(r.Body).Decode(&req)
		if req["id"] != "abc" || req["from"] != float64(2) || req["count"] != float64(2) {
			t.Errorf("request %v", req)
		}
		w.Header().Set("X-Wpsync-Block-From", from)
		w.Header().Set("X-Wpsync-Blocks", blocks)
		w.Write(body)
	}))
	defer srv.Close()
	c := snapshotClient(srv.URL)

	body = []byte("12345678")
	var out bytes.Buffer
	n, err := c.SnapshotBlocks("abc", 2, 2, &out)
	if err != nil || n != 2 || out.String() != "12345678" {
		t.Fatalf("n=%d err=%v out=%q", n, err, out.String())
	}

	body = []byte("123456789") // one byte more than 2 blocks
	if _, err := c.SnapshotBlocks("abc", 2, 2, &bytes.Buffer{}); !errors.Is(err, ErrAnswerTooLarge) {
		t.Fatalf("want ErrAnswerTooLarge, got %v", err)
	}

	from = "3"
	if _, err := c.SnapshotBlocks("abc", 2, 2, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "block headers") {
		t.Fatalf("want header error, got %v", err)
	}
}

func TestSnapshotStatusDecodes(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"id":"abc","state":"done","tables_done":2,"tables_total":2,"blocks":1,"block_size":1048576,"size":10,"sha256":"` + strings.Repeat("a", 64) + `","bytes_plain":99,"rows":{"wp_posts":5},"inconsistent":["wp_old"],"code":"","error":"","seconds":1.5}`))
	}))
	defer srv.Close()
	st, err := snapshotClient(srv.URL).SnapshotStatus("abc")
	if err != nil {
		t.Fatal(err)
	}
	if st.State != SnapshotDone || st.Rows["wp_posts"] != 5 || st.Inconsistent[0] != "wp_old" || st.BytesPlain != 99 {
		t.Fatalf("status %+v", st)
	}
}
