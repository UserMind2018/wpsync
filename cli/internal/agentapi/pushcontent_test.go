package agentapi

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
)

// stageServer plays /content/stage: it keeps what arrives per sha256 and answers like the agent.
type stageServer struct {
	have     map[string][]byte
	requests []map[string]any
	lie      bool // confirm fewer bytes than sent
}

func (s *stageServer) handler(t *testing.T) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		verify(t, r)
		if route := r.URL.Query().Get("rest_route"); route != "/wpsync/v1/content/stage" {
			t.Errorf("route = %q", route)
		}
		var req struct {
			SHA256 string `json:"sha256"`
			Size   int64  `json:"size"`
			Offset *int64 `json:"offset"`
			Data   []byte `json:"data"`
		}
		raw := map[string]any{}
		dec := json.NewDecoder(r.Body)
		if err := dec.Decode(&raw); err != nil {
			t.Fatal(err)
		}
		s.requests = append(s.requests, raw)
		b, _ := json.Marshal(raw)
		json.Unmarshal(b, &req)
		if req.Offset != nil {
			if *req.Offset != int64(len(s.have[req.SHA256])) {
				t.Errorf("offset %d, have %d", *req.Offset, len(s.have[req.SHA256]))
			}
			s.have[req.SHA256] = append(s.have[req.SHA256], req.Data...)
		}
		n := int64(len(s.have[req.SHA256]))
		if s.lie && req.Offset != nil {
			n--
		}
		json.NewEncoder(w).Encode(map[string]any{"sha256": req.SHA256, "received": n, "complete": n == req.Size})
	}
}

func TestContentStageSendsPiecesAndResumes(t *testing.T) {
	s := &stageServer{have: map[string][]byte{}}
	srv := httptest.NewServer(s.handler(t))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	data := []byte(strings.Repeat("0123456789", 10)) // 100 bytes
	sha := strings.Repeat("ab", 32)

	sent, err := c.ContentStageBytes(sha, data, 40)
	if err != nil || !sent {
		t.Fatalf("sent = %v, err = %v", sent, err)
	}
	if string(s.have[sha]) != string(data) || len(s.requests) != 4 {
		t.Errorf("have %d bytes after %d requests", len(s.have[sha]), len(s.requests))
	}
	if _, has := s.requests[0]["data"]; has {
		t.Error("the first request only asks what is there")
	}

	// The package lies complete: nothing is sent again.
	s.requests = nil
	if sent, err := c.ContentStageBytes(sha, data, 40); err != nil || sent || len(s.requests) != 1 {
		t.Errorf("sent = %v, err = %v, requests = %d", sent, err, len(s.requests))
	}

	// An interrupted upload continues where it stopped.
	other := strings.Repeat("cd", 32)
	s.have[other] = append([]byte{}, data[:70]...)
	s.requests = nil
	if _, err := c.ContentStageBytes(other, data, 40); err != nil || string(s.have[other]) != string(data) || len(s.requests) != 2 {
		t.Errorf("err = %v, have = %d, requests = %d", err, len(s.have[other]), len(s.requests))
	}
}

func TestContentStageStopsWhenTheAgentCountsDifferently(t *testing.T) {
	s := &stageServer{have: map[string][]byte{}, lie: true}
	srv := httptest.NewServer(s.handler(t))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	_, err := c.ContentStageBytes(strings.Repeat("ab", 32), []byte("0123456789"), 4)
	if !errors.Is(err, ErrStage) {
		t.Fatalf("err = %v", err)
	}
}

