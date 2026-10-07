// Package staging drives the staging copy on the customer's server (Spec Stufe 2b): the agent
// copies inside the server, the CLI starts the job, checks the .htaccess from the outside and
// follows the progress. No content of the site passes through this machine (Leitplanke 8).
package staging

import (
	"context"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/url"
	"strings"
	"syscall"
	"time"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/sites"
)

// MinAgent is the first agent that knows staging.
const MinAgent = "0.5.0"

// confirmBytes: above this need (with 20 % reserve) create and refresh ask first (Spec 5.7).
const confirmBytes = 1 << 30

// maxNeed caps a size the agent reports, so the sum with reserve cannot overflow.
const maxNeed = math.MaxInt64 / 4

// cookieName is the access cookie of the copy (Spec 5.4).
const cookieName = "wpsync_stg"

var (
	// ErrMissing: the site has no staging copy (exit code staging_missing).
	ErrMissing = errors.New("es gibt keine Staging-Kopie")
	// ErrExists: create although a copy exists (staging_exists).
	ErrExists = errors.New("es gibt schon eine Staging-Kopie")
	// ErrUnsupported: probe failed, multisite, subdirectory, prefix (staging_unsupported).
	ErrUnsupported = errors.New("Staging ist auf diesem Server nicht möglich")
	// ErrLocked: locked after 14 days without use (staging_locked).
	ErrLocked = errors.New("die Staging-Kopie ist gesperrt")
	// ErrBusy: a staging job or a push to staging is running; Resume picks up a job left behind.
	ErrBusy = errors.New("auf der Staging-Kopie wird gerade gearbeitet")
	// ErrWindowClosed: create, refresh, delete and open need the push window of the pairing, like a
	// push (U44); status and following a running job do not (push_window_closed).
	ErrWindowClosed = errors.New("Staging braucht ein offenes Push-Fenster")
	// ErrNeedsYes: a question without a terminal or with --json (usage).
	ErrNeedsYes = errors.New("ohne Terminal mit --yes bestätigen")
	// ErrAborted: the user declined.
	ErrAborted = errors.New("abgebrochen")
	// ErrFailed: the job stopped; the agent removed what it had created.
	ErrFailed = errors.New("Staging-Job fehlgeschlagen")
	// ErrNoProfile: staging copies by the pull profile (S3).
	ErrNoProfile = errors.New("noch kein Pull-Profil")
	// ErrNoInfosheet: the profile needs the infosheet of the site.
	ErrNoInfosheet = errors.New("die Site hat noch kein Infosheet")
)

// Options for every staging command.
type Options struct {
	Site        sites.Site
	Client      *agentapi.Client
	HTTP        *http.Client // probe and login link; nil: 30 s timeout
	Out         io.Writer
	Yes         bool                               // no questions
	NoAnonymize bool                               // personal data in plain text (T2)
	Code        bool                               // refresh: the code as well
	Confirm     func(string) bool                  // nil without a terminal
	Progress    func(name string, done, total int) // --json phase events
}

func (o Options) defaults() Options {
	if o.Out == nil {
		o.Out = io.Discard
	}
	return o
}

// ask returns nil when the user agreed or Yes is set.
func (o Options) ask(question string) error {
	switch {
	case o.Yes:
		return nil
	case o.Confirm == nil:
		return ErrNeedsYes
	case !o.Confirm(question):
		return ErrAborted
	}
	return nil
}

// BrowserWarning goes with every login link (Review H2): the copy lives on the origin of the live
// site, so a script in the copy acts with a live admin session of the same browser profile.
const BrowserWarning = "die Kopie läuft auf derselben Adresse (Origin) wie Live: nicht in einem Browser(-profil) öffnen, in dem jemand bei Live im WP-Admin angemeldet ist – privates Fenster oder eigenes Profil nehmen"

const plainQuestion = "--no-anonymize legt echte Kundendaten auf eine Kopie mit eigener URL. Trotzdem?"

