package push

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/baseline"
)

// UploadsUnit is the unit of new files below wp-content/uploads (Spec Content-Push §8.1). Its paths
// are relative to wp-content/uploads/; a push has at most one, and it only ever adds files.
const UploadsUnit = "uploads"

// MinAgentUploads is the first agent version that takes the unit uploads.
const MinAgentUploads = "0.6.0"

// MaxUploads bounds the files of one push, as the agent does (PushUploads::MAX_FILES).
const MaxUploads = 5000

// maxListBytes bounds the list file of --uploads.
const maxListBytes = 1 << 20

var (
	// ErrAgentNoUploads: the agent cannot take the unit uploads (agent < 0.6.0).
	ErrAgentNoUploads = errors.New("der Agent auf der Site kennt noch keine Uploads")
	// ErrUploadExists: a file of the list lies on the site with other content (Spec §8.2, W3). A
	// push never replaces an upload, --force or not.
	ErrUploadExists = errors.New("auf der Site liegt am selben Pfad schon eine andere Datei")
	// ErrUploadTypeBlocked: executable code, server configuration, hidden files and types WordPress
	// does not allow on the site never go out as uploads (Spec §8.3).
	ErrUploadTypeBlocked = errors.New("dieser Dateityp geht nie als Upload auf die Site")
	// ErrUploadMissing: a file of the list is no regular file below the local wp-content/uploads.
	ErrUploadMissing = errors.New("liegt lokal nicht vor")
	// ErrUploadsThere: no code to push and every file of the list is on the site already.
	ErrUploadsThere = fmt.Errorf("%w – alle Dateien aus --uploads liegen schon auf der Site", ErrNothing)
)

// uploadBlockedRe mirrors PushUploads::BLOCKED: executable and active types (SVG, HTML, XML,
// JavaScript), whatever upload_mimes allows on the site, also as an inner extension.
var uploadBlockedRe = regexp.MustCompile(`(?i)\.(php[0-9]?|phtml|phar|pht|phps|svgz?|x?html?|shtml|xml|m?js)(\.|$)`)

// middleExtRe is what WordPress' sanitize_file_name() takes for an extension between the first and
// the last dot of a name; unless it is an allowed type, WordPress appends "_" (bild.cgi.png →
// bild.cgi_.png) and the agent refuses the name. Ordinary words count too: foto.final.v2.jpg would
// become foto.final_.v2_.jpg on the site, so the CLI refuses it as well.
var middleExtRe = regexp.MustCompile(`^[a-zA-Z]{2,5}[0-9]?$`)

// middleExtOK are inner extensions the CLI lets through: common image, media and document types
// WordPress allows by default. Only a rough local check – the agent decides with the site's list.
var middleExtOK = map[string]bool{
	"jpg": true, "jpeg": true, "jpe": true, "png": true, "gif": true, "webp": true, "avif": true, "bmp": true,
	"tif": true, "tiff": true, "ico": true, "heic": true, "heif": true, "pdf": true, "mp4": true, "m4v": true,
	"mov": true, "webm": true, "ogv": true, "mp3": true, "m4a": true, "ogg": true, "oga": true, "wav": true,
	"flac": true, "doc": true, "docx": true, "xls": true, "xlsx": true, "ppt": true, "pptx": true, "odt": true,
	"ods": true, "odp": true, "rtf": true, "txt": true, "csv": true, "zip": true, "tar": true,
}

// hiddenMiddleExt reports an inner extension of name that WordPress would rename (see middleExtRe).
func hiddenMiddleExt(name string) bool {
	parts := strings.Split(name, ".")
	if len(parts) <= 2 {
		return false
	}
	for _, p := range parts[1 : len(parts)-1] {
		if middleExtRe.MatchString(p) && !middleExtOK[strings.ToLower(p)] {
			return true
		}
	}
	return false
}

// UploadExistsError names the files that lie on the site with other content.
type UploadExistsError struct{ Paths []string }

func (e *UploadExistsError) Error() string {
	shown := make([]string, 0, 6)
	for i, p := range e.Paths {
		if i == 5 {
			shown = append(shown, fmt.Sprintf("und %d weitere", len(e.Paths)-5))
			break
		}
		shown = append(shown, agentapi.Printable(p))
	}
	return fmt.Sprintf("%v: %s", ErrUploadExists, strings.Join(shown, ", "))
}

func (e *UploadExistsError) Unwrap() error { return ErrUploadExists }

