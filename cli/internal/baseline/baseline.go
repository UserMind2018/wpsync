// Package baseline stores the source state of the last successful pull (Konzept 4.3).
package baseline

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"time"
)

// FileStamp identifies a file version.
type FileStamp struct {
	Size  int64 `json:"size"`
	MTime int64 `json:"mtime"`
}

// Baseline is the state after the last successful pull.
type Baseline struct {
	Source   string               `json:"source"`
	PulledAt time.Time            `json:"pulled_at"`
	Files    map[string]FileStamp `json:"files"`
	Tables   map[string]string    `json:"tables"`
	// Modes holds the mode key each table was pulled with (full, structure, filtered:<post types>).
	Modes map[string]string `json:"modes,omitempty"`
}

// New returns an empty baseline for source.
func New(source string) *Baseline {
	return &Baseline{Source: source, Files: map[string]FileStamp{}, Tables: map[string]string{}, Modes: map[string]string{}}
}

// Mode returns the mode key a table was pulled with: "" if never, "full" for baselines from stage 1a.
func (b *Baseline) Mode(table string) string {
	if m, ok := b.Modes[table]; ok {
		return m
	}
	if _, ok := b.Tables[table]; ok {
		return "full"
	}
	return ""
}

// Empty reports whether no pull has completed yet.
func (b *Baseline) Empty() bool { return b.PulledAt.IsZero() }

func file(siteDir string) string { return filepath.Join(siteDir, ".wpsync", "baseline.json") }

// Load returns the baseline or an empty one.
func Load(siteDir string) (*Baseline, error) {
	data, err := os.ReadFile(file(siteDir))
	if errors.Is(err, os.ErrNotExist) {
		return New(""), nil
	}
	if err != nil {
		return nil, err
	}
	b := New("")
	if err := json.Unmarshal(data, b); err != nil {
		return nil, err
	}
	return b, nil
}

// Save writes the baseline atomically.
func Save(siteDir string, b *Baseline) error {
	p := file(siteDir)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		return err
	}
	if b.PulledAt.IsZero() {
		b.PulledAt = time.Now()
	}
	data, err := json.MarshalIndent(b, "", "  ")
	if err != nil {
		return err
	}
	if err := os.WriteFile(p+".tmp", data, 0o644); err != nil {
		return err
	}
	return os.Rename(p+".tmp", p)
}
