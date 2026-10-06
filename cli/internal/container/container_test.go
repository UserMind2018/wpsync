package container

import (
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/usermind/wpsync/internal/agentapi"
	"github.com/usermind/wpsync/internal/localenv"
)

// fakeDocker puts a docker script on PATH that logs argv (one call per line) and the
// WORDPRESS_DB_PASSWORD/MYSQL_PWD it received through the environment.
const fakeDocker = `#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
printf '%s%s\n' "$WORDPRESS_DB_PASSWORD" "$MYSQL_PWD" >> "$FAKE_DOCKER_ENV"
case "$1" in
  inspect)
    [ "$FAKE_MISSING" = 1 ] && exit 1
    echo "${FAKE_RUNNING:-true}"; exit 0;;
  ps) [ -n "$FAKE_PS" ] && cat "$FAKE_PS"; exit 0;;
esac
case "$*" in
  *local_mailguard_collect*) echo ok;;
esac
[ "$2" = "--rm" ] && [ "$3" = "-i" ] && cat > "$FAKE_DOCKER_STDIN"
exit 0
`

type fake struct{ log, envLog, stdin string }

func installFakeDocker(t *testing.T) fake {
	t.Helper()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "docker"), []byte(fakeDocker), 0o755); err != nil {
		t.Fatal(err)
	}
	f := fake{log: filepath.Join(dir, "calls"), envLog: filepath.Join(dir, "env"), stdin: filepath.Join(dir, "stdin")}
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv("FAKE_DOCKER_LOG", f.log)
	t.Setenv("FAKE_DOCKER_ENV", f.envLog)
	t.Setenv("FAKE_DOCKER_STDIN", f.stdin)
	t.Setenv("FAKE_MISSING", "")
	t.Setenv("FAKE_RUNNING", "")
	t.Setenv("FAKE_PS", "")
	fixedRunName(t)
	return f
}

// fixedRunName makes the container names of docker run predictable.
func fixedRunName(t *testing.T) {
	t.Helper()
	orig := runName
	runName = func(site string) string { return "wpsync-" + site + "-test" }
	t.Cleanup(func() { runName = orig })
}

func (f fake) calls(t *testing.T) []string {
	t.Helper()
	data, _ := os.ReadFile(f.log)
	return strings.Split(strings.TrimSpace(string(data)), "\n")
}

func testDriver(t *testing.T) *Driver {
	t.Helper()
	root := t.TempDir()
	d := &Driver{Config: Config{
		Site: "vorlage", Container: "ws-dev-vorlage", Docroot: filepath.Join(root, "docroot"),
		DBHost: "wp-mariadb", DBName: "ws_dev_vorlage", DBUser: "ws_dev_vorlage", DBPassword: "db-geheim",
		LocalURL: "https://vorlage.dev.um-dev.de",
	}, Out: io.Discard, Err: io.Discard}
	os.MkdirAll(d.Docroot, 0o755)
	return d
}

func TestConfigValidate(t *testing.T) {
	ok := testDriver(t).Config
	if err := ok.Validate(); err != nil {
		t.Fatal(err)
	}
	bad := ok
	bad.Container, bad.Docroot, bad.DBPassword, bad.LocalURL, bad.CLIImage = "", "rel/path", "", "ftp://x", "Bad Image"
	err := bad.Validate()
	for _, want := range []string{"--container", "--docroot", "DB-Passwort", "--local-url", "--cli-image"} {
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("missing %q in %v", want, err)
		}
	}
}

