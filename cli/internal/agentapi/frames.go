package agentapi

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"strconv"
	"strings"
)

// FileHandler receives one file; it may read body partially – the rest is drained.
type FileHandler func(path string, size, mtime int64, body io.Reader) error

// TableHandler receives the SQL of one table.
type TableHandler func(name string, rows int, sql io.Reader) error

// ReadFileFrames parses "F path\tsize\tmtime\n<bytes>\n", "M path\n" and "E\n".
func ReadFileFrames(r io.Reader, onFile FileHandler, onMissing func(path string)) error {
	br := bufio.NewReaderSize(r, 1<<20)
	for {
		line, err := readLine(br)
		if err != nil {
			return err
		}
		switch {
		case line == "E":
			return nil
		case strings.HasPrefix(line, "M "):
			if onMissing != nil {
				onMissing(line[2:])
			}
		case strings.HasPrefix(line, "F "):
			parts := strings.Split(line[2:], "\t")
			if len(parts) != 3 {
				return fmt.Errorf("bad file frame %q", line)
			}
			size, err1 := strconv.ParseInt(parts[1], 10, 64)
			mtime, err2 := strconv.ParseInt(parts[2], 10, 64)
			if err1 != nil || err2 != nil || size < 0 {
				return fmt.Errorf("bad file frame %q", line)
			}
			err := consume(br, size, func(body io.Reader) error { return onFile(parts[0], size, mtime, body) })
			if err != nil {
				return fmt.Errorf("file %s: %w", parts[0], err)
			}
		default:
			return fmt.Errorf("unexpected frame %q", line)
		}
	}
}

// ReadTableFrames parses "T name\trows\tbytes\n<sql>\n" and "E\n" and returns the received tables.
// The agent may end early to respect its time budget; callers request the rest again.
func ReadTableFrames(r io.Reader, onTable TableHandler) ([]string, error) {
	br := bufio.NewReaderSize(r, 1<<20)
	var received []string
	for {
		line, err := readLine(br)
		if err != nil {
			return received, err
		}
		if line == "E" {
			return received, nil
		}
		parts := strings.Split(strings.TrimPrefix(line, "T "), "\t")
		if !strings.HasPrefix(line, "T ") || len(parts) != 3 {
			return received, fmt.Errorf("unexpected frame %q", line)
		}
		rows, err1 := strconv.Atoi(parts[1])
		size, err2 := strconv.ParseInt(parts[2], 10, 64)
		if err1 != nil || err2 != nil || size < 0 {
			return received, fmt.Errorf("bad table frame %q", line)
		}
		if err := consume(br, size, func(sql io.Reader) error { return onTable(parts[0], rows, sql) }); err != nil {
			return received, fmt.Errorf("table %s: %w", parts[0], err)
		}
		received = append(received, parts[0])
	}
}

func readLine(br *bufio.Reader) (string, error) {
	line, err := br.ReadString('\n')
	if err != nil {
		return "", fmt.Errorf("stream truncated: %w", err)
	}
	return strings.TrimSuffix(line, "\n"), nil
}

func consume(br *bufio.Reader, size int64, handle func(io.Reader) error) error {
	body := io.LimitReader(br, size)
	if err := handle(body); err != nil {
		return err
	}
	if _, err := io.Copy(io.Discard, body); err != nil {
		return err
	}
	b, err := br.ReadByte()
	if err != nil || b != '\n' {
		return errors.New("frame terminator missing (content changed during transfer?)")
	}
	return nil
}