// Create copies the live site into a staging folder on the same server (Spec 5.2).
func Create(o Options) (*agentapi.StagingResult, error) {
	o = o.defaults()
	scope, err := prepare(o)
	if err != nil {
		return nil, err
	}
	if o.NoAnonymize {
		if err := o.ask(plainQuestion); err != nil {
			return nil, err
		}
	}
	req := agentapi.StagingBeginRequest{Op: "create", Dry: true, Scope: scope}
	dry, err := o.Client.StagingBegin(req)
	if err != nil {
		return nil, agentError(err)
	}
	if err := o.confirmNeed(dry.Need); err != nil {
		return nil, err
	}
	req.Dry = false
	begin, err := o.Client.StagingBegin(req)
	if err == nil && begin.Probe == nil {
		err = errors.New("der Agent hat keine Probe-URLs geliefert")
	} else if err != nil && !errors.Is(err, agentapi.ErrForeignURL) {
		return nil, agentError(err)
	}
	if err != nil {
		// The job exists on the server already: without a probe it must not stay there.
		if _, cleanup := follow(o, "fail"); cleanup != nil && !errors.Is(cleanup, ErrUnsupported) {
			return nil, fmt.Errorf("%w – die angefangene Kopie liess sich nicht abräumen (%v), wpsync staging delete %s", err, cleanup, o.Site.Name)
		}
		return nil, err
	}
	verdict := "ok"
	if err := Probe(o.Client.Ctx, o.HTTP, o.Site.URL, *begin.Probe); err != nil {
		fmt.Fprintf(o.Out, "  ! %v\n", err)
		verdict = "fail"
	}
	fmt.Fprintf(o.Out, "Staging für %s wird angelegt …\n", show(o.Site.URL))
	return follow(o, verdict)
}

// Refresh loads the database (and with Code the code) again from live (Spec 5.2).
func Refresh(o Options) (*agentapi.StagingResult, error) {
	o = o.defaults()
	scope, err := prepare(o)
	if err != nil {
		return nil, err
	}
	question := "Staging-Datenbank neu von Live laden? Was auf Staging in der Datenbank geändert wurde, geht verloren."
	if o.Code {
		question = "Staging-Datenbank und -Code neu von Live laden? Gepushter Code, seine Snapshots und alle Änderungen auf Staging gehen verloren."
	}
	if err := o.ask(question); err != nil {
		return nil, err
	}
	if o.NoAnonymize {
		if err := o.ask(plainQuestion); err != nil {
			return nil, err
		}
	}
	req := agentapi.StagingBeginRequest{Op: "refresh", Code: o.Code, Dry: true, Scope: scope}
	dry, err := o.Client.StagingBegin(req)
	if err != nil {
		return nil, agentError(err)
	}
	if err := o.confirmNeed(dry.Need); err != nil {
		return nil, err
	}
	req.Dry = false
	if _, err := o.Client.StagingBegin(req); err != nil {
		return nil, agentError(err)
	}
	fmt.Fprintln(o.Out, "Staging wird aufgefrischt …")
	return follow(o, "")
}

// Delete removes tables and folder of the copy (Spec 5.2).
func Delete(o Options) error {
	o = o.defaults()
	if err := checkAgent(o.Client); err != nil {
		return err
	}
	if err := o.ask(fmt.Sprintf("Staging-Kopie von %s löschen – Tabellen, Ordner und Pushes nach Staging?", show(o.Site.URL))); err != nil {
		return err
	}
	if _, err := o.Client.StagingBegin(agentapi.StagingBeginRequest{Op: "delete"}); err != nil {
		return agentError(err)
	}
	_, err := follow(o, "")
	return err
}

// Resume follows a job whose CLI was interrupted (ErrBusy on the next command). The result is nil
// when the job was a delete. A create left in its probe is taken down by the agent.
func Resume(o Options) (*agentapi.StagingResult, error) {
	o = o.defaults()
	if err := checkAgent(o.Client); err != nil {
		return nil, err
	}
	return follow(o, "")
}

