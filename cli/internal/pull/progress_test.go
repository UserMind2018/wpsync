package pull

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strconv"
	"testing"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

// stepClock advances by step on every reading.
func stepClock(step time.Duration) func() time.Time {
	now := time.Unix(1700000000, 0)
	return func() time.Time {
		now = now.Add(step)
		return now
	}
}

// chunkServer serves wp_small ("SMALL;") as a bundle and wp_large in chunks of "PARTn;" (6 bytes
// each): chunks-1 full ones with 2 rows and a last one with 1 row. onChunk runs before chunk n.
func chunkServer(t *testing.T, chunks int, onChunk func(n int)) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/db-bundle":
			w.Write([]byte("T wp_small\t1\t6\nSMALL;\nE\n"))
		case "/wpsync/v1/db":
			n := 1
			if after, ok := body["after"].(string); ok {
				n, _ = strconv.Atoi(after)
			}
			if onChunk != nil {
				onChunk(n)
			}
			w.Header().Set("X-Wpsync-Mode", "keyset")
			if n < chunks {
				w.Header().Set("X-Wpsync-Rows", "2")
				w.Header().Set("X-Wpsync-Next", base64.StdEncoding.EncodeToString([]byte(strconv.Itoa(n+1))))
			} else {
				w.Header().Set("X-Wpsync-Rows", "1")
			}
			fmt.Fprintf(w, "PART%d;", n)
		}
	}))
}

var chunkTables = []agentapi.Table{
	{Name: "wp_small", Checksum: sp("1"), Rows: 1, Bytes: 100},
	{Name: "wp_large", Checksum: sp("2"), Rows: 5000, Bytes: 1000},
}

func collect(events *[]DBProgress) func(DBProgress) {
	return func(p DBProgress) { *events = append(*events, p) }
}

// W2: eine Tabelle in Chunks meldet sich je Chunk – der Tabellenzähler bleibt, die Bytes wachsen.
// bytes_total ist die Schätzung der Site, bytes_done das, was wirklich ankam: sie treffen sich nicht.
func TestDownloadTablesReportsBytesPerChunk(t *testing.T) {
	srv := chunkServer(t, 3, nil)
	defer srv.Close()
	var events []DBProgress
	opts := DBOptions{RowsPerChunk: 2, BundleBytes: 1 << 20, Progress: collect(&events), Now: stepClock(time.Second)}
	if err := DownloadTables(quickClient(srv.URL), t.TempDir(), chunkTables, opts); err != nil {
		t.Fatal(err)
	}
	want := []DBProgress{
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 6, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 12, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 18, BytesTotal: 1100},
		{Done: 2, Total: 2, Table: "wp_large", BytesDone: 24, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v\nwant     %+v", events, want)
	}
}

// W2: höchstens ein Zwischen-Event je Sekunde; der Abschluss einer Tabelle kommt immer.
func TestDownloadTablesThrottlesChunkEvents(t *testing.T) {
	srv := chunkServer(t, 6, nil)
	defer srv.Close()
	var events []DBProgress
	opts := DBOptions{RowsPerChunk: 2, BundleBytes: 1 << 20, Progress: collect(&events), Now: stepClock(400 * time.Millisecond)}
	if err := DownloadTables(quickClient(srv.URL), t.TempDir(), chunkTables, opts); err != nil {
		t.Fatal(err)
	}
	// Clock: wp_small done at 0.4 s, chunks 1–5 at 0.8 … 2.4 s – only chunk 3 (1.6 s) is a full
	// second after the last event.
	want := []DBProgress{
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 6, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 24, BytesTotal: 1100},
		{Done: 2, Total: 2, Table: "wp_large", BytesDone: 42, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v\nwant     %+v", events, want)
	}
}

// W2: ohne vorheriges Event kommt das erste Zwischen-Event sofort.
func TestDownloadTablesFirstChunkEventComesAtOnce(t *testing.T) {
	srv := chunkServer(t, 3, nil)
	defer srv.Close()
	var events []DBProgress
	clock := time.Unix(1700000000, 0)
	opts := DBOptions{RowsPerChunk: 2, BundleBytes: 1 << 20, Progress: collect(&events), Now: func() time.Time { return clock }}
	if err := DownloadTables(quickClient(srv.URL), t.TempDir(), chunkTables[1:], opts); err != nil {
		t.Fatal(err)
	}
	want := []DBProgress{
		{Done: 0, Total: 1, Table: "wp_large", BytesDone: 6, BytesTotal: 1000},
		{Done: 1, Total: 1, Table: "wp_large", BytesDone: 18, BytesTotal: 1000},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v\nwant     %+v", events, want)
	}
}

