package content

import (
	"errors"
	"io"
	"strings"
	"testing"
)

type fakeRunner struct {
	out   string
	err   error
	args  []string
	stdin string
}

func (f *fakeRunner) Run(args ...string) error              { return nil }
func (f *fakeRunner) Output(args ...string) (string, error) { return "", nil }
func (f *fakeRunner) RunStdin(io.Reader, ...string) error   { return nil }
func (f *fakeRunner) Stream(stdin io.Reader, stdout io.Writer, args ...string) error {
	f.args = args
	data, _ := io.ReadAll(stdin)
	f.stdin = string(data)
	if _, err := io.WriteString(stdout, f.out); err != nil {
		return err
	}
	return f.err
}

type plainRunner struct{}

func (plainRunner) Run(args ...string) error              { return nil }
func (plainRunner) Output(args ...string) (string, error) { return "", nil }
func (plainRunner) RunStdin(io.Reader, ...string) error   { return nil }

func TestExportCopiesRecordLines(t *testing.T) {
	r := &fakeRunner{out: `{"t":"posts","k":"1","h":"aa","row":{"post_title":"WA=="}}` + "\n" +
		"Deprecated: something from a plugin\n" +
		`{"t":"options","k":"blogname","h":"bb","row":{"option_value":"Sw=="}}` + "\n" +
		`{"end":true,"rows":2}` + "\n"}
	var out strings.Builder
	rows, err := Export(r, "http://kunde.ddev.site", nil, &out)
	if err != nil || rows != 2 {
		t.Fatalf("rows=%d err=%v", rows, err)
	}
	if strings.Count(out.String(), "\n") != 2 || strings.Contains(out.String(), "Deprecated") || strings.Contains(out.String(), `"end"`) {
		t.Fatalf("output: %q", out.String())
	}
	want := []string{"wp", "eval-file", "-", "http://kunde.ddev.site", "all", "--skip-plugins", "--skip-themes"}
	if strings.Join(r.args, " ") != strings.Join(want, " ") {
		t.Fatalf("args: %v", r.args)
	}
	if !strings.HasPrefix(r.stdin, "<?php\n") {
		t.Fatal("the script did not go to stdin")
	}
}

func TestExportNamesTheTables(t *testing.T) {
	r := &fakeRunner{out: `{"end":true,"rows":0}` + "\n"}
	if _, err := Export(r, "http://x.test", []string{"posts", "postmeta"}, io.Discard); err != nil {
		t.Fatal(err)
	}
	if r.args[4] != "posts,postmeta" {
		t.Fatalf("args: %v", r.args)
	}
}

func TestExportWithoutEndLineFails(t *testing.T) {
	r := &fakeRunner{out: `{"t":"posts","k":"1","h":"aa"}` + "\nPHP Fatal error: memory\n"}
	_, err := Export(r, "http://x.test", nil, io.Discard)
	if !errors.Is(err, ErrExport) || !strings.Contains(err.Error(), "PHP Fatal error") {
		t.Fatalf("err=%v", err)
	}
}

func TestExportWithWrongCountFails(t *testing.T) {
	r := &fakeRunner{out: `{"t":"posts","k":"1","h":"aa"}` + "\n" + `{"end":true,"rows":5}` + "\n"}
	if _, err := Export(r, "http://x.test", nil, io.Discard); !errors.Is(err, ErrExport) {
		t.Fatalf("err=%v", err)
	}
}

func TestExportReportsARunnerError(t *testing.T) {
	r := &fakeRunner{out: "Error: This does not seem to be a WordPress installation.\n", err: errors.New("exit status 1")}
	_, err := Export(r, "http://x.test", nil, io.Discard)
	if !errors.Is(err, ErrExport) || !strings.Contains(err.Error(), "WordPress installation") {
		t.Fatalf("err=%v", err)
	}
}

func TestExportNeedsAStreamer(t *testing.T) {
	if _, err := Export(plainRunner{}, "http://x.test", nil, io.Discard); !errors.Is(err, ErrNoStream) {
		t.Fatalf("err=%v", err)
	}
}

func TestExportSurvivesVeryLongLines(t *testing.T) {
	big := strings.Repeat("A", 3<<20)
	r := &fakeRunner{out: `{"t":"postmeta","k":"1","h":"aa","row":{"values":["` + big + `"]}}` + "\n" + `{"end":true,"rows":1}` + "\n"}
	var out strings.Builder
	if rows, err := Export(r, "http://x.test", nil, &out); err != nil || rows != 1 || out.Len() < 3<<20 {
		t.Fatalf("rows=%d err=%v len=%d", rows, err, out.Len())
	}
}

func TestExportKeepsTheRating(t *testing.T) {
	line := `{"t":"postmeta","k":"1\u0000_edit_lock","h":"aa","row":{"values":["MQ=="]},"p":false,"why":"meta_key"}`
	r := &fakeRunner{out: line + "\n" + `{"end":true,"rows":1}` + "\n"}
	var out strings.Builder
	if rows, err := Export(r, "http://x.test", nil, &out); err != nil || rows != 1 || out.String() != line+"\n" {
		t.Fatalf("rows=%d err=%v out=%q", rows, err, out.String())
	}
}
