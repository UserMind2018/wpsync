package pull

import (
	"errors"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
)

// DBOptions controls chunking and the pull scope.
type DBOptions struct {
	RowsPerChunk int
	BundleBytes  int64
	Scope        agentapi.Scope
	// Progress (may be nil) gets the number of finished tables, already downloaded ones included.
	Progress func(done, total int)
}

const (
	importHeader = "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO';\n"
	importFooter = "SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;\n"
)

// DownloadTables stores each table as dir/<name>.sql plus a dir/<name>.done marker.
// Small tables travel together (Spike B16), large ones per keyset chunk (Spike B26).
func DownloadTables(c *agentapi.Client, dir string, tables []agentapi.Table, o DBOptions) error {
	// All names first: a valid table must not be requested before an invalid one aborts the run.
	for _, t := range tables {
		if _, err := tablePath(dir, t.Name, sqlSuffix); err != nil {
			return err
		}
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	done := 0
	tick := func() {
		done++
		if o.Progress != nil {
			o.Progress(done, len(tables))
		}
	}
	var small, large []agentapi.Table
	for _, t := range tables {
		switch {
		case markerMatches(dir, t):
			tick()
		case t.Mode == profile.ModeStructure || (t.Rows <= int64(o.RowsPerChunk) && t.Bytes <= o.BundleBytes):
			small = append(small, t)
		default:
			large = append(large, t)
		}
	}
	for _, group := range tableGroups(small, o.BundleBytes) {
		if err := fetchBundles(c, dir, group, o.RowsPerChunk, o.Scope, tick); err != nil {
			return err
		}
	}
	for _, t := range large {
		if err := fetchChunks(c, dir, t, o.RowsPerChunk, o.Scope); err != nil {
			return err
		}
		tick()
	}
	return nil
}

func tableGroups(tables []agentapi.Table, maxBytes int64) [][]agentapi.Table {
	var groups [][]agentapi.Table
	var current []agentapi.Table
	var size int64
	for _, t := range tables {
		if len(current) > 0 && size+transferBytes(t) > maxBytes {
			groups = append(groups, current)
			current, size = nil, 0
		}
		current = append(current, t)
		size += transferBytes(t)
	}
	if len(current) > 0 {
		groups = append(groups, current)
	}
	return groups
}

// transferBytes is what a table adds to a bundle – nothing for structure-only tables.
func transferBytes(t agentapi.Table) int64 {
	if t.Mode == profile.ModeStructure {
		return 0
	}
	return t.Bytes
}

func fetchBundles(c *agentapi.Client, dir string, group []agentapi.Table, limit int, scope agentapi.Scope, tick func()) error {
	byName := map[string]agentapi.Table{}
	remaining := make([]string, 0, len(group))
	for _, t := range group {
		byName[t.Name] = t
		remaining = append(remaining, t.Name)
	}
	for len(remaining) > 0 {
		received, err := c.DBBundle(remaining, limit, scope, func(name string, rows int, sql io.Reader) error {
			t, ok := byName[name]
			if !ok {
				return fmt.Errorf("unexpected table %s", name)
			}
			if err := storeTable(dir, t, sql); err != nil {
				return err
			}
			tick()
			return nil
		})
		if err != nil {
			return err
		}
		if len(received) == 0 {
			return fmt.Errorf("db-bundle returned no table for %v", remaining)
		}
		got := map[string]bool{}
		for _, n := range received {
			got[n] = true
		}
		next := remaining[:0]
		for _, n := range remaining {
			if !got[n] {
				next = append(next, n)
			}
		}
		remaining = next
	}
	return nil
}

func fetchChunks(c *agentapi.Client, dir string, t agentapi.Table, limit int, scope agentapi.Scope) error {
	part, err := tablePath(dir, t.Name, partSuffix)
	if err != nil {
		return err
	}
	final, err := tablePath(dir, t.Name, sqlSuffix)
	if err != nil {
		return err
	}
	f, err := os.Create(part)
	if err != nil {
		return err
	}
	req := agentapi.ChunkRequest{Table: t.Name, Limit: limit, Scope: scope}
	for {
		res, err := c.DBChunk(req, f)
		if err != nil {
			f.Close()
			return err
		}
		if res.Rows < limit {
			break
		}
		if res.Mode == "keyset" {
			if res.Next == nil {
				f.Close()
				return fmt.Errorf("table %s: keyset chunk without next cursor", t.Name)
			}
			req.After = res.Next
		} else {
			req.Offset += res.Rows
		}
	}
	if err := f.Close(); err != nil {
		return err
	}
	if err := os.Rename(part, final); err != nil {
		return err
	}
	return writeMarker(dir, t)
}

func storeTable(dir string, t agentapi.Table, sql io.Reader) error {
	tmp, err := tablePath(dir, t.Name, partSuffix)
	if err != nil {
		return err
	}
	final, err := tablePath(dir, t.Name, sqlSuffix)
	if err != nil {
		return err
	}
	f, err := os.Create(tmp)
	if err != nil {
		return err
	}
	_, copyErr := io.Copy(f, sql)
	if err := f.Close(); err != nil || copyErr != nil {
		os.Remove(tmp)
		return errors.Join(err, copyErr)
	}
	if err := os.Rename(tmp, final); err != nil {
		return err
	}
	return writeMarker(dir, t)
}

// markerValue ties a downloaded table file to the mode and checksum it was fetched with.
func markerValue(t agentapi.Table) string {
	checksum := ""
	if t.Checksum != nil {
		checksum = *t.Checksum
	}
	return t.Mode + ":" + checksum
}

func writeMarker(dir string, t agentapi.Table) error {
	path, err := tablePath(dir, t.Name, doneSuffix)
	if err != nil {
		return err
	}
	return os.WriteFile(path, []byte(markerValue(t)), 0o644)
}

func markerMatches(dir string, t agentapi.Table) bool {
	if t.Checksum == nil && t.Mode != profile.ModeStructure {
		return false
	}
	path, err := tablePath(dir, t.Name, doneSuffix)
	if err != nil {
		return false
	}
	data, err := os.ReadFile(path)
	return err == nil && strings.TrimSpace(string(data)) == markerValue(t)
}

// ImportReader concatenates header, all table files and footer for one `ddev mysql` import.
// The table files are unchecked server content; importTables feeds them to a hardened client.
func ImportReader(dir string, tables []agentapi.Table) (io.Reader, func() error, error) {
	readers := []io.Reader{strings.NewReader(importHeader)}
	var files []*os.File
	closeAll := func() error {
		var errs []error
		for _, f := range files {
			errs = append(errs, f.Close())
		}
		return errors.Join(errs...)
	}
	for _, t := range tables {
		path, err := tablePath(dir, t.Name, sqlSuffix)
		if err != nil {
			closeAll()
			return nil, nil, err
		}
		f, err := os.Open(path)
		if err != nil {
			closeAll()
			return nil, nil, fmt.Errorf("table %s not downloaded: %w", t.Name, err)
		}
		files = append(files, f)
		readers = append(readers, f)
	}
	readers = append(readers, strings.NewReader(importFooter))
	return io.MultiReader(readers...), closeAll, nil
}