// WP-CLI läuft im Netz des Containers, mit dessen Volumes, als www-data – DB-Passwort nur über die Umgebung.
func TestRunnerRunsWPCLIInTheSiteNetwork(t *testing.T) {
	f := installFakeDocker(t)
	d := testDriver(t)
	if err := d.Configure(agentapi.Env{PHPVersion: "8.3.35", TablePrefix: "djTui5D_"}); err != nil {
		t.Fatal(err)
	}
	if err := d.Runner("vorlage").Run("wp", "option", "get", "siteurl"); err != nil {
		t.Fatal(err)
	}
	want := "run --rm --init --name wpsync-vorlage-test --label wpsync.site=vorlage " +
		"--network container:ws-dev-vorlage --volumes-from ws-dev-vorlage --user 33:33 " +
		"-e WORDPRESS_DB_HOST -e WORDPRESS_DB_NAME -e WORDPRESS_DB_USER -e WORDPRESS_DB_PASSWORD -e WORDPRESS_TABLE_PREFIX " +
		"wordpress:cli-php8.3 wp option get siteurl"
	if got := f.calls(t); !reflect.DeepEqual(got, []string{want}) {
		t.Fatalf("calls =\n%v\nwant\n%v", got, want)
	}
	env, _ := os.ReadFile(f.envLog)
	if strings.TrimSpace(string(env)) != "db-geheim" {
		t.Fatalf("password must reach docker through the environment, got %q", env)
	}
}

func TestRunnerImportsSQLWithMariaDBClient(t *testing.T) {
	f := installFakeDocker(t)
	d := testDriver(t)
	d.CLIImage = "wordpress:cli-php8.2"
	d.Configure(agentapi.Env{PHPVersion: "8.3.35"})
	// pull.importArgs: DDEVs Import-Konto entfällt, die Härtung bleibt.
	importArgs := []string{"mysql", "--user=db", "--password=db", "--database=db", "--binary-mode", "--local-infile=0"}
	if err := d.Runner("vorlage").RunStdin(strings.NewReader("SELECT 1;"), importArgs...); err != nil {
		t.Fatal(err)
	}
	want := "run --rm -i --init --name wpsync-vorlage-test --label wpsync.site=vorlage --network container:ws-dev-vorlage -e MYSQL_PWD wordpress:cli-php8.2 mariadb --skip-ssl --binary-mode --local-infile=0 -h wp-mariadb -u ws_dev_vorlage ws_dev_vorlage"
	if got := f.calls(t); !reflect.DeepEqual(got, []string{want}) {
		t.Fatalf("calls = %v", got)
	}
	if sql, _ := os.ReadFile(f.stdin); string(sql) != "SELECT 1;" {
		t.Fatalf("stdin = %q", sql)
	}
	for _, call := range f.calls(t) {
		if strings.Contains(call, "db-geheim") {
			t.Fatal("password in docker arguments")
		}
	}
}

// Ohne --binary-mode und --local-infile=0 liefen Client-Kommandos aus dem Dump der Site (\!, source,
// LOAD DATA LOCAL) – der Container-Modus verweigert den Import dann, ohne docker aufzurufen.
func TestRunnerRefusesImportWithoutHardening(t *testing.T) {
	f := installFakeDocker(t)
	d := testDriver(t)
	d.Configure(agentapi.Env{PHPVersion: "8.3"})
	for _, args := range [][]string{{"mysql"}, {"mysql", "--binary-mode"}, {"mysql", "--binary-mode", "--local-infile=0", "--execute=SELECT 1"}} {
		if err := d.Runner("vorlage").RunStdin(strings.NewReader("SELECT 1;"), args...); err == nil {
			t.Errorf("%v accepted", args)
		}
	}
	if data, _ := os.ReadFile(f.log); len(data) != 0 {
		t.Fatalf("docker called: %s", data)
	}
}

func TestRunnerRejectsUnknownCommands(t *testing.T) {
	installFakeDocker(t)
	d := testDriver(t)
	d.Configure(agentapi.Env{PHPVersion: "8.3"})
	if err := d.Runner("vorlage").Run("restart"); err == nil {
		t.Fatal("restart must be rejected")
	}
}

func TestExistsNeedsRunningContainer(t *testing.T) {
	installFakeDocker(t)
	d := testDriver(t)

	t.Setenv("FAKE_MISSING", "1")
	if _, err := d.Exists("vorlage"); err == nil || !strings.Contains(err.Error(), "fehlt") {
		t.Fatalf("missing container: err = %v", err)
	}
	t.Setenv("FAKE_MISSING", "")
	t.Setenv("FAKE_RUNNING", "false")
	if _, err := d.Exists("vorlage"); err == nil || !strings.Contains(err.Error(), "läuft nicht") {
		t.Fatalf("stopped container: err = %v", err)
	}
	t.Setenv("FAKE_RUNNING", "true")
	if ok, err := d.Exists("vorlage"); err != nil || ok {
		t.Fatalf("before setup: %v, %v", ok, err)
	}
}

