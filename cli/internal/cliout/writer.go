package cliout

import (
	"encoding/json"
	"io"
	"sync"
)

// Writer writes one JSON object per line to stdout (--json).
type Writer struct {
	mu  sync.Mutex
	enc *json.Encoder
}

// NewWriter writes to w.
func NewWriter(w io.Writer) *Writer {
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	return &Writer{enc: enc}
}

// PhaseEvent is a progress line of pull --json. Table, BytesDone and BytesTotal are only there for
// phases that know them (PhaseBytes); the two byte fields always come together, also with 0.
type PhaseEvent struct {
	Event      string `json:"event"`
	Name       string `json:"name"`
	Done       int    `json:"done"`
	Total      int    `json:"total"`
	Table      string `json:"table,omitempty"`
	BytesDone  *int64 `json:"bytes_done,omitempty"`
	BytesTotal *int64 `json:"bytes_total,omitempty"`
}

// ResultEvent is the last line of every --json command.
type ResultEvent struct {
	Event    string   `json:"event"`
	Command  string   `json:"command"`
	OK       bool     `json:"ok"`
	ExitCode int      `json:"exit_code"`
	Data     any      `json:"data,omitempty"`
	Error    *Failure `json:"error,omitempty"`
}

// Phase writes {"event":"phase",…}.
func (w *Writer) Phase(name string, done, total int) {
	w.write(PhaseEvent{Event: "phase", Name: name, Done: done, Total: total})
}

// PhaseBytes writes a phase line with byte progress; table may be empty (omitted then).
func (w *Writer) PhaseBytes(name string, done, total int, table string, bytesDone, bytesTotal int64) {
	w.write(PhaseEvent{Event: "phase", Name: name, Done: done, Total: total, Table: table, BytesDone: &bytesDone, BytesTotal: &bytesTotal})
}

// Result writes {"event":"result",…} and returns the exit code for err. Data is written even on
// failure when the command set it (doctor lists its checks either way).
func (w *Writer) Result(command string, data any, err error) int {
	f := Classify(err)
	ev := ResultEvent{Event: "result", Command: command, OK: err == nil, ExitCode: f.Exit, Data: data}
	if err != nil {
		ev.Error = &f
	}
	w.write(ev)
	return f.Exit
}

// Event writes {"event":<name>,"data":{…}}: the steps of push --json (plan, upload, commit,
// health) and of the staging commands. Without data the object is empty, never null.
func (w *Writer) Event(name string, data any) {
	if data == nil {
		data = struct{}{}
	}
	w.write(struct {
		Event string `json:"event"`
		Data  any    `json:"data"`
	}{name, data})
}

func (w *Writer) write(v any) {
	w.mu.Lock()
	defer w.mu.Unlock()
	_ = w.enc.Encode(v)
}
