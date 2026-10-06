package agentapi

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestUnreachableBeforeAnySuccess(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	url := srv.URL
	srv.Close()
	c, _ := newTestClient(url)
	_, err := c.Ping()
	if !errors.Is(err, ErrUnreachable) || errors.Is(err, ErrSuspectedBan) {
		t.Fatalf("err = %v", err)
	}
	if _, err := Discover(http.DefaultClient, url); !errors.Is(err, ErrUnreachable) {
		t.Fatalf("discover: err = %v", err)
	}
}

func TestDiscoverWithoutAgentIsUnreachable(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	defer srv.Close()
	if _, err := Discover(http.DefaultClient, srv.URL); !errors.Is(err, ErrUnreachable) {
		t.Fatalf("err = %v", err)
	}
}

// SIGTERM im Server-Modus: ein laufender Request bricht mit context.Canceled ab, nicht als IP-Sperre.
func TestCancelAbortsRunningRequest(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("rest_route") == "/wpsync/v1/ping" {
			w.Write([]byte(`{}`))
			return
		}
		io.Copy(io.Discard, r.Body)
		cancel()
		select {
		case <-r.Context().Done():
		case <-time.After(5 * time.Second):
		}
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	c.Ctx = ctx
	if _, err := c.Ping(); err != nil {
		t.Fatal(err)
	}
	_, _, err := c.Infosheet()
	if !errors.Is(err, context.Canceled) || errors.Is(err, ErrSuspectedBan) {
		t.Fatalf("err = %v", err)
	}
}

func TestCancelEndsBackoffWait(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "300")
		w.WriteHeader(http.StatusTooManyRequests)
		cancel()
	}))
	defer srv.Close()
	c, _ := newTestClient(srv.URL)
	c.Ctx = ctx
	start := time.Now()
	_, err := c.Ping()
	if !errors.Is(err, context.Canceled) || time.Since(start) > 5*time.Second {
		t.Fatalf("err = %v after %s", err, time.Since(start))
	}
}
