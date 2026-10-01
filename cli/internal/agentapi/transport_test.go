package agentapi

import "testing"

func TestIsHTTPS(t *testing.T) {
	cases := map[string]bool{
		"https://www.example.com":                  true,
		"HTTPS://www.example.com/blog":             true,
		"http://www.example.com":                   false,
		"http://wpsync-e2e-source.ddev.site:33000": false,
		"www.example.com":                          false,
		"://broken":                                false,
	}
	for raw, want := range cases {
		if got := IsHTTPS(raw); got != want {
			t.Errorf("IsHTTPS(%q) = %v, want %v", raw, got, want)
		}
	}
}
