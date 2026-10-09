package content

import (
	"bufio"
	"bytes"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/safefs"
)

// Key names one row as manifest, baseline and package do: table without prefix and key.
type Key struct{ T, K string }

func (k Key) id() string { return k.T + "\x00" + k.K }

// Change is the state of one key on the live site after a confirmed push (Spec Content-Push §10).
type Change struct {
	// H is the fingerprint the agent computed after writing; nil: the key is gone.
	H *string
	// Row is the normalized row of the package – what the working copy holds; nil for a key the
	// package has no row for.
	Row json.RawMessage
	// Trash: the package moved the post to the trash; the baseline keeps its row with that status.
	// Row then holds at most post_date and post_date_gmt, the dates the post got in the trash.
	Trash bool
}

// Undo holds the lines Patch replaced, per file: "table\x00key" → the line before, null when
// there was none. Unpatch puts them back.
type Undo struct {
	Manifest map[string]json.RawMessage `json:"manifest"`
	Baseline map[string]json.RawMessage `json:"baseline"`
}

type patchLine struct {
	T   string          `json:"t"`
	K   string          `json:"k"`
	H   *string         `json:"h"`
	Row json.RawMessage `json:"row,omitempty"`
	P   *bool           `json:"p,omitempty"`
	Why string          `json:"why,omitempty"`
}

var null = json.RawMessage("null")

// Patch brings manifest.jsonl and baseline.jsonl to the state the push left on the live site:
// the manifest gets the new fingerprints, the baseline the rows of the package. Afterwards the
// working copy and the baseline agree on what was pushed, and the next package is built against
// the fingerprints the site has now. A key the baseline has no row for (rows the agent wrote
// itself, like the trash meta) changes the manifest only.
func Patch(siteDir string, changes map[Key]Change) (*Undo, error) {
	root, err := open(siteDir, false)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	undo := &Undo{Manifest: map[string]json.RawMessage{}, Baseline: map[string]json.RawMessage{}}
	err = rewrite(root, manifestName, undo.Manifest, keysOf(changes), func(k Key, old []byte) ([]byte, bool, error) {
		c := changes[k]
		if c.H == nil {
			return nil, true, nil
		}
		line, err := encode(patchLine{T: k.T, K: k.K, H: c.H})
		return line, true, err
	})
	if err != nil {
		return nil, err
	}
	// What the trash leaves at a post the agent writes itself, as WordPress does. The baseline
	// follows from the row the post had: its name and its status.
	trashed := map[Key]bool{}
	for k, c := range changes {
		if c.Trash && k.T == "posts" {
			trashed[k] = true
		}
	}
	posts, err := rowsOf(root, baselineName, trashed)
	if err != nil {
		if back := restore(root, manifestName, undo.Manifest); back != nil {
			return nil, errors.Join(err, back)
		}
		return nil, err
	}
	err = rewrite(root, baselineName, undo.Baseline, keysOf(changes), func(k Key, old []byte) ([]byte, bool, error) {
		c := changes[k]
		line := patchLine{T: k.T, K: k.K}
		if old != nil {
			if err := json.Unmarshal(old, &line); err != nil {
				return nil, false, fmt.Errorf("%s: %w", baselineName, err)
			}
		}
		line.H = c.H
		switch {
		case c.H == nil:
			return nil, true, nil
		case c.Trash:
			if old == nil {
				return nil, false, nil // the baseline never had the post: nothing to keep
			}
			row := map[string]json.RawMessage{}
			if err := json.Unmarshal(line.Row, &row); err != nil {
				return nil, false, fmt.Errorf("%s: %w", baselineName, err)
			}
			row["post_status"] = packValue("trash")
			if name, ok := unpackValue(row["post_name"]); ok {
				row["post_name"] = packValue(TrashedName(name))
			}
			// The two dates a trash may carry (WordPress sets them when a draft that was never
			// published goes to the trash) – nothing else of its row.
			if c.Row != nil {
				dates := map[string]json.RawMessage{}
				if err := json.Unmarshal(c.Row, &dates); err != nil {
					return nil, false, fmt.Errorf("row of a trash: %w", err)
				}
				for _, name := range []string{"post_date", "post_date_gmt"} {
					if value, ok := dates[name]; ok {
						row[name] = value
					}
				}
			}
			packed, err := json.Marshal(row)
			if err != nil {
				return nil, false, err
			}
			line.Row = packed
		case c.Row != nil:
			line.Row = c.Row
			if old == nil {
				yes := true
				line.P = &yes
			}
		default:
			value, pushable, ok := trashMeta(k, posts)
			if !ok {
				return nil, false, nil // no row to put there: the baseline stays as it is
			}
			// add_post_meta() appends: what the pair held stays in front.
			var set struct {
				Values []json.RawMessage `json:"values"`
			}
			if old != nil && json.Unmarshal(line.Row, &set) != nil {
				return nil, false, fmt.Errorf("%s: row of %s is no set", baselineName, k.T)
			}
			set.Values = append(set.Values, packValue(value))
			packed, err := json.Marshal(set)
			if err != nil {
				return nil, false, err
			}
			line.Row = packed
			if old == nil {
				line.P = &pushable
				if !pushable {
					line.Why = "meta_key"
				}
			}
		}
		out, err := encode(line)
		return out, true, err
	})
	if err != nil {
		// Both files or neither: a manifest with the new fingerprints next to a baseline with the old
		// rows would name every pushed row as changed locally.
		if back := restore(root, manifestName, undo.Manifest); back != nil {
			return nil, errors.Join(err, back)
		}
		return nil, err
	}
	return undo, nil
}

