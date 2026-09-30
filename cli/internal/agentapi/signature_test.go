package agentapi

import "testing"

func TestSignMatchesPHPVector(t *testing.T) {
	payload := Payload("POST", "/wpsync/v1/ping", 1790000000, "0123456789abcdef0123456789abcdef", []byte("{}"))
	got := Sign("test-secret-0123456789abcdef0123456789abcdef", payload)
	want := "c797bbaa5e3decdcf65b9846271bc15ed06bedda7234591ba9793984c05f4d0d"
	if got != want {
		t.Fatalf("Sign() = %s, want %s", got, want)
	}
}
