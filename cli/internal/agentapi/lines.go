package agentapi

import (
	"bufio"
	"errors"
)

// ErrLineTooLong: a line of a JSON-Lines stream is longer than its reader allows.
var ErrLineTooLong = errors.New("Zeile zu lang")

// ReadLine returns the next line of br with its '\n' (the last line may lack it), like
// bufio.Reader.ReadBytes – but it never holds more than max bytes: a longer line is
// ErrLineTooLong, and the rest of it stays unread.
func ReadLine(br *bufio.Reader, max int) ([]byte, error) {
	var line []byte
	for {
		chunk, err := br.ReadSlice('\n')
		if len(line)+len(chunk) > max {
			return nil, ErrLineTooLong
		}
		line = append(line, chunk...)
		if !errors.Is(err, bufio.ErrBufferFull) {
			return line, err
		}
	}
}
