package pull

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/profile"
	"github.com/usermind/wpsync/internal/safefs"
)

// DBOptions controls chunking and the pull scope.
type DBOptions struct {
	RowsPerChunk int
	BundleBytes  int64
	Scope        agentapi.Scope
	// Progress (may be nil) reports every finished table – already downloaded ones included – and,
	// at most once per progressInterval, the chunks of a large table in between.
	Progress func(DBProgress)
	// Now is the clock of that limit; nil = time.Now.
	Now func() time.Time
}

// DBProgress is one progress report of the DB download.
type DBProgress struct {
	// Done counts finished tables out of Total; it stays the same while a table arrives in chunks.
	Done, Total int
	// Table is the table that just finished or, between two chunks, the one being loaded.
	Table string
	// BytesDone is the SQL that lies in the cache for the tables of this run: what was received
	// plus the files of tables a previous, interrupted run had finished. BytesTotal is the sum
	// of the site's size estimates (transferBytes). The two measure different things and need
	// not meet; BytesDone is not capped at BytesTotal.
	BytesDone, BytesTotal int64
}

// progressInterval is the least time between two reports that do not finish a table.
const progressInterval = time.Second

// dbProgress counts tables and bytes of one DB download and reports them.
type dbProgress struct {
	report func(DBProgress)
	now    func() time.Time
	last   time.Time // time of the last report; zero before the first
	state  DBProgress
}

func newDBProgress(tables []agentapi.Table, o DBOptions) *dbProgress {
	p := &dbProgress{report: o.Progress, now: o.Now, state: DBProgress{Total: len(tables)}}
	if p.now == nil {
		p.now = time.Now
	}
	for _, t := range tables {
		p.state.BytesTotal += transferBytes(t)
	}
	return p
}

// add counts received bytes without reporting.
func (p *dbProgress) add(n int64) { p.state.BytesDone += n }

// chunk reports the table being loaded, unless the last report is less than progressInterval ago.
func (p *dbProgress) chunk(table string) {
	if p.report == nil {
		return
	}
	now := p.now()
	if !p.last.IsZero() && now.Sub(p.last) < progressInterval {
		return
	}
	p.emit(table, now)
}

// finished counts table as done and always reports it.
func (p *dbProgress) finished(table string) {
	p.state.Done++
	if p.report != nil {
		p.emit(table, p.now())
	}
}

func (p *dbProgress) emit(table string, now time.Time) {
	p.last = now
	p.state.Table = table
	p.report(p.state)
}

const (
	importHeader = "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO';\n"
	importFooter = "SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;\n"
)

// DownloadTables stores each table as dir/<name>.sql plus a dir/<name>.done marker.
// Small tables travel together (Spike B16), large ones per keyset chunk (Spike B26).
func DownloadTables(c *agentapi.Client, dir string, tables []agentapi.Table, o DBOptions) error {
	// All names first: a valid table must not be requested before an invalid one aborts the run.
	if err := checkTableFiles(tables); err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		return err
	}
	defer root.Close()
	return downloadTables(c, root, tables, o)
}

// openTables opens the DB cache <siteDir>/.wpsync/db/tables. On the Mac it lies in the DDEV
// mount: no folder on the way may be a symlink, and every file operation stays inside (SEC-113).
func openTables(siteDir string) (*os.Root, error) {
	if err := os.MkdirAll(siteDir, 0o755); err != nil {
		return nil, err
	}
	return safefs.OpenTree(siteDir, filepath.Join(".wpsync", "db", "tables"))
}

func checkTableFiles(tables []agentapi.Table) error {
	for _, t := range tables {
		if _, err := tableFile(t.Name, sqlSuffix); err != nil {
			return err
		}
	}
	return nil
}

// downloadTables is DownloadTables on the opened cache folder.
func downloadTables(c *agentapi.Client, dir *os.Root, tables []agentapi.Table, o DBOptions) error {
	if err := checkTableFiles(tables); err != nil {
		return err
	}
	progress := newDBProgress(tables, o)
	var small, large []agentapi.Table
	for _, t := range tables {
		switch {
		case markerMatches(dir, t):
			progress.add(storedBytes(dir, t))
			progress.finished(t.Name)
		case t.Mode == profile.ModeStructure || (t.Rows <= int64(o.RowsPerChunk) && t.Bytes <= o.BundleBytes):
			small = append(small, t)
		default:
			large = append(large, t)
		}
	}
	for _, group := range tableGroups(small, o.BundleBytes) {
		if err := fetchBundles(c, dir, group, o.RowsPerChunk, o.Scope, progress); err != nil {
			return err
		}
	}
	for _, t := range large {
		if err := fetchChunks(c, dir, t, o.RowsPerChunk, o.Scope, progress); err != nil {
			return err
		}
		progress.finished(t.Name)
	}
	return nil
}

