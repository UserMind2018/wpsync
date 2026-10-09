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

// chunkTables: wp_small travels in a bundle, wp_large (5 rows, 1000 bytes by the site's estimate)
// in chunks of 2 rows.
var chunkTables = []agentapi.Table{
	{Name: "wp_small", Checksum: sp("1"), Rows: 1, Bytes: 100},
	{Name: "wp_large", Checksum: sp("2"), Rows: 5, Bytes: 1000},
}

func collect(events *[]DBProgress) func(DBProgress) {
	return func(p DBProgress) { *events = append(*events, p) }
}

// checkBytes: bytes_done never falls, never exceeds bytes_total, and ends at it when complete.
func checkBytes(t *testing.T, events []DBProgress, complete bool) {
	t.Helper()
	var prev int64
	for i, e := range events {
		if e.BytesDone < prev || e.BytesDone > e.BytesTotal {
			t.Errorf("event %d: bytes %d/%d after %d", i, e.BytesDone, e.BytesTotal, prev)
		}
		prev = e.BytesDone
	}
	if last := events[len(events)-1]; complete && (last.BytesDone != last.BytesTotal || last.Done != last.Total) {
		t.Errorf("last event = %+v, want done == total and bytes_done == bytes_total", last)
	}
}

func download(t *testing.T, chunks int, tables []agentapi.Table, o DBOptions) []DBProgress {
	t.Helper()
	srv := chunkServer(t, chunks, nil)
	defer srv.Close()
	var events []DBProgress
	o.Progress = collect(&events)
	if o.RowsPerChunk == 0 {
		o.RowsPerChunk = 2
	}
	if o.BundleBytes == 0 {
		o.BundleBytes = 1 << 20
	}
	if o.Now == nil {
		o.Now = stepClock(time.Second)
	}
	if err := DownloadTables(quickClient(srv.URL), t.TempDir(), tables, o); err != nil {
		t.Fatal(err)
	}
	checkBytes(t, events, true)
	return events
}

// W2: eine Tabelle in Chunks meldet sich je Chunk – der Tabellenzähler bleibt, die Bytes wachsen
// mit dem Anteil der empfangenen Zeilen an der Schätzung; fertige Tabellen zählen ihre Schätzung.
func TestDownloadTablesReportsBytesPerChunk(t *testing.T) {
	events := download(t, 3, chunkTables, DBOptions{})
	want := []DBProgress{
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 100, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 500, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 900, BytesTotal: 1100},
		{Done: 2, Total: 2, Table: "wp_large", BytesDone: 1100, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v\nwant     %+v", events, want)
	}
}

// W2: kommt weit weniger an, als die Site schätzt (Indizes, gefilterte Zeilen), endet die Phase
// trotzdem bei bytes_total – der Rest kommt mit dem Abschluss der Tabelle.
func TestDownloadTablesFarBelowTheEstimateStillEndsAtTotal(t *testing.T) {
	tables := []agentapi.Table{{Name: "wp_large", Checksum: sp("2"), Rows: 5000, Bytes: 2 << 30}}
	events := download(t, 3, tables, DBOptions{})
	want := []DBProgress{
		{Done: 0, Total: 1, Table: "wp_large", BytesDone: 858993, BytesTotal: 2 << 30},
		{Done: 0, Total: 1, Table: "wp_large", BytesDone: 1717986, BytesTotal: 2 << 30},
		{Done: 1, Total: 1, Table: "wp_large", BytesDone: 2 << 30, BytesTotal: 2 << 30},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v\nwant     %+v", events, want)
	}
}

// W2: kommen mehr Zeilen an, als die Site schätzt, bleibt die Tabelle bis zu ihrem Abschluss bei
// höchstens 99 % ihrer Schätzung – und geht nie rückwärts.
func TestDownloadTablesAboveTheEstimateStaysBelowItUntilDone(t *testing.T) {
	events := download(t, 6, chunkTables, DBOptions{}) // 11 rows against an estimate of 5
	var got []int64
	for _, e := range events {
		got = append(got, e.BytesDone)
	}
	if want := []int64{100, 500, 900, 1090, 1090, 1090, 1100}; !reflect.DeepEqual(got, want) {
		t.Fatalf("bytes_done = %v, want %v", got, want)
	}
}

