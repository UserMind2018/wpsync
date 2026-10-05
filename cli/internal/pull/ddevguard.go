package pull

import (
	"errors"
	"fmt"

	"github.com/usermind/wpsync/internal/ddev"
)

// openProject prepares the site's DDEV project before the first ddev call of a pull: containers
// that can still write to .ddev are stopped through docker (no ddev, so no hooks), and an existing
// site without a trusted .ddev state is taken over once the user confirmed it (--yes does not).
func openProject(o *Options, siteDir string) (*ddev.Project, error) {
	if o.DDEVState.Dir == "" {
		return nil, errors.New("no location for the trusted .ddev state")
	}
	docker := o.Docker
	if docker == nil {
		docker = ddev.DockerExec{}
	}
	project, err := ddev.OpenProject(o.Site.Name, siteDir, o.DDEVState, docker)
	if err != nil {
		return nil, err
	}
	stopped, err := ddev.StopUnhardened(docker, o.Site.Name, siteDir)
	if err != nil {
		return nil, fmt.Errorf("DDEV-Container von %s prüfen: %w", o.Site.Name, err)
	}
	if stopped {
		fmt.Fprintf(o.Out, "  DDEV-Container von %s konnten .ddev noch beschreiben – ohne ddev gestoppt, sie werden gehärtet neu erstellt\n", o.Site.Name)
	}
	if ddev.Exists(siteDir) && !project.Trusted() {
		if err := takeOver(project, o); err != nil {
			return nil, err
		}
	}
	return project, nil
}

// takeOver records the current .ddev of a site pulled before this check existed, after showing
// everything that reaches the Mac.
func takeOver(p *ddev.Project, o *Options) error {
	own, err := ddev.OwnFiles(o.MailguardSource)
	if err != nil {
		return err
	}
	changes, shown, content, err := p.Review()
	if err != nil {
		return err
	}
	fmt.Fprintf(o.Out, "wpsync prüft ab jetzt vor jedem ddev-Aufruf .ddev von %s. Einmalig wird der heutige Stand übernommen:\n", o.Site.Name)
	ddev.DescribeContent(o.Out, changes, content, own, true)
	if o.Confirm == nil {
		return fmt.Errorf("%w für %s – ohne Terminal keine Übernahme. Ansehen und übernehmen: wpsync trust %s", ddev.ErrNotAdopted, o.Site.Name, o.Site.Name)
	}
	if !o.Confirm("Diesen Stand von .ddev übernehmen?") {
		return ErrAborted
	}
	return p.Trust(shown)
}
