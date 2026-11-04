package agentapi

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func activityServer(t *testing.T, status int, answer string) (*Client, *map[string]any) {
	t.Helper()
	got := map[string]any{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("rest_route") != ActivityRoute {
			http.Error(w, `{"code":"rest_no_route","message":"no route"}`, http.StatusNotFound)
			return
		}
		json.NewDecoder(r.Body).Decode(&got)
		w.WriteHeader(status)
		w.Write([]byte(answer))
	}))
	t.Cleanup(srv.Close)
	return New(srv.URL, "0123456789abcdef", "secret", 1000), &got
}

func TestActivitySendsTheRangeAndCleans(t *testing.T) {
	long := strings.Repeat("x", 300)
	c, got := activityServer(t, 200, `{"since":100,"until":200,"checked":200,"log_since":50,"agent_version":"0.12.0","core":"6.8.3",
"events":[
 {"at":150,"kind":"updated","type":"plugin","slug":"a","name":"A\u001b[31m","from":"1.0","to":"1.1","source":"auto","user":null,"detail":null},
 {"at":151,"kind":"pwned","type":"plugin","slug":"b","name":"B","from":null,"to":null,"source":"auto","user":null,"detail":null},
 {"at":152,"kind":"activated","type":"plugin","slug":"c","name":"`+long+`","from":null,"to":null,"source":"user","user":{"id":3,"login":"redaktion"},"detail":null},
 {"at":153,"kind":"activated","type":"plugin","slug":"d","name":"D","from":null,"to":null,"source":"martian","user":null,"detail":null}
],"truncated":false,"auto_updates":{"plugins":["a"],"themes":null}}`)
	rep, err := c.Activity(100, 200, 50)
	if err != nil {
		t.Fatal(err)
	}
	if (*got)["since"] != float64(100) || (*got)["until"] != float64(200) || (*got)["limit"] != float64(50) {
		t.Errorf("body = %v", *got)
	}
	if len(rep.Events) != 2 || rep.Events[0].Slug != "a" || rep.Events[1].Slug != "c" {
		t.Fatalf("events = %+v", rep.Events)
	}
	if rep.Events[0].Name != "A [31m" {
		t.Errorf("name = %q", rep.Events[0].Name)
	}
	if len(rep.Events[1].Name) != 191 || rep.Events[1].User == nil || rep.Events[1].User.Login != "redaktion" {
		t.Errorf("event = %+v", rep.Events[1])
	}
	if *rep.Events[0].From != "1.0" || rep.Events[1].From != nil || rep.AutoUpdates.Themes == nil || *rep.LogSince != 50 {
		t.Errorf("report = %+v", rep)
	}
}

func TestActivityCapsTheEvents(t *testing.T) {
	var events []string
	for i := 0; i < ActivityMaxEvents+5; i++ {
		events = append(events, `{"at":1,"kind":"updated","type":"plugin","slug":"a","name":"A","from":"1","to":"2","source":"auto","user":null,"detail":null}`)
	}
	c, _ := activityServer(t, 200, `{"events":[`+strings.Join(events, ",")+`],"truncated":false,"auto_updates":{"plugins":[],"themes":[]}}`)
	rep, err := c.Activity(0, 1, 500)
	if err != nil {
		t.Fatal(err)
	}
	if len(rep.Events) != ActivityMaxEvents || !rep.Truncated {
		t.Errorf("events = %d, truncated = %v", len(rep.Events), rep.Truncated)
	}
}

func TestActivityOnAnOldAgentIsOutdated(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"code":"rest_no_route","message":"no route"}`, http.StatusNotFound)
	}))
	t.Cleanup(srv.Close)
	_, err := New(srv.URL, "0123456789abcdef", "secret", 1000).Activity(0, 1, 1)
	var outdated *OutdatedError
	if !errors.As(err, &outdated) || outdated.Required != MinAgentActivity {
		t.Fatalf("err = %v", err)
	}
}

func TestActivityRangeErrorStaysAnAPIError(t *testing.T) {
	c, _ := activityServer(t, 400, `{"code":"wpsync_activity_range","message":"since/until/limit ausserhalb der Grenzen","data":{"status":400}}`)
	_, err := c.Activity(0, 1, 1)
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Code != "wpsync_activity_range" {
		t.Fatalf("err = %v", err)
	}
}
