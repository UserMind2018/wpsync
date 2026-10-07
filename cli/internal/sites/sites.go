// Package sites manages paired sites in ~/.config/wpsync/sites/<name>.yaml.
package sites

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"github.com/usermind/wpsync/internal/profile"
	"gopkg.in/yaml.v3"
)

// Site is a paired source with its pull profile (set by wpsync scan).
type Site struct {
	Name    string           `yaml:"name"`
	URL     string           `yaml:"url"`
	KeyID   string           `yaml:"key_id"`
	RPS     float64          `yaml:"rps"`
	Profile *profile.Profile `yaml:"profile,omitempty"`
	// HealthURLs are checked before and after a push, in addition to the pages the agent names.
	HealthURLs []string `yaml:"health_urls,omitempty"`
	// Device is the name pair sent for this pairing (--device or the host name); the WP admin lists
	// the push window under it. Empty for pairings before CLI 0.5.0.
	Device string `yaml:"device,omitempty"`
}

var nameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,40}$`)

// ValidName follows DDEV project name rules.
func ValidName(name string) bool { return nameRe.MatchString(name) }

// NameFromURL derives a site name from the host, e.g. www.example.com → example-com.
func NameFromURL(raw string) (string, error) {
	u, err := url.Parse(raw)
	if err != nil || u.Hostname() == "" {
		return "", fmt.Errorf("invalid URL %q", raw)
	}
	host := strings.TrimPrefix(strings.ToLower(u.Hostname()), "www.")
	name := strings.NewReplacer(".", "-", "_", "-").Replace(host)
	if len(name) > 41 {
		name = strings.TrimRight(name[:41], "-")
	}
	if !ValidName(name) {
		return "", fmt.Errorf("cannot derive a valid name from %q", raw)
	}
	return name, nil
}

// KeychainService is the keychain service name of a site.
func KeychainService(name string) string { return "wpsync:" + name }

// ConfigDir is $WPSYNC_CONFIG_DIR or ~/.config/wpsync.
func ConfigDir() (string, error) {
	if dir := os.Getenv("WPSYNC_CONFIG_DIR"); dir != "" {
		return dir, nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, ".config", "wpsync"), nil
}

// SitesRoot is $WPSYNC_SITES_DIR or ~/wpsync-sites.
func SitesRoot() (string, error) {
	if dir := os.Getenv("WPSYNC_SITES_DIR"); dir != "" {
		return dir, nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, "wpsync-sites"), nil
}

func path(name string) (string, error) {
	if !ValidName(name) {
		return "", fmt.Errorf("invalid site name %q", name)
	}
	dir, err := ConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "sites", name+".yaml"), nil
}

// Load reads a site.
func Load(name string) (*Site, error) {
	p, err := path(name)
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(p)
	if errors.Is(err, os.ErrNotExist) {
		return nil, fmt.Errorf("site %q is not paired", name)
	}
	if err != nil {
		return nil, err
	}
	var s Site
	if err := yaml.Unmarshal(data, &s); err != nil {
		return nil, fmt.Errorf("read %s: %w", p, err)
	}
	return &s, nil
}

// Save writes a site with 0600 permissions.
func Save(s *Site) error {
	p, err := path(s.Name)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	data, err := yaml.Marshal(s)
	if err != nil {
		return err
	}
	return os.WriteFile(p, data, 0o600)
}

// List returns all sites sorted by name.
func List() ([]Site, error) {
	dir, err := ConfigDir()
	if err != nil {
		return nil, err
	}
	entries, err := os.ReadDir(filepath.Join(dir, "sites"))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []Site
	for _, e := range entries {
		if name, ok := strings.CutSuffix(e.Name(), ".yaml"); ok {
			if s, err := Load(name); err == nil {
				out = append(out, *s)
			}
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}

// Delete removes the site configuration.
func Delete(name string) error {
	p, err := path(name)
	if err != nil {
		return err
	}
	return os.Remove(p)
}
