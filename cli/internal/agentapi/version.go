package agentapi

import (
	"fmt"
	"strconv"
	"strings"
)

// MinAgentVersion is the oldest agent this CLI pulls from: older agents cannot pseudonymize
// (Spec 11, AC-36). Scan and pair report it, pull enforces it.
const MinAgentVersion = "0.3.0"

// OutdatedError: the agent is older than the CLI needs (exit code agent_outdated).
type OutdatedError struct {
	Installed string // "" if the agent does not report its version
	Required  string
	Err       error // the concrete reason, e.g. pull.ErrAgentCannotAnonymize
}

func (e *OutdatedError) Error() string {
	installed := e.Installed
	if installed == "" {
		installed = "unbekannt"
	}
	msg := fmt.Sprintf("wpsync-Agent veraltet (installiert: %s, nötig: %s)", installed, e.Required)
	if e.Err != nil {
		msg += ": " + e.Err.Error()
	}
	return msg
}

func (e *OutdatedError) Unwrap() error { return e.Err }

// VersionAtLeast compares dotted numeric versions ("0.3.1" ≥ "0.3.0"). An unparsable or empty
// have counts as too old.
func VersionAtLeast(have, want string) bool {
	h, ok1 := parseVersion(have)
	w, ok2 := parseVersion(want)
	if !ok1 || !ok2 {
		return false
	}
	for i := 0; i < 3; i++ {
		if h[i] != w[i] {
			return h[i] > w[i]
		}
	}
	return true
}

func parseVersion(v string) ([3]int, bool) {
	var out [3]int
	parts := strings.Split(strings.TrimPrefix(strings.TrimSpace(v), "v"), ".")
	if len(parts) == 0 || len(parts) > 3 || parts[0] == "" {
		return out, false
	}
	for i, p := range parts {
		n, err := strconv.Atoi(p)
		if err != nil || n < 0 {
			return out, false
		}
		out[i] = n
	}
	return out, true
}

// AtLeast compares dotted versions numerically ("0.10.0" ≥ "0.4.0"); unreadable versions fail.
func AtLeast(version, minimum string) bool {
	parse := func(v string) ([]int, bool) {
		v, _, _ = strings.Cut(v, "-")
		var out []int
		for _, part := range strings.Split(v, ".") {
			n, err := strconv.Atoi(part)
			if err != nil {
				return nil, false
			}
			out = append(out, n)
		}
		return out, true
	}
	have, ok := parse(version)
	want, _ := parse(minimum)
	if !ok {
		return false
	}
	for i := range want {
		h := 0
		if i < len(have) {
			h = have[i]
		}
		if h != want[i] {
			return h > want[i]
		}
	}
	return true
}
