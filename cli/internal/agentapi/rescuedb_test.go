package agentapi

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
)

// Spec Content-Push P3 §5.3: der Begin eines Pushs mit Inhalten nennt rescue.db; ein Agent 0.7.x nennt es nicht.
func TestPushBeginCarriesTheRescueDBState(t *testing.T) {
	answer := ""
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(answer))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	for _, tc := range []struct {
		rescue string
		want   *RescueDBState
	}{
		{`{"url":"x","salt":"s","hardening":[],"db":{"ok":true}}`, &RescueDBState{OK: true}},
		{`{"url":"x","db":{"ok":false,"reason":"no_image_key"}}`, &RescueDBState{Reason: "no_image_key"}},
		{`{"url":"x"}`, nil}, // agent 0.7.x, or a push without content
		// The reason is the site's word: anything that does not look like a code becomes "unknown";
		// a reason next to ok has no meaning.
		{`{"url":"x","db":{"ok":false,"reason":"\u001b[2J <b>x</b>"}}`, &RescueDBState{Reason: "unknown"}},
		{`{"url":"x","db":{"ok":false}}`, &RescueDBState{Reason: "unknown"}},
		{`{"url":"x","db":{"ok":true,"reason":"driver"}}`, &RescueDBState{OK: true}},
	} {
		answer = `{"push_id":"","agent_version":"0.8.0","window_open":true,"units":[],"rescue":` + tc.rescue + `}`
		res, err := c.PushBegin(PushBeginRequest{Target: "live", Dry: true})
		if err != nil {
			t.Fatal(err)
		}
		if !reflect.DeepEqual(res.Rescue.DB, tc.want) {
			t.Errorf("%s: db = %+v, want %+v", tc.rescue, res.Rescue.DB, tc.want)
		}
	}
}

// Spec Content-Push P3 §7.6: was rescue.php über die Inhalte sagt – bereinigt wie alles, was von der Site kommt.
func TestRollbackNotesCarryTheContentOfRescue(t *testing.T) {
	decode := func(body string) RollbackNotes {
		t.Helper()
		var n RollbackNotes
		if err := json.Unmarshal([]byte(body), &n); err != nil {
			t.Fatal(err)
		}
		return n.Clean()
	}
	n := decode(`{"ok":true,"status":"rolled_back","content":{"state":"rolled_back","cache":"stale"},"warnings":["upload_changed_since_push"],"kept":["2026/10/a.png"]}`)
	if n.Content == nil || n.Content.State != "rolled_back" || n.Content.Cache != "stale" || n.Content.Error != nil {
		t.Errorf("content = %+v", n.Content)
	}
	n = decode(`{"content":{"state":"nothing"}}`)
	if n.Content == nil || n.Content.State != "nothing" || n.Content.Cache != "" {
		t.Errorf("content = %+v", n.Content)
	}
	n = decode(`{"content":{"state":"kept","error":{"code":"changed_since_push","keys":[{"table":"posts","key":"219"},{"table":"x y","key":"1"}],"total":7}},"warnings":["content_not_rolled_back"]}`)
	want := &RescueContentError{Code: "changed_since_push", Keys: []ContentKey{{Table: "posts", Key: "219"}}, Total: 7}
	if n.Content == nil || n.Content.State != "kept" || !reflect.DeepEqual(n.Content.Error, want) {
		t.Errorf("content = %+v, error = %+v", n.Content, n.Content.Error)
	}
	n = decode(`{"content":{"state":"kept","error":{"code":"content_failed","unrestored":true,"keys":[{"table":"posts","key":"219"}],"total":1}}}`)
	if !n.Content.Error.Unrestored {
		t.Errorf("unrestored lost: %+v", n.Content.Error)
	}
	n = decode(`{"content":{"state":"rolled_back","cache":"none","left":[{"table":"postmeta","key":"1000001\u0000farbe"}],"left_total":3},"warnings":["content_left_extra"]}`)
	if len(n.Content.Left) != 1 || n.Content.Left[0].Table != "postmeta" || n.Content.LeftTotal != 3 {
		t.Errorf("left = %+v / %d", n.Content.Left, n.Content.LeftTotal)
	}

	// What does not look like the agent's words is dropped: an unknown state is no statement at all
	// (the caller then assumes the content still stands), an unknown cache state is none, a code
	// that is no code becomes content_failed, kept without a reason gets one.
	for _, body := range []string{`{"content":{"state":"done"}}`, `{"content":{"state":""}}`, `{"content":"rolled_back"}`, `{"content":null}`, `{}`} {
		var raw RollbackNotes
		_ = json.Unmarshal([]byte(body), &raw) // a field of another type stays empty
		if got := raw.Clean(); got.Content != nil {
			t.Errorf("%s: content = %+v, want none", body, got.Content)
		}
	}
	n = decode(`{"content":{"state":"rolled_back","cache":"\u001b[31m"}}`)
	if n.Content.Cache != "" {
		t.Errorf("cache = %q", n.Content.Cache)
	}
	n = decode(`{"content":{"state":"kept","error":{"code":"Access denied for user 'wp'@'db'"}}}`)
	if n.Content.Error.Code != "content_failed" {
		t.Errorf("code = %q", n.Content.Error.Code)
	}
	n = decode(`{"content":{"state":"kept"}}`)
	if n.Content.Error == nil || n.Content.Error.Code != "content_failed" {
		t.Errorf("kept without a reason: %+v", n.Content.Error)
	}
	n = decode(`{"content":{"state":"rolled_back","error":{"code":"changed_since_push"}}}`)
	if n.Content.Error != nil {
		t.Errorf("an error next to rolled_back means nothing: %+v", n.Content.Error)
	}
	// Bounded: at most 200 keys either way.
	many := strings.Repeat(`{"table":"posts","key":"1"},`, 300)
	n = decode(`{"content":{"state":"rolled_back","left":[` + strings.TrimSuffix(many, ",") + `]}}`)
	if len(n.Content.Left) != 200 || n.Content.LeftTotal != 200 {
		t.Errorf("left = %d / %d", len(n.Content.Left), n.Content.LeftTotal)
	}
}

// §8.2: das Protokoll der Pushes nennt, was der Agent nach einer Rücknahme durch rescue.php nachgeholt hat.
func TestPushListCarriesWhatTheAgentCaughtUpOn(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"pushes":[{"push_id":"p_20261005_0123456789ab","device":"mac","target":"live","status":"rolled_back","created":7,
"units":[{"path":"plugins/x","files":1,"uploaded":1},
{"path":"content","files":5,"uploaded":5,"via":"rescue","post_actions":[{"step":"object_cache","ok":true},{"step":"<b>","ok":true}],"left":[{"table":"postmeta","key":"1\u0000x"}],"left_total":1},
{"path":"themes/t","files":1,"uploaded":1,"via":"\u001b[2J"}]}]}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	list, err := c.PushList()
	if err != nil || len(list) != 1 || len(list[0].Units) != 3 {
		t.Fatalf("list = %+v, %v", list, err)
	}
	u := list[0].Units[1]
	if u.Via != "rescue" || !reflect.DeepEqual(u.PostActions, []PostAction{{Step: "object_cache", OK: true}}) || u.LeftTotal != 1 || len(u.Left) != 1 {
		t.Errorf("content unit = %+v", u)
	}
	if list[0].Units[0].Via != "" || list[0].Units[2].Via != "" {
		t.Errorf("via of other units: %q %q", list[0].Units[0].Via, list[0].Units[2].Via)
	}
}