func TestPushBeginCarriesTheContentAndItsPlan(t *testing.T) {
	var got map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		json.NewDecoder(r.Body).Decode(&got)
		w.Write([]byte(`{"push_id":"","agent_version":"0.7.0","window_open":false,"units":[],"rescue":{"url":"x"},
"content":{"ok":false,"partial":true,"error":{"code":"conflict","message":"ge\u0007ändert","keys":[{"table":"posts","key":"219"},{"table":"postmeta","key":"219\u0000_x"},{"table":"../x","key":"1"}],"total":3},
"rows":{"posts":2,"postmeta":1},"limits":{"max_rows":5000,"max_bytes":8388608,"budget_seconds":12,"id_headroom":1000000,"max_state_bytes":67108864},
"conflicts":[{"table":"posts","key":"219"}],"health_urls":["https://kunde.de/a/"],
"extensions":{"post_types":["referenz","../x"],"taxonomies":[],"meta_exceptions":["design_token"]}}}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	plan, err := c.PushBegin(PushBeginRequest{Target: "live", Dry: true, Content: &PushContentRef{SHA256: "ff"}})
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(got["content"], map[string]any{"sha256": "ff"}) || got["units"] != nil {
		t.Errorf("request = %v", got)
	}
	ct := plan.Content
	if ct == nil || ct.OK || !ct.Partial || ct.Error.Code != "conflict" || ct.Error.Total != 3 || ct.Rows["posts"] != 2 || ct.Limits.MaxRows != 5000 ||
		ct.Limits.BudgetSeconds != 12 || ct.Limits.IDHeadroom != 1000000 || ct.Limits.MaxStateBytes != 64<<20 || len(ct.Conflicts) != 1 || ct.HealthURLs[0] != "https://kunde.de/a/" {
		t.Fatalf("content = %+v", ct)
	}
	if e := ct.Extensions; e == nil || !reflect.DeepEqual(*e, ContentExtensions{PostTypes: []string{"referenz"}, Taxonomies: []string{}, MetaExceptions: []string{"design_token"}}) {
		t.Errorf("extensions = %+v", e)
	}
	want := []ContentKey{{Table: "posts", Key: "219"}, {Table: "postmeta", Key: "219\x00_x"}}
	if !reflect.DeepEqual(ct.Error.Keys, want) {
		t.Errorf("keys = %q", ct.Error.Keys)
	}
	if strings.ContainsRune(ct.Error.Message, 7) {
		t.Errorf("message not cleaned: %q", ct.Error.Message)
	}

	// Without content the request names none.
	got = nil
	if _, err := c.PushBegin(PushBeginRequest{Target: "live", Dry: true}); err != nil {
		t.Fatal(err)
	}
	if _, has := got["content"]; has {
		t.Errorf("request without content = %v", got)
	}
}

func TestPushCommitFullReturnsStampsAndContent(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := strings.Repeat("a", 64)
		w.Write([]byte(`{"next":null,"stamps":{"plugins/x":{"a.php":{"size":1,"mtime":2}}},"content":{"rows":2,
"after":[{"t":"posts","k":"219","h":"` + h + `"},{"t":"postmeta","k":"219\u0000_x","h":null},{"t":"posts","k":"220","h":"kein hash"}],
"post_actions":[{"step":"object_cache","ok":true},{"step":"Elementor CSS!","ok":true},{"step":"revisions","ok":false}],"seconds":0.42}}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	res, err := c.PushCommitFull("p_20261005_0123456789ab")
	if err != nil {
		t.Fatal(err)
	}
	if res.Stamps["plugins/x"]["a.php"].MTime != 2 || res.Content == nil || res.Content.Rows != 2 || res.Content.Seconds != 0.42 {
		t.Fatalf("result = %+v", res)
	}
	if len(res.Content.After) != 2 || res.Content.After[1].H != nil || res.Content.After[1].K != "219\x00_x" {
		t.Errorf("after = %+v", res.Content.After)
	}
	if !reflect.DeepEqual(res.Content.PostActions, []PostAction{{Step: "object_cache", OK: true}, {Step: "revisions", OK: false}}) {
		t.Errorf("post actions = %+v", res.Content.PostActions)
	}
	stamps, err := c.PushCommit("p_20261005_0123456789ab")
	if err != nil || stamps["plugins/x"]["a.php"].Size != 1 {
		t.Errorf("PushCommit = %v, %v", stamps, err)
	}
}

func TestContentErrorsCarryKeysAndPaths(t *testing.T) {
	body := `{"code":"wpsync_content_conflict","message":"geändert","data":{"status":409,"keys":[{"table":"posts","key":"219"}],"total":1}}`
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusConflict)
		w.Write([]byte(body))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)

	_, err := c.PushCommitFull("p_20261005_0123456789ab")
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Code != "wpsync_content_conflict" || !reflect.DeepEqual(apiErr.Keys, []ContentKey{{Table: "posts", Key: "219"}}) {
		t.Fatalf("err = %#v", err)
	}
	body = `{"code":"wpsync_content_upload_missing","message":"fehlt","data":{"status":409,"paths":["2026/10/a.jpg"]}}`
	_, err = c.PushCommitFull("p_20261005_0123456789ab")
	if !errors.As(err, &apiErr) || !reflect.DeepEqual(apiErr.Paths, []string{"2026/10/a.jpg"}) {
		t.Fatalf("err = %#v", err)
	}
	// Details of other errors are not taken from the site.
	body = `{"code":"wpsync_push_window","message":"zu","data":{"status":403,"keys":[{"table":"posts","key":"219"}]}}`
	_, err = c.PushCommitFull("p_20261005_0123456789ab")
	if !errors.As(err, &apiErr) || apiErr.Keys != nil {
		t.Fatalf("err = %#v", err)
	}
	// A long answer that is no JSON stays a short message.
	body = strings.Repeat("<html>", 20000)
	_, err = c.PushCommitFull("p_20261005_0123456789ab")
	if !errors.As(err, &apiErr) || len(apiErr.Message) > 4000 {
		t.Fatalf("message of %d bytes", len(apiErr.Message))
	}
}

func TestRollbackNotesCarryPostActions(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"ok":true,"status":"rolled_back","warnings":["content_not_rolled_back"],"post_actions":[{"step":"object_cache","ok":true}]}`))
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	notes, err := c.PushRollbackNotes("p_20261005_0123456789ab")
	if err != nil || !reflect.DeepEqual(notes.Warnings, []string{"content_not_rolled_back"}) || !reflect.DeepEqual(notes.PostActions, []PostAction{{Step: "object_cache", OK: true}}) {
		t.Fatalf("notes = %+v, %v", notes, err)
	}
}
