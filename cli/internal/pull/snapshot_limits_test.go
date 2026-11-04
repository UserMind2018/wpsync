package pull

import (
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
)

func TestPlausibleSnapshotLimits(t *testing.T) {
	ok := func() *agentapi.SnapshotStatus {
		return &agentapi.SnapshotStatus{
			BlockSize: agentapi.SnapshotBlockSize, Blocks: 2, Size: agentapi.SnapshotBlockSize + 10,
			BytesPlain: 5 << 20, SHA256: strings.Repeat("a", 64),
		}
	}
	if !plausibleSnapshot(ok(), 4<<20) {
		t.Fatal("a normal snapshot is refused")
	}
	bomb := ok()
	bomb.BytesPlain = 1 << 40 // 1 TiB from about 1 MiB: more than gzip can do
	if plausibleSnapshot(bomb, 1<<40) {
		t.Fatal("a gzip bomb ratio is accepted")
	}
	huge := ok()
	huge.Size = 10*agentapi.SnapshotBlockSize - 1
	huge.Blocks = 10
	huge.BytesPlain = 4 << 30 // plausible ratio, but far above what the tables announce
	if plausibleSnapshot(huge, 1<<20) {
		t.Fatal("a dump far above the announced table bytes is accepted")
	}
}