// Status prints the staging record. A locked copy returns ErrLocked with the status (Spec 6.4).
func Status(o Options) (*agentapi.StagingStatus, error) {
	o = o.defaults()
	if err := checkAgent(o.Client); err != nil {
		return nil, err
	}
	st, err := o.Client.StagingStatus()
	if err != nil {
		return nil, agentError(err)
	}
	if !st.Exists {
		fmt.Fprintf(o.Out, "Keine Staging-Kopie – anlegen mit wpsync staging create %s\n", o.Site.Name)
		return st, ErrMissing
	}
	fmt.Fprintf(o.Out, "Staging: %s\n", statusLabel(st.Status))
	if st.URL = o.onSite(st.URL); st.URL != "" {
		fmt.Fprintf(o.Out, "  Adresse:         %s (nur mit Link aus wpsync staging open %s)\n", show(st.URL), o.Site.Name)
	}
	if st.CopiedAt > 0 {
		fmt.Fprintf(o.Out, "  Stand von Live:  %s\n", when(st.CopiedAt))
	}
	if st.LastUsed > 0 {
		fmt.Fprintf(o.Out, "  zuletzt genutzt: %s\n", when(st.LastUsed))
	}
	data := "anonymisiert"
	if !st.Anonymized {
		data = "KLARTEXT (--no-anonymize)"
	}
	fmt.Fprintf(o.Out, "  Daten:           %s, Datenbank %s\n", data, size(st.DBBytes))
	if st.Job != nil && !st.Job.Finished() {
		fmt.Fprintf(o.Out, "  Job:             %s\n", phaseLabel(st.Job.Phase))
	}
	if st.Error != "" {
		fmt.Fprintf(o.Out, "  Fehler:          %s\n", clean(st.Error))
	}
	for _, p := range st.Pushes {
		fmt.Fprintf(o.Out, "  Push %s: %s\n", show(p.PushID), show(p.Status))
	}
	if st.Status == agentapi.StagingLocked {
		fmt.Fprintf(o.Out, "  Entsperren: wpsync staging open %s\n", o.Site.Name)
		return st, ErrLocked
	}
	return st, nil
}

// Open returns a one-time login link for the browser (T1). The agent lifts a lock on the way.
// The link carries the token: it goes to the browser or, on request, to stdout – nowhere else.
func Open(o Options) (*agentapi.StagingLogin, error) {
	o = o.defaults()
	if err := checkAgent(o.Client); err != nil {
		return nil, err
	}
	return login(o.Client, o.Site.URL)
}

// Access fetches a login link and redeems it without a browser. The health check of a push to
// staging then sees the copy like a visitor with access (Spec 6.2, V20). base is the staging URL.
// The cookie is the access cookie alone, without attributes: the admin session the link also
// starts is dropped. hc nil: 30 s timeout.
func Access(c *agentapi.Client, hc *http.Client, siteURL string) (base string, cookie *http.Cookie, err error) {
	l, err := login(c, siteURL)
	if err != nil {
		return "", nil, err
	}
	status, _, cookies, err := fetch(c.Ctx, hc, l.URL, "")
	if err != nil {
		return "", nil, err
	}
	for _, c := range cookies {
		if c.Name == cookieName && c.Value != "" {
			cookie = &http.Cookie{Name: cookieName, Value: c.Value}
		}
	}
	if cookie == nil {
		return "", nil, fmt.Errorf("der Login-Link der Staging-Kopie setzt kein Zugangs-Cookie (HTTP %d)", status)
	}
	return strings.TrimRight(l.Redacted(), "/"), cookie, nil
}

// login asks for a one-time link and accepts it only on the paired site. No error names the link.
func login(c *agentapi.Client, siteURL string) (*agentapi.StagingLogin, error) {
	l, err := c.StagingLogin()
	if err != nil {
		return nil, agentError(err)
	}
	if !agentapi.SameOrigin(siteURL, l.URL) {
		return nil, fmt.Errorf("%w (Login-Link)", agentapi.ErrForeignURL)
	}
	return l, nil
}

// Probe checks from the outside that the .htaccess of the staging folder works (S5, V6): without
// the access cookie 403, with it the rewrite answers with the token and protected files stay 403.
func Probe(ctx context.Context, hc *http.Client, siteURL string, p agentapi.StagingProbe) error {
	for _, u := range []string{p.DenyURL, p.RewriteURL, p.FilesURL} {
		if !agentapi.SameOrigin(siteURL, u) {
			return fmt.Errorf("%w: %s", agentapi.ErrForeignURL, agentapi.Printable(u))
		}
	}
	if strings.TrimSpace(p.Token) == "" {
		return errors.New("der Agent hat kein Probe-Token geliefert")
	}
	status, _, _, err := fetch(ctx, hc, p.DenyURL, "")
	if err != nil {
		return err
	}
	if status != http.StatusForbidden {
		return fmt.Errorf("ohne Zugangs-Cookie liefert der Staging-Ordner HTTP %d statt 403 – die .htaccess wirkt nicht", status)
	}
	status, body, _, err := fetch(ctx, hc, p.RewriteURL, "probe")
	if err != nil {
		return err
	}
	if status != http.StatusOK || strings.TrimSpace(body) != p.Token {
		return fmt.Errorf("Rewrite im Staging-Ordner wirkt nicht (HTTP %d)", status)
	}
	status, _, _, err = fetch(ctx, hc, p.FilesURL, "probe")
	if err != nil {
		return err
	}
	if status != http.StatusForbidden {
		return fmt.Errorf("geschützte Dateien im Staging-Ordner sind abrufbar (HTTP %d statt 403)", status)
	}
	return nil
}