// W2, Resume: ein abgebrochener Download zählt beim nächsten Lauf die schon vorhandene Tabelle in
// bytes_total (Schätzung) UND in bytes_done (Grösse ihrer Datei im Cache); die halb geladene
// Tabelle fängt bei null an.
func TestDownloadTablesResumeCountsFinishedTablesInBoth(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	srv := chunkServer(t, 3, func(n int) {
		if n == 2 {
			cancel()
		}
	})
	defer srv.Close()
	dir := t.TempDir()
	var events []DBProgress
	opts := DBOptions{RowsPerChunk: 2, BundleBytes: 1 << 20, Progress: collect(&events), Now: stepClock(time.Second)}
	c := quickClient(srv.URL)
	c.Ctx = ctx
	if err := DownloadTables(c, dir, chunkTables, opts); err == nil {
		t.Fatal("the cancelled download must fail")
	}
	want := []DBProgress{
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 6, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 12, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("first run = %+v\nwant       %+v", events, want)
	}

	srv2 := chunkServer(t, 3, nil)
	defer srv2.Close()
	events = nil
	opts.Now = stepClock(time.Second)
	if err := DownloadTables(quickClient(srv2.URL), dir, chunkTables, opts); err != nil {
		t.Fatal(err)
	}
	want = []DBProgress{
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 6, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 12, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 18, BytesTotal: 1100},
		{Done: 2, Total: 2, Table: "wp_large", BytesDone: 24, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("resumed run = %+v\nwant          %+v", events, want)
	}
}

// W2: Struktur-Tabellen zählen mit 0 in bytes_total (wie in transferBytes), ihr CREATE TABLE aber
// in bytes_done – bytes_done wird nicht auf bytes_total begrenzt.
func TestDownloadTablesStructureOnlyHasNoByteTotal(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("T wp_log\t0\t7\nCREATE;\nE\n"))
	}))
	defer srv.Close()
	var events []DBProgress
	tables := []agentapi.Table{{Name: "wp_log", Rows: 9_000_000, Bytes: 5 << 30, Mode: profile.ModeStructure}}
	opts := DBOptions{RowsPerChunk: 2000, BundleBytes: 8 << 20, Progress: collect(&events), Now: stepClock(time.Second)}
	if err := DownloadTables(quickClient(srv.URL), t.TempDir(), tables, opts); err != nil {
		t.Fatal(err)
	}
	if want := []DBProgress{{Done: 1, Total: 1, Table: "wp_log", BytesDone: 7, BytesTotal: 0}}; !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v", events)
	}
}

// W2: die Datei-Phase meldet je Bundle die Grössen aus dem Delta.
func TestDownloadFilesReportsBytes(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Paths []string `json:"paths"`
		}
		json.NewDecoder(r.Body).Decode(&body)
		for _, p := range body.Paths {
			fmt.Fprintf(w, "F %s\t4\t1700000000\nabcd\n", p)
		}
		w.Write([]byte("E\n"))
	}))
	defer srv.Close()
	files := []agentapi.File{
		{Path: "wp-content/a.txt", Size: 4, MTime: 1700000000},
		{Path: "wp-content/b.txt", Size: 4, MTime: 1700000000},
		{Path: "wp-content/c.txt", Size: 4, MTime: 1700000000},
	}
	var events []string
	progress := func(done, total int, bytesDone, bytesTotal int64) {
		events = append(events, fmt.Sprintf("%d/%d %d/%d", done, total, bytesDone, bytesTotal))
	}
	if _, err := DownloadFiles(quickClient(srv.URL), t.TempDir()+"/public", files, 8, &bytes.Buffer{}, progress); err != nil {
		t.Fatal(err)
	}
	if want := []string{"2/3 8/12", "3/3 12/12"}; !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %v", events)
	}
}