// Unpatch takes Patch back: every line it replaced or added is as before.
func Unpatch(siteDir string, undo *Undo) error {
	root, err := open(siteDir, false)
	if err != nil {
		return err
	}
	defer root.Close()
	for name, lines := range map[string]map[string]json.RawMessage{manifestName: undo.Manifest, baselineName: undo.Baseline} {
		if err := restore(root, name, lines); err != nil {
			return err
		}
	}
	return nil
}

// restore puts the lines Patch replaced in one file back: "table\x00key" → the line before.
func restore(root *os.Root, name string, lines map[string]json.RawMessage) error {
	keys := map[Key]bool{}
	for id := range lines {
		t, k, ok := bytes.Cut([]byte(id), []byte{0})
		if !ok {
			return fmt.Errorf("undo of %s names no key", name)
		}
		keys[Key{T: string(t), K: string(k)}] = true
	}
	if len(keys) == 0 {
		return nil
	}
	return rewrite(root, name, nil, keys, func(k Key, old []byte) ([]byte, bool, error) {
		before := lines[k.id()]
		if bytes.Equal(before, null) || len(before) == 0 {
			return nil, true, nil
		}
		return before, true, nil
	})
}

// TrashedName is the post_name WordPress gives a post on its way to the trash
// (wp_add_trashed_suffix_to_post_name_for_post): the name, cut to 191 bytes and without a dash
// at its end, with "__trashed" – unless it carries the suffix already. The agent may add a
// number to keep it unique; the fingerprint of the line is always the agent's.
func TrashedName(name string) string {
	if strings.HasSuffix(name, "__trashed") {
		return name
	}
	if len(name) > 191 {
		name = strings.ToValidUTF8(name[:191], "")
	}
	return strings.TrimRight(name, "-") + "__trashed"
}

func packValue(s string) json.RawMessage {
	out, _ := json.Marshal(base64.StdEncoding.EncodeToString([]byte(s)))
	return out
}

func unpackValue(raw json.RawMessage) (string, bool) {
	var packed string
	if json.Unmarshal(raw, &packed) != nil {
		return "", false
	}
	value, err := base64.StdEncoding.DecodeString(packed)
	return string(value), err == nil
}

// trashMeta answers for a meta key the agent wrote at a trashed post the value it has there and
// whether the lists let it be pushed: _wp_desired_post_slug holds the name the post had – not
// written when the name carried the suffix already –, _wp_trash_meta_status its status. The
// time of the trash only the agent knows.
func trashMeta(k Key, posts map[Key]map[string]json.RawMessage) (value string, pushable, ok bool) {
	object, name, pair := strings.Cut(k.K, "\x00")
	row, known := posts[Key{T: "posts", K: object}]
	if k.T != "postmeta" || !pair || !known {
		return "", false, false
	}
	switch name {
	case "_wp_desired_post_slug":
		value, ok = unpackValue(row["post_name"])
		return value, true, ok && !strings.HasSuffix(value, "__trashed")
	case "_wp_trash_meta_status":
		value, ok = unpackValue(row["post_status"])
		return value, false, ok
	}
	return "", false, false
}

