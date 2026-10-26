package pathmatch

import (
	"encoding/json"
	"os"
	"reflect"
	"testing"
)

type fixture struct {
	Valid []struct {
		In  []string `json:"in"`
		Out []string `json:"out"`
	} `json:"valid"`
	Invalid []string `json:"invalid"`
	Match   []struct {
		P    []string `json:"p"`
		Path string   `json:"path"`
		Dir  bool     `json:"dir"`
		Want bool     `json:"want"`
	} `json:"match"`
	Touches []struct {
		P    []string `json:"p"`
		Unit string   `json:"unit"`
		Want bool     `json:"want"`
	} `json:"touches"`
}

func load(t *testing.T) fixture {
	t.Helper()
	raw, err := os.ReadFile("../../../agent/tests/fixtures/pathmatch.json")
	if err != nil {
		t.Fatal(err)
	}
	var f fixture
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	return f
}

func TestNormalize(t *testing.T) {
	for _, c := range load(t).Valid {
		got, err := Normalize(c.In)
		if err != nil {
			t.Fatalf("Normalize(%q): %v", c.In, err)
		}
		if !reflect.DeepEqual(got, c.Out) {
			t.Errorf("Normalize(%q) = %q, want %q", c.In, got, c.Out)
		}
	}
}

func TestInvalid(t *testing.T) {
	for _, in := range load(t).Invalid {
		if _, err := Normalize([]string{in}); err == nil {
			t.Errorf("Normalize(%q) accepted", in)
		}
	}
}

func TestTooMany(t *testing.T) {
	var in []string
	for i := 0; i < MaxEntries+1; i++ {
		in = append(in, "uploads/d"+string(rune('a'+i%26))+string(rune('a'+i/26))+"/")
	}
	if _, err := Normalize(in); err == nil {
		t.Fatal("201 entries accepted")
	}
	if _, err := Normalize(in[:MaxEntries]); err != nil {
		t.Fatalf("200 entries refused: %v", err)
	}
}

func TestMatch(t *testing.T) {
	for _, c := range load(t).Match {
		s := MustCompile(c.P)
		if got := s.Match(c.Path, c.Dir); got != c.Want {
			t.Errorf("%q.Match(%q, dir=%v) = %v, want %v", c.P, c.Path, c.Dir, got, c.Want)
		}
	}
}

func TestTouches(t *testing.T) {
	for _, c := range load(t).Touches {
		if got := MustCompile(c.P).Touches(c.Unit); got != c.Want {
			t.Errorf("%q.Touches(%q) = %v, want %v", c.P, c.Unit, got, c.Want)
		}
	}
}