// Der Aufrufer legt den Core vor dem Erst-Pull hin (das Site-Netz hat kein Internet); Setup lädt nichts.
func TestSetupUsesTheCallersCoreAndDownloadsNothing(t *testing.T) {
	f := installFakeDocker(t)
	d := testDriver(t)
	var out strings.Builder
	d.Out = &out
	d.Configure(agentapi.Env{PHPVersion: "8.3.35", WPVersion: "6.8.1"})
	os.MkdirAll(filepath.Join(d.Docroot, "wp-includes"), 0o755)
	os.WriteFile(filepath.Join(d.Docroot, "wp-includes", "version.php"), []byte("<?php\n$wp_version = '6.8.1';\n"), 0o644)
	if err := d.Setup("vorlage"); err == nil || !strings.Contains(err.Error(), "wp-config.php") {
		t.Fatalf("setup without wp-config.php from the image: err = %v", err)
	}
	os.WriteFile(filepath.Join(d.Docroot, "wp-config.php"), []byte("<?php"), 0o644)
	if err := d.Setup("vorlage"); err != nil {
		t.Fatal(err)
	}
	if data, _ := os.ReadFile(f.log); len(data) != 0 {
		t.Fatalf("setup must not call docker, calls = %s", data)
	}
	if strings.Contains(out.String(), "!") {
		t.Errorf("same version must not warn: %s", out.String())
	}
	if ok, err := d.Exists("vorlage"); err != nil || !ok {
		t.Fatalf("after setup: %v, %v", ok, err)
	}
}

func TestSetupWarnsOnOtherCoreVersion(t *testing.T) {
	installFakeDocker(t)
	d := testDriver(t)
	var out strings.Builder
	d.Out = &out
	d.Configure(agentapi.Env{PHPVersion: "8.3", WPVersion: "6.8.1"})
	os.MkdirAll(filepath.Join(d.Docroot, "wp-includes"), 0o755)
	os.WriteFile(filepath.Join(d.Docroot, "wp-includes", "version.php"), []byte("<?php $wp_version = '6.7.2';"), 0o644)
	os.WriteFile(filepath.Join(d.Docroot, "wp-config.php"), []byte("<?php"), 0o644)
	if err := d.Setup("vorlage"); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.String(), "6.7.2") || !strings.Contains(out.String(), "6.8.1") {
		t.Fatalf("warning = %q", out.String())
	}
}

// Ohne Core bricht Setup ab, statt ins Netz zu gehen.
func TestSetupWithoutCoreFails(t *testing.T) {
	f := installFakeDocker(t)
	d := testDriver(t)
	d.Configure(agentapi.Env{PHPVersion: "8.3", WPVersion: "6.8.1"})
	os.WriteFile(filepath.Join(d.Docroot, "wp-config.php"), []byte("<?php"), 0o644)
	if err := d.Setup("vorlage"); !errors.Is(err, ErrNoCore) {
		t.Fatalf("err = %v", err)
	}
	if data, _ := os.ReadFile(f.log); len(data) != 0 {
		t.Fatalf("no docker call allowed, calls = %s", data)
	}
}

func TestStopMapsSiteToContainer(t *testing.T) {
	f := installFakeDocker(t)
	d := testDriver(t)
	if n, err := d.Stop("vorlage", "ws-dev-andere"); err != nil || n != 2 {
		t.Fatalf("Stop = %d, %v", n, err)
	}
	if got := f.calls(t); !reflect.DeepEqual(got, []string{"stop ws-dev-vorlage ws-dev-andere"}) {
		t.Fatalf("calls = %v", got)
	}
}