// W2: ohne Zeilenschätzung hängt der Anteil an den empfangenen Bytes – mit derselben Grenze.
func TestDownloadTablesWithoutRowEstimateUsesReceivedBytes(t *testing.T) {
	tables := []agentapi.Table{{Name: "wp_large", Checksum: sp("2"), Rows: 0, Bytes: 10}}
	events := download(t, 3, tables, DBOptions{BundleBytes: 5})
	var got []int64
	for _, e := range events {
		got = append(got, e.BytesDone)
	}
	if want := []int64{6, 9, 10}; !reflect.DeepEqual(got, want) { // 6 bytes per chunk, capped at 99 % of 10
		t.Fatalf("bytes_done = %v, want %v", got, want)
	}
}

// W2: höchstens ein Zwischen-Event je Sekunde; der Abschluss einer Tabelle kommt immer.
func TestDownloadTablesThrottlesChunkEvents(t *testing.T) {
	// Clock: wp_small done at 0.4 s, chunks 1–5 at 0.8 … 2.4 s – only chunk 3 (1.6 s) is a full
	// second after the last event.
	events := download(t, 6, chunkTables, DBOptions{Now: stepClock(400 * time.Millisecond)})
	want := []DBProgress{
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 100, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 1090, BytesTotal: 1100},
		{Done: 2, Total: 2, Table: "wp_large", BytesDone: 1100, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v\nwant     %+v", events, want)
	}
}

// W2: ohne vorheriges Event kommt das erste Zwischen-Event sofort.
func TestDownloadTablesFirstChunkEventComesAtOnce(t *testing.T) {
	clock := time.Unix(1700000000, 0)
	events := download(t, 3, chunkTables[1:], DBOptions{Now: func() time.Time { return clock }})
	want := []DBProgress{
		{Done: 0, Total: 1, Table: "wp_large", BytesDone: 400, BytesTotal: 1000},
		{Done: 1, Total: 1, Table: "wp_large", BytesDone: 1000, BytesTotal: 1000},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("events = %+v\nwant     %+v", events, want)
	}
}

// W2, Resume: ein abgebrochener Download zählt beim nächsten Lauf die schon vorhandene Tabelle mit
// ihrer Schätzung in bytes_total UND in bytes_done; die halb geladene Tabelle fängt bei null an.
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
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 100, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 500, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("first run = %+v\nwant       %+v", events, want)
	}
	checkBytes(t, events, false)

	srv2 := chunkServer(t, 3, nil)
	defer srv2.Close()
	events = nil
	opts.Now = stepClock(time.Second)
	if err := DownloadTables(quickClient(srv2.URL), dir, chunkTables, opts); err != nil {
		t.Fatal(err)
	}
	want = []DBProgress{
		{Done: 1, Total: 2, Table: "wp_small", BytesDone: 100, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 500, BytesTotal: 1100},
		{Done: 1, Total: 2, Table: "wp_large", BytesDone: 900, BytesTotal: 1100},
		{Done: 2, Total: 2, Table: "wp_large", BytesDone: 1100, BytesTotal: 1100},
	}
	if !reflect.DeepEqual(events, want) {
		t.Fatalf("resumed run = %+v\nwant          %+v", events, want)
	}
	checkBytes(t, events, true)
}

// W2: Struktur-Tabellen zählen mit 0 (wie in transferBytes) – die Felder kommen trotzdem, 0 von 0.
func TestDownloadTablesStructureOnlyHasNoBytes(t *testing.T) {
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
	if want := []DBProgress{{Done: 1, Total: 1, Table: "wp_log", BytesDone: 0, BytesTotal: 0}}; !reflect.DeepEqual(events, want) {
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
