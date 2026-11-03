package pull

import (
	"context"
	"errors"
	"io"
	"net"
	"syscall"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/safefs"
)

// isTransient reports whether a failed transfer is worth repeating: the connection was cut or
// reset, the body ended early, a timeout, or the site answered 502/503/504. Everything else
// (403, a forbidden path, no space, a wrong checksum) is final, and so is a suspected ban – retries
// extend fail2ban bans (Konzept E16, AC-26) – and a cancelled pull.
func isTransient(err error) bool {
	if err == nil || errors.Is(err, agentapi.ErrSuspectedBan) || errors.Is(err, agentapi.ErrUnreachable) || errors.Is(err, context.Canceled) {
		return false
	}
	var api *agentapi.APIError
	if errors.As(err, &api) {
		return api.Status == 502 || api.Status == 503 || api.Status == 504
	}
	if errors.Is(err, io.ErrUnexpectedEOF) || errors.Is(err, io.EOF) || errors.Is(err, safefs.ErrShortBody) ||
		errors.Is(err, syscall.ECONNRESET) || errors.Is(err, syscall.ECONNABORTED) || errors.Is(err, syscall.EPIPE) ||
		errors.Is(err, context.DeadlineExceeded) {
		return true
	}
	var netErr net.Error
	return errors.As(err, &netErr) && netErr.Timeout()
}