func TestListReadsLabels(t *testing.T) {
	f := installFakeDocker(t)
	ps := filepath.Join(t.TempDir(), "ps")
	os.WriteFile(ps, []byte(`{"Names":"ws-dev-vorlage","State":"running","Labels":"um.env=dev,um.slug=vorlage,um.website-studio=1"}
{"Names":"ws-dev-alt","State":"exited","Labels":"um.website-studio=1"}
`), 0o644)
	t.Setenv("FAKE_PS", ps)
	got, err := (&Driver{Out: io.Discard, Err: io.Discard}).List()
	if err != nil {
		t.Fatal(err)
	}
	want := []localenv.Env{
		{Name: "vorlage", Status: localenv.StatusRunning, Ref: "ws-dev-vorlage"},
		{Name: "ws-dev-alt", Status: localenv.StatusStopped, Ref: "ws-dev-alt"},
	}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("List = %+v", got)
	}
	if calls := f.calls(t); calls[0] != "ps -a --filter label=um.website-studio=1 --format {{json .}}" {
		t.Fatalf("calls = %v", calls)
	}
}

// SIGTERM: ein laufender docker-Aufruf wird beendet, der Fehler trägt context.Canceled.
func TestCancelStopsDocker(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, "docker"), []byte("#!/bin/sh\nsleep 30\n"), 0o755)
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))
	ctx, cancel := context.WithCancel(context.Background())
	d := testDriver(t)
	d.Ctx = ctx
	d.Configure(agentapi.Env{PHPVersion: "8.3"})
	cancel()
	err := d.Runner("vorlage").Run("wp", "option", "get", "home")
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v", err)
	}
}

// Ohne .htaccess antwortet Apache auf /wp-json/ mit 404 und der Elementor-Editor lädt nicht
// (Abnahme Server-Modus). Setup und Start legen den WordPress-Standardblock an, wenn nichts da ist.
func TestSetupAndStartWriteDefaultHtaccess(t *testing.T) {
	installFakeDocker(t)
	d := testDriver(t)
	d.Configure(agentapi.Env{PHPVersion: "8.3", WPVersion: "6.8.1"})
	os.MkdirAll(filepath.Join(d.Docroot, "wp-includes"), 0o755)
	os.WriteFile(filepath.Join(d.Docroot, "wp-includes", "version.php"), []byte("<?php $wp_version = '6.8.1';"), 0o644)
	os.WriteFile(filepath.Join(d.Docroot, "wp-config.php"), []byte("<?php"), 0o644)
	htaccess := filepath.Join(d.Docroot, ".htaccess")

	if err := d.Setup("vorlage"); err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile(htaccess)
	if err != nil || !strings.Contains(string(data), "# BEGIN WordPress") || !strings.Contains(string(data), "RewriteBase /\n") {
		t.Fatalf(".htaccess after setup = %q, %v", data, err)
	}

	os.Remove(htaccess)
	if err := d.Start("vorlage"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(htaccess); err != nil {
		t.Fatalf("start must restore a missing .htaccess: %v", err)
	}

	os.WriteFile(htaccess, []byte("# eigene Regeln\n"), 0o644)
	if err := d.Start("vorlage"); err != nil {
		t.Fatal(err)
	}
	if data, _ := os.ReadFile(htaccess); string(data) != "# eigene Regeln\n" {
		t.Fatalf("an existing .htaccess must stay untouched: %q", data)
	}
}

// Ein Symlink an der Stelle der .htaccess (der Docroot ist für die Site beschreibbar) wird nie
// verfolgt: wpsync schreibt nicht ausserhalb des Docroot.
func TestHtaccessNeverFollowsSymlink(t *testing.T) {
	installFakeDocker(t)
	d := testDriver(t)
	outside := filepath.Join(t.TempDir(), "ziel")
	if err := os.Symlink(outside, filepath.Join(d.Docroot, ".htaccess")); err != nil {
		t.Fatal(err)
	}
	if err := d.Start("vorlage"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Lstat(outside); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("wpsync wrote through the symlink: %v", err)
	}
}

func TestHtaccessUsesLocalURLPath(t *testing.T) {
	installFakeDocker(t)
	d := testDriver(t)
	d.Config.LocalURL = "http://localhost:18082/sub/"
	if err := d.Start("vorlage"); err != nil {
		t.Fatal(err)
	}
	data, _ := os.ReadFile(filepath.Join(d.Docroot, ".htaccess"))
	if !strings.Contains(string(data), "RewriteBase /sub/\n") || !strings.Contains(string(data), "RewriteRule . /sub/index.php [L]") {
		t.Fatalf(".htaccess = %q", data)
	}
}

// Review M1: SIGTERM beendet nur die docker-CLI. Der Container läuft mit --init (Signale erreichen
// WP-CLI), trägt Name und Label und wird nach dem Abbruch per docker rm -f entfernt.
func TestCancelRemovesTheRunContainer(t *testing.T) {
	dir := t.TempDir()
	log := filepath.Join(dir, "calls")
	script := "#!/bin/sh\nprintf '%s\\n' \"$*\" >> " + log + "\n[ \"$1\" = run ] && exec sleep 30\nexit 0\n"
	os.WriteFile(filepath.Join(dir, "docker"), []byte(script), 0o755)
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))
	fixedRunName(t)
	ctx, cancel := context.WithCancel(context.Background())
	d := testDriver(t)
	d.Ctx = ctx
	d.Configure(agentapi.Env{PHPVersion: "8.3"})
	go func() {
		for {
			if data, _ := os.ReadFile(log); len(data) > 0 {
				cancel()
				return
			}
		}
	}()
	err := d.Runner("vorlage").Run("wp", "option", "get", "home")
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v", err)
	}
	data, _ := os.ReadFile(log)
	calls := strings.Split(strings.TrimSpace(string(data)), "\n")
	if len(calls) != 2 || !strings.HasPrefix(calls[0], "run --rm --init --name wpsync-vorlage-test --label wpsync.site=vorlage ") ||
		calls[1] != "rm -f wpsync-vorlage-test" {
		t.Fatalf("calls = %q", calls)
	}
}