// UploadTypeError names a file of the list the fixed type check refuses before any request.
type UploadTypeError struct{ Path string }

func (e *UploadTypeError) Error() string {
	return fmt.Sprintf("%s: %v", agentapi.Printable("wp-content/uploads/"+e.Path), ErrUploadTypeBlocked)
}

func (e *UploadTypeError) Unwrap() error { return ErrUploadTypeBlocked }

// ValidUploadPath mirrors the path rules of the agent (PushUploads::validFile): relative to
// wp-content/uploads/, no way out, nothing a terminal would interpret, no VCS, staging or push
// work folders.
func ValidUploadPath(rel string) bool {
	if rel == "" || len(rel) > 1024 || strings.Contains(rel, `\`) || strings.ContainsFunc(rel, agentapi.Unsafe) {
		return false
	}
	for _, s := range strings.Split(rel, "/") {
		l := strings.ToLower(s)
		if s == "" || s == "." || s == ".." || l == ".git" || l == ".svn" || l == ".hg" ||
			strings.HasPrefix(l, "wpsync-staging-") || strings.HasPrefix(l, "wpsync-push-") {
			return false
		}
	}
	return true
}

// BlockedUpload mirrors the fixed part of the agent's type check (PushUploads::blockedName):
// executable and active extensions, hidden files (.htaccess, .user.ini), logs, dumps and what the
// CLI never pushes anyway – plus a rough check of inner extensions (hiddenMiddleExt). Whether
// WordPress allows the type decides the agent.
func BlockedUpload(rel string) bool {
	name := path.Base(rel)
	lower := strings.ToLower(name)
	return strings.HasPrefix(name, ".") || uploadBlockedRe.MatchString(name) || hiddenMiddleExt(name) || strings.HasSuffix(lower, ".log") ||
		strings.HasSuffix(lower, ".sql") || strings.HasSuffix(lower, ".sql.gz") || Ignored(UploadsUnit, rel, 0)
}

// ReadUploadList reads the file of push --uploads: one path per line relative to
// wp-content/uploads/, blank lines and lines starting with # ignored, spaces around a path
// trimmed, each path once.
func ReadUploadList(file string) ([]string, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, fmt.Errorf("--uploads: %w", err)
	}
	defer f.Close()
	data, err := io.ReadAll(io.LimitReader(f, maxListBytes+1))
	if err != nil {
		return nil, fmt.Errorf("--uploads: %w", err)
	}
	if len(data) > maxListBytes {
		return nil, errors.New("--uploads: die Liste ist grösser als 1 MB")
	}
	var list []string
	seen := map[string]bool{}
	for n, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if !ValidUploadPath(line) {
			return nil, fmt.Errorf("--uploads Zeile %d: %s ist kein Pfad relativ zu wp-content/uploads/", n+1, agentapi.Printable(line))
		}
		if !seen[line] {
			seen[line] = true
			list = append(list, line)
		}
	}
	switch {
	case len(list) == 0:
		return nil, errors.New("--uploads: die Liste nennt keine Datei")
	case len(list) > MaxUploads:
		return nil, fmt.Errorf("--uploads: höchstens %d Dateien je Push, die Liste nennt %d", MaxUploads, len(list))
	}
	return list, nil
}

// uploadsUnit builds the unit uploads from the list: every file must be a regular file below
// docroot/wp-content/uploads, reached without a symlink. It is read through a root pinned to the
// folder like a code unit; Hash and upload use the same unit.
func uploadsUnit(docroot string, list []string) (Unit, error) {
	if _, ok, err := realDir(docroot, "wp-content/"+UploadsUnit); err != nil {
		return Unit{}, err
	} else if !ok {
		return Unit{}, fmt.Errorf("wp-content/%s %w", UploadsUnit, ErrUploadMissing)
	}
	root, dir, err := openUnit(docroot, UploadsUnit, nil)
	if err != nil {
		return Unit{}, err
	}
	defer root.Close()
	u := Unit{Path: UploadsUnit, Files: map[string]LocalFile{}, Base: map[string]baseline.FileStamp{}, dir: dir}
	for _, rel := range list {
		if BlockedUpload(rel) {
			return Unit{}, &UploadTypeError{Path: rel}
		}
		where := "wp-content/" + UploadsUnit + "/" + showPath(rel)
		parts := strings.Split(rel, "/")
		for i := 1; i < len(parts); i++ {
			info, err := root.Lstat(filepath.FromSlash(strings.Join(parts[:i], "/")))
			switch {
			case errors.Is(err, fs.ErrNotExist):
				return Unit{}, fmt.Errorf("%s %w", where, ErrUploadMissing)
			case err != nil:
				return Unit{}, err
			case info.Mode()&fs.ModeSymlink != 0:
				return Unit{}, fmt.Errorf("%s %w, Push abgebrochen", where, ErrSymlink)
			case !info.IsDir():
				return Unit{}, fmt.Errorf("%s %w", where, ErrUploadMissing)
			}
		}
		info, err := root.Lstat(filepath.FromSlash(rel))
		switch {
		case errors.Is(err, fs.ErrNotExist):
			return Unit{}, fmt.Errorf("%s %w", where, ErrUploadMissing)
		case errors.Is(err, fs.ErrPermission):
			return Unit{}, &UnreadableError{Path: unreadablePath(UploadsUnit, rel)}
		case err != nil:
			return Unit{}, err
		case info.Mode()&fs.ModeSymlink != 0:
			return Unit{}, fmt.Errorf("%s %w, Push abgebrochen", where, ErrSymlink)
		case !info.Mode().IsRegular():
			return Unit{}, fmt.Errorf("%s %w", where, ErrUploadMissing)
		case info.Size() > maxFileBytes:
			return Unit{}, fmt.Errorf("%s ist grösser als 256 MB – geht nicht als Upload", where)
		}
		u.Files[rel] = LocalFile{Size: info.Size(), MTime: info.ModTime().Unix()}
		u.Changed = append(u.Changed, rel)
	}
	sort.Strings(u.Changed)
	return u, nil
}

// uploadError ties the agent's refusals of the unit uploads to their errors; the agent's code
// and message stay reachable for errors.As.
func uploadError(err error) error {
	var apiErr *agentapi.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	switch apiErr.Code {
	case "wpsync_upload_exists":
		return fmt.Errorf("%w: %w", ErrUploadExists, err)
	case "wpsync_upload_type_blocked":
		return fmt.Errorf("%w: %w", ErrUploadTypeBlocked, err)
	}
	return err
}

// printUploads shows the agent's answer for the unit uploads: new files, files already on the
// site and files with other content there.
func printUploads(out io.Writer, u *Unit, p *agentapi.PushUnitPlan) {
	fmt.Fprintf(out, "uploads – %d von %d Dateien neu, %d liegen schon auf der Site\n", len(p.Need), len(u.Files), len(p.Same))
	for _, rel := range p.Need {
		fmt.Fprintf(out, "    %s\n", uploadShown(u, rel))
	}
	if len(p.Conflicts) > 0 {
		fmt.Fprintln(out, "  ! auf der Site liegt am selben Pfad schon eine andere Datei (ein Push ersetzt nie einen Upload, auch nicht mit --force):")
		for _, rel := range p.Conflicts {
			fmt.Fprintf(out, "      %s\n", uploadShown(u, rel))
		}
	}
	if !p.Writable {
		fmt.Fprintln(out, "  ! der Webserver darf in wp-content/uploads nicht schreiben (Rechte auf dem Server prüfen)")
	}
}

// uploadShown shows a path of the agent's answer as the local list has it, or quoted.
func uploadShown(u *Unit, rel string) string {
	if _, ok := u.Files[rel]; ok {
		return showPath(rel)
	}
	return agentapi.Printable(rel)
}

// printKept names the uploads a rollback left on the site because they changed since the push.
func printKept(out io.Writer, kept []string) {
	for _, rel := range kept {
		fmt.Fprintf(out, "  ! seit dem Push auf der Site geändert, bleibt liegen: %s\n", agentapi.Printable("wp-content/uploads/"+rel))
	}
}

// pushWhat names what a push brings, for the question before it.
func pushWhat(units int, up *agentapi.PushUnitPlan, pkg *Package) string {
	var parts []string
	if units > 0 || (up == nil && pkg == nil) {
		parts = append(parts, fmt.Sprintf("%d Einheit(en)", units))
	}
	if up != nil {
		parts = append(parts, fmt.Sprintf("%d neue Upload-Datei(en)", len(up.Need)))
	}
	if pkg != nil {
		parts = append(parts, fmt.Sprintf("%d Inhaltszeile(n)", len(pkg.Rows)))
	}
	return strings.Join(parts, " und ")
}

// nonNil returns a copy that is [] in JSON when empty, never null.
func nonNil(s []string) []string { return append([]string{}, s...) }