// prepare checks the agent and builds the scope of the pull profile (S3, V2).
func prepare(o Options) (*agentapi.Scope, error) {
	if err := checkAgent(o.Client); err != nil {
		return nil, err
	}
	if o.Site.Profile == nil {
		return nil, ErrNoProfile
	}
	sheet, _, err := o.Client.Infosheet()
	if err != nil {
		return nil, err
	}
	if sheet == nil {
		return nil, ErrNoInfosheet
	}
	scope := o.Site.Profile.Scope(sheet)
	scope.PlainPII = o.NoAnonymize
	return &scope, nil
}

func checkAgent(c *agentapi.Client) error {
	env, err := c.Ping()
	if err != nil {
		return err
	}
	if !agentapi.VersionAtLeast(env.AgentVersion, MinAgent) {
		return &agentapi.OutdatedError{Installed: env.AgentVersion, Required: MinAgent, Err: errors.New("der Agent kennt noch kein Staging")}
	}
	return nil
}

// confirmNeed shows the space the copy takes and asks above 1 GB (Spec 5.7).
func (o Options) confirmNeed(n *agentapi.StagingNeed) error {
	if n == nil {
		return nil
	}
	db := plausible(n.DBBytes)
	total := db
	code := "unbekannt"
	if n.CodeBytes != nil {
		total += plausible(*n.CodeBytes)
		code = size(plausible(*n.CodeBytes))
	}
	total = total / 5 * 6
	fmt.Fprintf(o.Out, "Platzbedarf auf dem Server: %s (Code %s, Datenbank %s, mit 20 %% Reserve)\n", size(total), code, size(db))
	if n.DiskFree != nil {
		fmt.Fprintf(o.Out, "  frei auf dem Dateisystem: %s; das Datenbank-Kontingent lässt sich nicht prüfen\n", size(plausible(*n.DiskFree)))
	}
	for _, w := range n.Warnings {
		fmt.Fprintf(o.Out, "  ! %s\n", clean(w))
	}
	if total <= confirmBytes {
		return nil
	}
	return o.ask(fmt.Sprintf("Die Kopie braucht %s auf dem Server. Fortfahren?", size(total)))
}

// plausible keeps a size of the agent in a range that adds up: a negative or absurd number counts
// as the largest, so it asks instead of slipping under the limit.
func plausible(b int64) int64 {
	if b < 0 || b > maxNeed {
		return maxNeed
	}
	return b
}

// follow steps the job until none is left; the first step carries the probe verdict. A job that
// fails keeps running as its own cleanup, so the error is reported only once that has ended.
func follow(o Options, probe string) (*agentapi.StagingResult, error) {
	last := ""
	for {
		st, err := o.Client.StagingStep(probe)
		probe = ""
		if err != nil {
			return nil, agentError(err)
		}
		if st.Finished() {
			return o.finished(st)
		}
		if st.Phase != last {
			fmt.Fprintf(o.Out, "  %s\n", phaseLabel(st.Phase))
			last = st.Phase
		}
		if o.Progress != nil {
			o.Progress(st.Phase, st.Done, st.Total)
		}
	}
}

func (o Options) finished(st *agentapi.StagingStep) (*agentapi.StagingResult, error) {
	switch st.Status {
	case agentapi.StagingReady:
		if st.Result == nil {
			return nil, errors.New("der Agent meldet die Kopie fertig, aber ohne Ergebnis")
		}
		st.Result.URL = o.onSite(st.Result.URL)
		return st.Result, nil
	case agentapi.StagingDeleted:
		return nil, nil
	case agentapi.StagingFailed:
		return nil, jobError(st)
	}
	return nil, fmt.Errorf("auf der Staging-Kopie läuft kein Job (Status %s)", show(st.Status))
}

// onSite returns an address the agent names for the copy, or "" with a note when it is not on the
// paired site: such an address is neither shown nor handed on (T1).
func (o Options) onSite(rawURL string) string {
	if rawURL == "" || agentapi.SameOrigin(o.Site.URL, rawURL) {
		return rawURL
	}
	fmt.Fprintln(o.Out, "  ! der Agent nennt für die Kopie eine Adresse ausserhalb der gekoppelten Site – nicht übernommen")
	return ""
}

