package agentapi

import (
	"net/http"
	"net/http/httptest"
	"reflect"
	"testing"
)

// Spec Content-Push §8.2, §8.4, §9: same je Datei der Einheit uploads, Hinweise der Rücknahme,
// Öffner des Fensters im Protokoll.
func TestPushUploadsPlanRollbackNotesAndOpener(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		switch r.URL.Query().Get("rest_route") {
		case "/wpsync/v1/push/begin":
			w.Write([]byte(`{"agent_version":"0.6.0","units":[{"path":"uploads","exists":true,"version":"",
"conflicts":["2026/10/b.png"],"need":["2026/10/a.png"],"same":["2026/10/c.png"],"writable":true}]}`))
		case "/wpsync/v1/push/rollback":
			w.Write([]byte(`{"ok":true,"status":"rolled_back","warnings":["upload_changed_since_push","Bad Warning\n"],"kept":["2026/10/b.png"]}`))
		case "/wpsync/v1/push/list":
			w.Write([]byte(`{"pushes":[{"push_id":"p_20261008_0123456789ab","status":"confirmed","units":[],"created":1,"opened_by":7},
{"push_id":"p_20261007_0123456789ab","status":"confirmed","units":[],"created":1}]}`))
		}
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	plan, err := c.PushBegin(PushBeginRequest{Target: "live", Dry: true})
	if err != nil || !reflect.DeepEqual(plan.Units[0].Same, []string{"2026/10/c.png"}) || !reflect.DeepEqual(plan.Units[0].Need, []string{"2026/10/a.png"}) {
		t.Fatalf("plan = %+v, %v", plan, err)
	}
	notes, err := c.PushRollbackNotes("p_20261008_0123456789ab")
	want := RollbackNotes{Warnings: []string{"upload_changed_since_push"}, Kept: []string{"2026/10/b.png"}}
	if err != nil || !reflect.DeepEqual(notes, want) {
		t.Fatalf("notes = %+v, %v", notes, err)
	}
	if err := c.PushRollback("p_20261008_0123456789ab"); err != nil {
		t.Fatal(err)
	}
	list, err := c.PushList()
	if err != nil || list[0].OpenedBy == nil || *list[0].OpenedBy != 7 || list[1].OpenedBy != nil {
		t.Fatalf("list = %+v, %v", list, err)
	}
}

func TestRollbackNotesCleanKeepsOnlyCodesAndAtMost100Files(t *testing.T) {
	var n RollbackNotes
	for i := 0; i < 150; i++ {
		n.Kept = append(n.Kept, "x")
	}
	n.Warnings = []string{"upload_changed_since_push", "UPPER", "", "a_b"}
	got := n.Clean()
	if len(got.Kept) != 100 || !reflect.DeepEqual(got.Warnings, []string{"upload_changed_since_push", "a_b"}) {
		t.Fatalf("clean = %d kept, %v", len(got.Kept), got.Warnings)
	}
	if empty := (RollbackNotes{}).Clean(); empty.Warnings != nil || empty.Kept != nil {
		t.Fatalf("empty = %+v", empty)
	}
}
