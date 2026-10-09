// Package content is the local side of the content push (Spec Content-Push §4, §5): the manifest
// of the live site, the domain map of the pull, the baseline and the export of the working copy.
// Fingerprints are computed in PHP only (C1) – on the site by the agent, locally by the same
// files, which this package embeds and sends to `wp eval-file -`.
package content

import (
	"bytes"
	"embed"
)

// CanonVersion is the canonical form this CLI works with (Canon::VERSION of the embedded PHP).
const CanonVersion = 1

//go:embed php/*.php
var phpFS embed.FS

// agentFiles are copies of agent/src in load order; TestPHPMatchesAgent keeps them equal.
var agentFiles = []string{"SerializedWalker.php", "ContentOrigin.php", "Canon.php", "ContentReader.php"}

// Script returns the PHP for `wp eval-file -`: the agent's classes followed by the driver.
// WP-CLI evals the code, so it opens PHP once and starts with the namespace statement.
func Script(driver string) []byte {
	var b bytes.Buffer
	b.WriteString("<?php\n")
	for _, name := range append(append([]string{}, agentFiles...), driver) {
		data, err := phpFS.ReadFile("php/" + name)
		if err != nil {
			panic(err) // embedded at build time: a missing file is a programming error
		}
		b.Write(bytes.TrimLeft(bytes.TrimPrefix(data, []byte("<?php")), "\r\n"))
		b.WriteString("\n")
	}
	return b.Bytes()
}