func jobError(st *agentapi.StagingStep) error {
	msg := clean(st.Error)
	switch st.ErrorCode {
	case agentapi.StagingErrUnsupported:
		return fmt.Errorf("%w: %s", ErrUnsupported, msg)
	case agentapi.StagingErrDiskFull:
		return fmt.Errorf("Staging abgebrochen, Angelegtes ist entfernt – %s: %w", msg, syscall.ENOSPC)
	}
	return fmt.Errorf("%w: %s", ErrFailed, msg)
}

// agentError ties agent refusals to the staging exit codes; the agent message stays.
func agentError(err error) error {
	var apiErr *agentapi.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	switch apiErr.Code {
	case "wpsync_staging_missing":
		return fmt.Errorf("%w: %w", ErrMissing, err)
	case "wpsync_staging_exists":
		return fmt.Errorf("%w: %w", ErrExists, err)
	case "wpsync_staging_unsupported":
		return fmt.Errorf("%w: %w", ErrUnsupported, err)
	case "wpsync_staging_locked":
		return fmt.Errorf("%w: %w", ErrLocked, err)
	case "wpsync_staging_busy":
		return fmt.Errorf("%w: %w", ErrBusy, err)
	case "wpsync_push_window":
		return fmt.Errorf("%w: %w", ErrWindowClosed, err)
	}
	return err
}

// fetch sends one GET to the copy; cookie is the value of the access cookie or "". It never
// follows a redirect and uses no cookie jar, whatever hc is set up to do: cookie and token reach
// the address the caller checked and no other. Errors name the address without its query.
func fetch(ctx context.Context, hc *http.Client, rawURL, cookie string) (status int, body string, cookies []*http.Cookie, err error) {
	if ctx == nil {
		ctx = context.Background()
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		return 0, "", nil, errors.New("ungültige Adresse der Staging-Kopie")
	}
	req.Header.Set("User-Agent", agentapi.UserAgent())
	if cookie != "" {
		req.AddCookie(&http.Cookie{Name: cookieName, Value: cookie})
	}
	client := &http.Client{Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	if hc != nil {
		client.Transport = hc.Transport
		if hc.Timeout > 0 {
			client.Timeout = hc.Timeout
		}
	}
	resp, err := client.Do(req)
	if err != nil {
		var ue *url.Error
		if errors.As(err, &ue) {
			err = ue.Err // its message would carry the whole URL, token included
		}
		return 0, "", nil, fmt.Errorf("%s nicht erreichbar: %w", show(agentapi.StagingLogin{URL: rawURL}.Redacted()), err)
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	return resp.StatusCode, string(raw), resp.Cookies(), nil
}

func phaseLabel(phase string) string {
	labels := map[string]string{
		"probe": "Probe", "files": "Code kopieren", "tables": "Tabellen kopieren", "anonymize": "Anonymisieren",
		"fixup": "Präfix anpassen", "urls": "Adressen umschreiben", "settings": "Riegel setzen",
		"lock": "Kopie sperren", "drop": "Tabellen löschen", "remove": "Ordner löschen", "remove-code": "Code entfernen",
	}
	if l, ok := labels[phase]; ok {
		return l
	}
	return show(phase)
}

func statusLabel(status string) string {
	labels := map[string]string{
		agentapi.StagingCreating: "wird angelegt", agentapi.StagingReady: "bereit", agentapi.StagingRefreshing: "wird aufgefrischt",
		agentapi.StagingLocked: "gesperrt (14 Tage ungenutzt)", agentapi.StagingFailed: "fehlgeschlagen", agentapi.StagingDeleting: "wird gelöscht",
	}
	if l, ok := labels[status]; ok {
		return l
	}
	return show(status)
}

func when(unix int64) string { return time.Unix(unix, 0).Format("02.01.2006 15:04") }

func size(b int64) string {
	if b >= 1<<30 {
		return fmt.Sprintf("%.1f GB", float64(b)/(1<<30))
	}
	return fmt.Sprintf("%.1f MB", float64(b)/(1<<20))
}

// show returns text from the server for the terminal: as is when it is safe, else quoted.
func show(s string) string {
	if agentapi.CleanText(s) == s {
		return s
	}
	return agentapi.Printable(s)
}

// clean keeps the line breaks of agent messages (the nginx rule) and replaces other control
// and direction characters.
func clean(s string) string {
	lines := strings.Split(s, "\n")
	for i, l := range lines {
		lines[i] = agentapi.CleanText(l)
	}
	return strings.Join(lines, "\n")
}