// Ohne Abbruch kein docker rm: --rm räumt selbst auf.
func TestRunWithoutCancelRemovesNothing(t *testing.T) {
	f := installFakeDocker(t)
	d := testDriver(t)
	d.Configure(agentapi.Env{PHPVersion: "8.3"})
	if err := d.Runner("vorlage").Run("wp", "option", "get", "home"); err != nil {
		t.Fatal(err)
	}
	for _, c := range f.calls(t) {
		if strings.HasPrefix(c, "rm ") {
			t.Fatalf("unexpected %q", c)
		}
	}
}

// Beim Pull-Start (unter dem Site-Lock) räumt wpsync verwaiste Läufe der Site weg – nur die eigenen.
func TestRemoveOrphans(t *testing.T) {
	f := installFakeDocker(t)
	ps := filepath.Join(t.TempDir(), "ps")
	os.WriteFile(ps, []byte("abc123\ndef456\n"), 0o644)
	t.Setenv("FAKE_PS", ps)
	d := testDriver(t)
	if err := d.RemoveOrphans("vorlage"); err != nil {
		t.Fatal(err)
	}
	want := []string{"ps -aq --filter label=wpsync.site=vorlage", "rm -f abc123 def456"}
	if got := f.calls(t); !reflect.DeepEqual(got, want) {
		t.Fatalf("calls = %q, want %q", got, want)
	}

	os.WriteFile(ps, nil, 0o644)
	os.Remove(f.log)
	if err := d.RemoveOrphans("vorlage"); err != nil {
		t.Fatal(err)
	}
	if got := f.calls(t); !reflect.DeepEqual(got, []string{"ps -aq --filter label=wpsync.site=vorlage"}) {
		t.Fatalf("calls = %q", got)
	}
}

func TestRunNameIsUniquePerRun(t *testing.T) {
	a, b := runName("vorlage"), runName("vorlage")
	if a == b || !strings.HasPrefix(a, "wpsync-vorlage-") || !nameRe.MatchString(a) {
		t.Fatalf("names %q %q", a, b)
	}
}