// storedBytes is the size of a table file a previous run left in the cache (0 if unreadable).
func storedBytes(dir *os.Root, t agentapi.Table) int64 {
	name, err := tableFile(t.Name, sqlSuffix)
	if err != nil {
		return 0
	}
	info, err := safefs.Lstat(dir, name)
	if err != nil || !info.Mode().IsRegular() {
		return 0
	}
	return info.Size()
}

// countingWriter counts what passes through to w.
type countingWriter struct {
	w io.Writer
	n int64
}

func (c *countingWriter) Write(b []byte) (int, error) {
	n, err := c.w.Write(b)
	c.n += int64(n)
	return n, err
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

func fetchBundles(c *agentapi.Client, dir *os.Root, group []agentapi.Table, limit int, scope agentapi.Scope, progress *dbProgress) error {
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
			n, err := storeTable(dir, t, sql)
			progress.add(n)
			if err != nil {
				return err
			}
			progress.finished(name)
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

func fetchChunks(c *agentapi.Client, dir *os.Root, t agentapi.Table, limit int, scope agentapi.Scope, progress *dbProgress) error {
	part, err := tableFile(t.Name, partSuffix)
	if err != nil {
		return err
	}
	final, err := tableFile(t.Name, sqlSuffix)
	if err != nil {
		return err
	}
	f, err := createFresh(dir, part)
	if err != nil {
		return err
	}
	req := agentapi.ChunkRequest{Table: t.Name, Limit: limit, Scope: scope}
	for {
		into := &countingWriter{w: f}
		res, err := c.DBChunk(req, into)
		progress.add(into.n)
		if err != nil {
			f.Close()
			return err
		}
		if res.Rows < limit {
			break // the caller reports the finished table
		}
		progress.chunk(t.Name)
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
	if err := dir.Rename(part, final); err != nil {
		return err
	}
	return writeMarker(dir, t)
}

// createFresh creates name anew without following a symlink that is already there.
func createFresh(dir *os.Root, name string) (*os.File, error) {
	if err := dir.Remove(name); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return nil, err
	}
	return dir.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
}

// storeTable writes one table of a bundle and returns the bytes it received.
func storeTable(dir *os.Root, t agentapi.Table, sql io.Reader) (int64, error) {
	tmp, err := tableFile(t.Name, partSuffix)
	if err != nil {
		return 0, err
	}
	final, err := tableFile(t.Name, sqlSuffix)
	if err != nil {
		return 0, err
	}
	f, err := createFresh(dir, tmp)
	if err != nil {
		return 0, err
	}
	n, copyErr := io.Copy(f, sql)
	if err := f.Close(); err != nil || copyErr != nil {
		dir.Remove(tmp)
		return n, errors.Join(err, copyErr)
	}
	if err := dir.Rename(tmp, final); err != nil {
		return n, err
	}
	return n, writeMarker(dir, t)
}

// markerValue ties a downloaded table file to the mode and checksum it was fetched with.
func markerValue(t agentapi.Table) string {
	checksum := ""
	if t.Checksum != nil {
		checksum = *t.Checksum
	}
	return t.Mode + ":" + checksum
}

func writeMarker(dir *os.Root, t agentapi.Table) error {
	name, err := tableFile(t.Name, doneSuffix)
	if err != nil {
		return err
	}
	return safefs.WriteFile(dir, name, strings.NewReader(markerValue(t)), -1, time.Time{}, 0o644)
}

func markerMatches(dir *os.Root, t agentapi.Table) bool {
	if t.Checksum == nil && t.Mode != profile.ModeStructure {
		return false
	}
	name, err := tableFile(t.Name, doneSuffix)
	if err != nil {
		return false
	}
	data, err := safefs.ReadFile(dir, name)
	return err == nil && strings.TrimSpace(string(data)) == markerValue(t)
}

// ImportReader concatenates header, all table files and footer for one `ddev mysql` import.
// The table files are unchecked server content; importTables feeds them to a hardened client.
func ImportReader(dir string, tables []agentapi.Table) (io.Reader, func() error, error) {
	if err := checkTableFiles(tables); err != nil {
		return nil, nil, err
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, nil, err
	}
	r, closeAll, err := importReader(root, tables)
	if err != nil {
		root.Close()
		return nil, nil, err
	}
	return r, func() error { return errors.Join(closeAll(), root.Close()) }, nil
}

// importReader is ImportReader on the opened cache folder; a table file that is a symlink is refused.
func importReader(dir *os.Root, tables []agentapi.Table) (io.Reader, func() error, error) {
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
		name, err := tableFile(t.Name, sqlSuffix)
		if err != nil {
			closeAll()
			return nil, nil, err
		}
		f, err := safefs.Open(dir, name)
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
