package agentapi

import (
	"io"
	"reflect"
	"strings"
	"testing"
)

func TestReadFileFrames(t *testing.T) {
	stream := "F wp-content/a.txt\t5\t1700000000\nhello\nM wp-content/gone.txt\nF wp-content/b.txt\t0\t1\n\nE\n"
	var got []string
	var missing []string
	err := ReadFileFrames(strings.NewReader(stream), func(path string, size, mtime int64, body io.Reader) error {
		data, _ := io.ReadAll(body)
		got = append(got, path+"="+string(data))
		return nil
	}, func(path string) { missing = append(missing, path) })
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"wp-content/a.txt=hello", "wp-content/b.txt="}; !reflect.DeepEqual(got, want) {
		t.Fatalf("files = %v, want %v", got, want)
	}
	if want := []string{"wp-content/gone.txt"}; !reflect.DeepEqual(missing, want) {
		t.Fatalf("missing = %v, want %v", missing, want)
	}
}

func TestReadFileFramesHandlerMayIgnoreBody(t *testing.T) {
	stream := "F wp-content/a.txt\t5\t1\nhello\nE\n"
	err := ReadFileFrames(strings.NewReader(stream), func(string, int64, int64, io.Reader) error { return nil }, nil)
	if err != nil {
		t.Fatalf("unread body must be drained, got %v", err)
	}
}

func TestReadFileFramesTruncated(t *testing.T) {
	stream := "F wp-content/a.txt\t5\t1\nhel"
	err := ReadFileFrames(strings.NewReader(stream), func(string, int64, int64, io.Reader) error { return nil }, nil)
	if err == nil {
		t.Fatal("expected error for truncated stream")
	}
}

func TestReadTableFrames(t *testing.T) {
	stream := "T wp_a\t2\t6\nSQL-A;\nT wp_b\t0\t0\n\nE\n"
	var bodies []string
	received, err := ReadTableFrames(strings.NewReader(stream), func(name string, rows int, sql io.Reader) error {
		data, _ := io.ReadAll(sql)
		bodies = append(bodies, name+":"+string(data))
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"wp_a", "wp_b"}; !reflect.DeepEqual(received, want) {
		t.Fatalf("received = %v, want %v", received, want)
	}
	if want := []string{"wp_a:SQL-A;", "wp_b:"}; !reflect.DeepEqual(bodies, want) {
		t.Fatalf("bodies = %v", bodies)
	}
}

func TestReadTableFramesEarlyEnd(t *testing.T) {
	received, err := ReadTableFrames(strings.NewReader("T wp_a\t1\t3\nabc\nE\n"), func(string, int, io.Reader) error { return nil })
	if err != nil || len(received) != 1 {
		t.Fatalf("received = %v, err = %v", received, err)
	}
}
