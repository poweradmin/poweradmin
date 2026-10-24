# Release checklist

Website and docs steps to run after a release is tagged and published on GitHub.

## Repository

- [ ] README: if the stable/LTS line changed, update the production warning under "Traditional Installation" (`release/x.y.x` LTS branch, example tag and Docker tag) and the release branch list
- [ ] GitHub release notes link to the upgrade guide on docs.poweradmin.org

## poweradmin.org (Substack)

- [ ] About page (poweradmin.org/about -> Edit page -> Save): add notable new features to the matching group (DNS management, PowerDNS integration, Automation, Security and access, Interface and deployment); skip for bugfix releases
- [ ] Release post
  - [ ] Title with a search phrase, e.g. "Poweradmin 4.6: <feature> in the PowerDNS web interface", not just "Release 4.6.0"
  - [ ] First paragraph names PowerDNS and the headline features in plain words
  - [ ] Settings -> SEO Options: custom SEO title and description
  - [ ] Settings -> Thumbnail: 1200x630 image (logo, version, headline feature)
  - [ ] Links to the GitHub release, upgrade docs and the Support page

## Search phrases to reuse

PowerDNS web interface, PowerDNS GUI, PowerDNS admin panel, PowerDNS REST API, PowerDNS Terraform provider, ExternalDNS PowerDNS, cert-manager PowerDNS, PowerDNS DNSSEC management
