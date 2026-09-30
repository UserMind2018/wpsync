package agentapi

// Env describes the source site.
type Env struct {
	PHPVersion       string   `json:"php_version"`
	WPVersion        string   `json:"wp_version"`
	DBServer         string   `json:"db_server"`
	DBCharset        string   `json:"db_charset"`
	TablePrefix      string   `json:"table_prefix"`
	SiteURL          string   `json:"siteurl"`
	Home             string   `json:"home"`
	MaxExecutionTime int      `json:"max_execution_time"`
	MemoryLimit      string   `json:"memory_limit"`
	ActivePlugins    []string `json:"active_plugins"`
	AgentVersion     string   `json:"agent_version"`
}

// Table is one database table with its change marker.
type Table struct {
	Name       string  `json:"name"`
	Checksum   *string `json:"checksum"`
	Rows       int64   `json:"rows"`
	Bytes      int64   `json:"bytes"`
	PrimaryKey *string `json:"primary_key"`
	// Mode is set by the CLI, not the agent: full, structure or filtered:<post types>.
	// The baseline remembers it so a changed profile reloads the table.
	Mode string `json:"-"`
}

// File is one file below wp-content, path relative to ABSPATH.
type File struct {
	Path  string `json:"path"`
	Size  int64  `json:"size"`
	MTime int64  `json:"mtime"`
}

// Skipped is a file the agent will not deliver (> 256 MB).
type Skipped struct {
	Path string `json:"path"`
	Size int64  `json:"size"`
}

// Delta is the complete, freshly computed state of the source.
type Delta struct {
	Env     Env
	Tables  []Table
	Files   []File
	Skipped []Skipped
}

type deltaPage struct {
	Env     *Env      `json:"env"`
	Tables  []Table   `json:"tables"`
	Files   []File    `json:"files"`
	Skipped []Skipped `json:"skipped"`
	Next    *string   `json:"next"`
}

// ChunkRequest asks for one chunk of a large table.
type ChunkRequest struct {
	Table  string  `json:"table"`
	After  *string `json:"after"`
	Offset int     `json:"offset"`
	Limit  int     `json:"limit"`
	Scope  Scope   `json:"scope"`
}

// ChunkResult describes a received chunk.
type ChunkResult struct {
	Rows int
	Mode string // "keyset" or "offset"
	Next *string
}

// PairResult is returned once by /pair.
type PairResult struct {
	KeyID        string `json:"key_id"`
	Secret       string `json:"secret"`
	Home         string `json:"home"`
	AgentVersion string `json:"agent_version"`
}

// Infosheet is the agent's precomputed inventory (Spec 4.4).
type Infosheet struct {
	GeneratedAt int64        `json:"generated_at"`
	Duration    float64      `json:"duration"`
	Steps       int          `json:"steps"`
	Env         Env          `json:"env"`
	Plugins     []Component  `json:"plugins"`
	Themes      []Component  `json:"themes"`
	Tables      []TableInfo  `json:"tables"`
	PostTypes   []PostType   `json:"post_types"`
	OrphanMeta  OrphanMeta   `json:"orphan_meta"`
	Uploads     []UploadYear `json:"uploads"`
	Findings    []Finding    `json:"findings"`
}

// Component is a plugin or theme with its size on disk.
type Component struct {
	Slug    string `json:"slug"`
	Name    string `json:"name"`
	Version string `json:"version"`
	Active  bool   `json:"active"`
	Files   int64  `json:"files"`
	Bytes   int64  `json:"bytes"`
}

// TableInfo is a table with its class: content, config, pii, log, cache, backup or unknown.
// Essential tables (posts, options, users …) are always pulled with data.
type TableInfo struct {
	Name      string `json:"name"`
	Rows      int64  `json:"rows"`
	Bytes     int64  `json:"bytes"`
	Class     string `json:"class"`
	Plugin    string `json:"plugin"`
	Essential bool   `json:"essential"`
}

// PostType summarizes one post type including its postmeta.
type PostType struct {
	Name      string `json:"name"`
	Count     int64  `json:"count"`
	Bytes     int64  `json:"bytes"`
	MetaRows  int64  `json:"meta_rows"`
	MetaBytes int64  `json:"meta_bytes"`
	Class     string `json:"class"`
}

// OrphanMeta is postmeta whose post no longer exists.
type OrphanMeta struct {
	Rows  int64 `json:"rows"`
	Bytes int64 `json:"bytes"`
}

// UploadYear is one uploads/<year> folder; Year "other" collects everything else below uploads.
type UploadYear struct {
	Year  string `json:"year"`
	Files int64  `json:"files"`
	Bytes int64  `json:"bytes"`
}

// Finding is something the scan points out: backup_dir, large_file or drop_in.
type Finding struct {
	Kind  string `json:"kind"`
	Path  string `json:"path"`
	Bytes int64  `json:"bytes"`
}

// InfosheetStatus reports the background inventory on the agent.
type InfosheetStatus struct {
	Running     bool   `json:"running"`
	Phase       string `json:"phase"`
	Done        int64  `json:"done"`
	Total       int64  `json:"total"`
	GeneratedAt int64  `json:"generated_at"`
}

// Scope limits what the agent delivers (Spec 5.2); the zero value means everything.
type Scope struct {
	Tables           map[string]string `json:"tables,omitempty"` // table → structure | skip
	ExcludePostTypes []string          `json:"exclude_post_types,omitempty"`
	ExcludePlugins   []string          `json:"exclude_plugins,omitempty"`
	ExcludeThemes    []string          `json:"exclude_themes,omitempty"`
	UploadsSince     string            `json:"uploads_since,omitempty"`
}
