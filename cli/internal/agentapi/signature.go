// Package agentapi talks to the wpsync WordPress agent.
package agentapi

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"strconv"
	"strings"
)

// Payload builds "METHOD\nROUTE\nTIMESTAMP\nNONCE\nsha256(BODY)" exactly like the agent (Signature.php).
func Payload(method, route string, timestamp int64, nonce string, body []byte) string {
	sum := sha256.Sum256(body)
	return strings.Join([]string{method, route, strconv.FormatInt(timestamp, 10), nonce, hex.EncodeToString(sum[:])}, "\n")
}

// Sign returns the hex HMAC-SHA256 of payload.
func Sign(secret, payload string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(payload))
	return hex.EncodeToString(mac.Sum(nil))
}
