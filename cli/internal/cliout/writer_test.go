package cliout

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

func lines(t *testing.T, buf *bytes.Buffer) []map[string]any {
	t.Helper()
	var out []map[string]any
	for _, l := range strings.Split(strings.TrimSpace(buf.String()), "\n") {
		var m map[string]any
		if err := json.Unmarshal([]byte(l), &m); err != nil {
			t.Fatalf("not JSON: %q", l)
		}
		out = append(out, m)
	}
	return out
}

func TestPhaseLine(t *testing.T) {
	var buf bytes.Buffer
	NewWriter(&buf).Phase("files", 120, 17210)
	if got := strings.TrimSpace(buf.String()); got != `{"event":"phase","name":"files","done":120,"total":17210}` {
		t.Fatalf("line = %s", got)
	}
}

func TestResultOK(t *testing.T) {
	var buf bytes.Buffer
	code := NewWriter(&buf).Result("version", map[string]string{"version": "0.2.0"}, nil)
	m := lines(t, &buf)[0]
	if code != 0 || m["event"] != "result" || m["command"] != "version" || m["ok"] != true || m["exit_code"] != float64(0) {
		t.Fatalf("result = %v", m)
	}
	if _, has := m["error"]; has {
		t.Fatal("ok result must not carry error")
	}
}

// Spec §10: veralteter Agent → Exit 11 mit beiden Versionen im JSON.
func TestResultAgentOutdated(t *testing.T) {
	var buf bytes.Buffer
	code := NewWriter(&buf).Result("pull", nil, &agentapi.OutdatedError{Installed: "0.2.0", Required: "0.3.0"})
	m := lines(t, &buf)[0]
	e, _ := m["error"].(map[string]any)
	if code != 11 || m["ok"] != false || m["exit_code"] != float64(11) || e["code"] != "agent_outdated" ||
		e["installed"] != "0.2.0" || e["required"] != "0.3.0" || e["message"] == "" {
		t.Fatalf("result = %v", m)
	}
	if _, has := m["data"]; has {
		t.Fatal("failed result must not carry data")
	}
}