// rowsOf returns the rows the baseline holds for keys.
func rowsOf(root *os.Root, name string, keys map[Key]bool) (map[Key]map[string]json.RawMessage, error) {
	out := map[Key]map[string]json.RawMessage{}
	if len(keys) == 0 {
		return out, nil
	}
	f, err := safefs.Open(root, name)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	br := bufio.NewReaderSize(f, 1<<20)
	for {
		data, readErr := agentapi.ReadLine(br, maxRecordLine)
		if errors.Is(readErr, agentapi.ErrLineTooLong) {
			return nil, fmt.Errorf("%s: %w (mehr als %d Bytes)", name, readErr, maxRecordLine)
		}
		var line patchLine
		if bytes.HasPrefix(data, []byte(`{"t":`)) && json.Unmarshal(data, &line) == nil && keys[Key{line.T, line.K}] {
			row := map[string]json.RawMessage{}
			if json.Unmarshal(line.Row, &row) == nil {
				out[Key{line.T, line.K}] = row
			}
		}
		if readErr != nil {
			if errors.Is(readErr, io.EOF) {
				return out, nil
			}
			return nil, readErr
		}
	}
}

func keysOf(changes map[Key]Change) map[Key]bool {
	keys := make(map[Key]bool, len(changes))
	for k := range changes {
		keys[k] = true
	}
	return keys
}

func encode(line patchLine) ([]byte, error) {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(line); err != nil {
		return nil, err
	}
	return bytes.TrimRight(b.Bytes(), "\n"), nil
}

// rewrite streams a JSON-Lines file through change: for every key of keys it is asked once – with
// the line the file has for it, or nil at the end when the file has none – and answers the line to
// write (nil: none) and whether it touched the key. undo, if given, gets the line before for every
// touched key. Other lines pass as they are.
func rewrite(root *os.Root, name string, undo map[string]json.RawMessage, keys map[Key]bool, change func(k Key, old []byte) ([]byte, bool, error)) error {
	in, err := safefs.Open(root, name)
	if err != nil {
		return err
	}
	defer in.Close()
	return writeFile(root, name, func(w io.Writer) error {
		seen := map[Key]bool{}
		put := func(k Key, old []byte) error {
			line, touched, err := change(k, old)
			if err != nil {
				return err
			}
			if !touched {
				line = old
			} else if undo != nil {
				if old == nil {
					undo[k.id()] = null
				} else {
					undo[k.id()] = append(json.RawMessage{}, old...)
				}
			}
			if line == nil {
				return nil
			}
			_, err = w.Write(append(append([]byte{}, line...), '\n'))
			return err
		}
		br := bufio.NewReaderSize(in, 1<<20)
		for {
			data, readErr := agentapi.ReadLine(br, maxRecordLine)
			if errors.Is(readErr, agentapi.ErrLineTooLong) {
				return fmt.Errorf("%s: %w (mehr als %d Bytes)", name, readErr, maxRecordLine)
			}
			line := bytes.TrimRight(data, "\r\n")
			if len(line) > 0 {
				var rec recordLine
				if bytes.HasPrefix(line, []byte(`{"t":`)) && json.Unmarshal(line, &rec) == nil && keys[Key{rec.T, rec.K}] && !seen[Key{rec.T, rec.K}] {
					seen[Key{rec.T, rec.K}] = true
					if err := put(Key{rec.T, rec.K}, line); err != nil {
						return err
					}
				} else if _, err := w.Write(append(line, '\n')); err != nil {
					return err
				}
			}
			if readErr != nil {
				if !errors.Is(readErr, io.EOF) {
					return readErr
				}
				break
			}
		}
		var rest []Key
		for k := range keys {
			if !seen[k] {
				rest = append(rest, k)
			}
		}
		sort.Slice(rest, func(i, j int) bool { return rest[i].id() < rest[j].id() })
		for _, k := range rest {
			if err := put(k, nil); err != nil {
				return err
			}
		}
		return nil
	})
}
