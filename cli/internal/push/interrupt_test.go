package push

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net/http"
	"strings"
	"testing"
	"time"
)

// AC-125, C12: SIGTERM vor dem echten Begin – Exit 30, auf dem Server entsteht kein Push.
func TestSIGTERMBeforeTheBeginCreatesNoPush(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	o.Ctx = ctx
	o.Sleep = func(time.Duration) { cancel() } // during the health check before the swap
	var res Result
	o.Report = &res
	err := Run(o)
	if !errors.Is(err, ErrInterrupted) || !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin" || f.begins[0].Dry != true {
		t.Errorf("routes = %s", got)
	}
	if res.PushID != "" || res.Status != "" {
		t.Errorf("result = %+v", res)
	}
}

// Zwischen Begin und Commit: der Upload bricht ab, nichts wird getauscht, der Push verfällt.
func TestSIGTERMDuringTheUploadSwapsNothing(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	o.Ctx = ctx
	f.onUpload = cancel
	var res Result
	o.Report = &res
	err := Run(o)
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v", err)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload" {
		t.Errorf("routes = %s", got)
	}
	if res.PushID != testID || res.Status != "" {
		t.Errorf("result = %+v", res)
	}
}

// Nach /push/commit läuft der Push zu Ende: bestätigt, nie getauscht und ungeprüft liegen gelassen.
func TestSIGTERMAfterTheCommitStillConfirms(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	o.Ctx = ctx
	o.Sleep = func(time.Duration) {
		if f.committed {
			cancel() // during the health check after the swap
		}
	}
	var res Result
	o.Report = &res
	if err := Run(o); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload commit confirm" {
		t.Errorf("routes = %s", got)
	}
	if res.Status != "confirmed" {
		t.Errorf("result = %+v", res)
	}
}

// Nach dem Commit gilt dasselbe für einen Push, der zurückgerollt werden muss.
func TestSIGTERMAfterTheCommitStillRollsBack(t *testing.T) {
	f := newFakeSite(t)
	f.broken = true
	o, _, _ := localSite(t, f)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	o.Ctx = ctx
	o.Sleep = func(time.Duration) {
		if f.committed {
			cancel()
		}
	}
	var res Result
	o.Report = &res
	var rolled *RolledBackError
	if err := Run(o); !errors.As(err, &rolled) {
		t.Fatalf("err = %v", err)
	}
	if res.Status != "rolled_back" {
		t.Errorf("result = %+v", res)
	}
}

// rollback läuft immer zu Ende, auch mit abgebrochenem Kontext.
func TestRollbackIgnoresSIGTERM(t *testing.T) {
	f, o, _ := pushed(t)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	o.Ctx = ctx
	if err := Rollback(o, testID); err != nil {
		t.Fatal(err)
	}
	if !f.rolledBack {
		t.Error("not rolled back")
	}
}

// Grill 2026-10-07: nach einem SIGTERM nach dem Tausch hat der Rest AfterSignal Zeit. Ein
// Health-Check, der bis AfterSignal-RollbackReserve nicht sauber durch ist, gilt als gescheitert:
// zurückgerollt (Exit 43), nie ungeprüft bestätigt.
func TestSIGTERMBudgetRollsBackAnUnfinishedHealthCheck(t *testing.T) {
	f := newFakeSite(t)
	f.stall = true
	o, _, out := localSite(t, f)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	o.Ctx = ctx
	o.AfterSignal, o.RollbackReserve = 400*time.Millisecond, 200*time.Millisecond
	f.onCommit = func(*http.Request) { cancel() }
	var res Result
	o.Report = &res
	start := time.Now()
	err := Run(o)
	var rolled *RolledBackError
	if !errors.As(err, &rolled) || !strings.Contains(err.Error(), "nicht rechtzeitig") {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if got := strings.Join(f.routes, " "); got != "begin begin upload commit rescue" {
		t.Errorf("routes = %s", got)
	}
	if res.Status != "rolled_back" || !f.rolledBack {
		t.Errorf("result = %+v", res)
	}
	if d := time.Since(start); d > 2*time.Second {
		t.Errorf("took %v, the budget is 400 ms", d)
	}
}

// Läuft die Zeit nach SIGTERM schon im /push/commit ab, ist der Stand unklar: Exit 42 wie ein
// unbestätigter Push, nie context.Canceled – das wäre Exit 30, „nichts getauscht“.
func TestSIGTERMBudgetDuringTheCommitIsPending(t *testing.T) {
	f := newFakeSite(t)
	o, _, _ := localSite(t, f)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	o.Ctx = ctx
	o.AfterSignal, o.RollbackReserve = 300*time.Millisecond, 100*time.Millisecond
	f.onCommit = func(r *http.Request) {
		cancel()
		body, _ := io.ReadAll(r.Body) // only then does the server notice a client that hangs up
		r.Body = io.NopCloser(bytes.NewReader(body))
		<-r.Context().Done() // the commit hangs until the client gives up
	}
	var res Result
	o.Report = &res
	err := Run(o)
	var pending *PendingError
	if !errors.As(err, &pending) || pending.PushID != testID || errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v", err)
	}
	if res.Status != "committed" || res.PushID != testID {
		t.Errorf("result = %+v", res)
	}
}

// Ohne SIGTERM gilt keine Frist: AfterSignal greift nur nach dem Signal.
func TestNoBudgetWithoutASignal(t *testing.T) {
	f := newFakeSite(t)
	o, _, out := localSite(t, f)
	o.Ctx = context.Background()
	o.AfterSignal, o.RollbackReserve = time.Millisecond, time.Microsecond
	o.Sleep = func(time.Duration) { time.Sleep(5 * time.Millisecond) }
	var res Result
	o.Report = &res
	if err := Run(o); err != nil || res.Status != "confirmed" {
		t.Fatalf("err = %v, result = %+v\n%s", err, res, out)
	}
}
