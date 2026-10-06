package agentapi

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestPushBeginSendsManifestAndDecodesPlan(t *testing.T) {
	var got PushBeginRequest
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		if route := r.URL.Query().Get("rest_route"); route != "/wpsync/v1/push/begin" {
			t.Errorf("route = %q", route)
		}
		json.NewDecoder(r.Body).Decode(&got)
		w.Write([]byte(`{"push_id":"p_20261005_0123456789ab","agent_version":"0.4.0","health_urls":["https://kunde.de/"],
"window_open":true,"pending":{"push_id":"p_20261004_ba9876543210","device":"mac","created":7},
"units":[{"path":"plugins/x","exists":true,"version":"1.4.0","conflicts":["a.php"],"need":["b.php"],"writable":true}],
"rescue":{"url":"https://kunde.de/wp-content/plugins/wpsync-agent/rescue.php","salt":"abc"}}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	req := PushBeginRequest{Target: "live", Dry: true, Units: []PushUnit{{
		Path:  "plugins/x",
		Base:  map[string]PushStamp{"a.php": {Size: 1, MTime: 2}},
		Files: map[string]PushFile{"b.php": {Size: 3, SHA256: "ff", MTime: 4}},
	}}}
	plan, err := c.PushBegin(req)
	if err != nil {
		t.Fatal(err)
	}
	if !got.Dry || got.Target != "live" || got.Units[0].Base["a.php"].MTime != 2 || got.Units[0].Files["b.php"].SHA256 != "ff" {
		t.Errorf("request = %+v", got)
	}
	u := plan.Units[0]
	if plan.PushID != "p_20261005_0123456789ab" || !plan.WindowOpen || plan.Pending.PushID != "p_20261004_ba9876543210" ||
		u.Version != "1.4.0" || u.Conflicts[0] != "a.php" || u.Need[0] != "b.php" || !u.Writable || plan.Rescue.Salt != "abc" {
		t.Errorf("plan = %+v", plan)
	}
}

func TestPushUploadEncodesContentAsBase64(t *testing.T) {
	var body map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		raw, _ := io.ReadAll(r.Body)
		json.Unmarshal(raw, &body)
		w.Write([]byte(`{"received":1}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	err := c.PushUpload("p_20261005_0123456789ab", 2, []PushChunk{{Path: "inc/a.php", Offset: 5, Data: []byte("<?php")}})
	if err != nil {
		t.Fatal(err)
	}
	file := body["files"].([]any)[0].(map[string]any)
	if body["push_id"] != "p_20261005_0123456789ab" || body["unit"] != float64(2) ||
		file["path"] != "inc/a.php" || file["offset"] != float64(5) || file["data"] != "PD9waHA=" {
		t.Errorf("body = %v", body)
	}
}

func TestPushCommitFollowsTheCursor(t *testing.T) {
	var cursors []any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		cursors = append(cursors, body["cursor"])
		if len(cursors) == 1 {
			w.Write([]byte(`{"next":{"u":0,"i":40},"stamps":{}}`))
			return
		}
		w.Write([]byte(`{"next":null,"stamps":{"plugins/x":{"a.php":{"size":3,"mtime":9}}}}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	stamps, err := c.PushCommit("p_20261005_0123456789ab")
	if err != nil {
		t.Fatal(err)
	}
	if len(cursors) != 2 || cursors[0] != nil || cursors[1].(map[string]any)["i"] != float64(40) {
		t.Errorf("cursors = %v", cursors)
	}
	if stamps["plugins/x"]["a.php"] != (PushStamp{Size: 3, MTime: 9}) {
		t.Errorf("stamps = %v", stamps)
	}
}

func TestPushConfirmRollbackAndList(t *testing.T) {
	var routes []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		route := r.URL.Query().Get("rest_route")
		routes = append(routes, route)
		if route == "/wpsync/v1/push/list" {
			w.Write([]byte(`{"pushes":[{"push_id":"p_20261005_0123456789ab","device":"mac","target":"live","status":"confirmed",
"forced":false,"pruned":false,"created":7,"committed":8,"finished":9,
"units":[{"path":"plugins/x","exists":true,"old_version":"1.3","new_version":"1.4","files":10,"uploaded":2}]}]}`))
			return
		}
		w.Write([]byte(`{"ok":true}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	if err := c.PushConfirm("p_20261005_0123456789ab"); err != nil {
		t.Fatal(err)
	}
	if err := c.PushRollback("p_20261005_0123456789ab"); err != nil {
		t.Fatal(err)
	}
	list, err := c.PushList()
	if err != nil || len(list) != 1 || list[0].Status != "confirmed" || *list[0].Finished != 9 || list[0].Units[0].NewVersion != "1.4" || list[0].Units[0].Uploaded != 2 {
		t.Fatalf("list = %+v, %v", list, err)
	}
	want := []string{"/wpsync/v1/push/confirm", "/wpsync/v1/push/rollback", "/wpsync/v1/push/list"}
	for i, route := range want {
		if routes[i] != route {
			t.Errorf("route %d = %q, want %q", i, routes[i], route)
		}
	}
}

func TestPushErrorsKeepTheAgentCode(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		w.Write([]byte(`{"code":"wpsync_push_window","message":"Das Push-Fenster ist geschlossen"}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	_, err := c.PushBegin(PushBeginRequest{Target: "live"})
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 403 || apiErr.Code != "wpsync_push_window" {
		t.Fatalf("err = %v", err)
	}
}
