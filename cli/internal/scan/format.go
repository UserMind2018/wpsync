package scan

import (
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Bytes formats a size the German way: 812 KB, 1,2 GB.
func Bytes(n int64) string {
	units := []string{"B", "KB", "MB", "GB", "TB"}
	f := float64(n)
	i := 0
	for f >= 1024 && i < len(units)-1 {
		f /= 1024
		i++
	}
	if i == 0 || f >= 100 {
		return fmt.Sprintf("%.0f %s", f, units[i])
	}
	return strings.Replace(fmt.Sprintf("%.1f %s", f, units[i]), ".", ",", 1)
}

// Count formats a number with thousands dots: 12.431.
func Count(n int64) string {
	s := strconv.FormatInt(n, 10)
	sign := ""
	if strings.HasPrefix(s, "-") {
		sign, s = "-", s[1:]
	}
	var b strings.Builder
	for i, r := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(r)
	}
	return sign + b.String()
}

// Age says how long ago something happened: vor 12 min, vor 3 h, vor 2 Tagen.
func Age(d time.Duration) string {
	switch {
	case d < time.Minute:
		return "gerade eben"
	case d < time.Hour:
		return fmt.Sprintf("vor %d min", int(d.Minutes()))
	case d < 48*time.Hour:
		return fmt.Sprintf("vor %d h", int(d.Hours()))
	default:
		return fmt.Sprintf("vor %d Tagen", int(d.Hours()/24))
	}
}
