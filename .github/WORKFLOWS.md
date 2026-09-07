# GitHub workflows

CI runs on GitHub. Release publishing requires the registry setup below.

- `ci.yml`: pull requests, pushes to main/next, manual checks, and release checks. Runs the compatibility matrix, audits, and package builds. Keeps build artifacts for seven days.
- `release.yml`: a published GitHub release runs the full CI matrix, checks the tag, then publishes the package. Keeps release artifacts for 30 days.
- `update-changelog.yml`: called after successful publication. Opens a pull request with stable release notes for `CHANGELOG.md`.
- `release-to-discord.yml`: called after successful publication. Skips notifications when the webhook secret is absent.
- `dependabot.yml`: weekly updates for GitHub Actions and composer dependencies.

## Setup

1. Configure package ownership in the registry.
2. Set `PACKAGIST_USERNAME` and `PACKAGIST_API_TOKEN` as repository secrets or secrets in the `release` environment.
3. Set the optional repository secret `DISCORD_RELEASE_WEBHOOK_URL`.
4. Enable GitHub Actions to create pull requests if you use the changelog workflow. Its pull request requires review; the workflow does not bypass branch rules.

The publish job uses the `release` environment. Environment review rules, if configured in GitHub, still apply. CI jobs do not receive registry or webhook credentials.

## Release

Update the package version and version strings in the source before tagging. Publish a GitHub release from that commit with a tag such as `v0.1.0`. The tag must match the .NET or Elixir package version. Composer derives its version from the tag. Prereleases can publish but do not update the stable changelog.

Local API source verification remains a separate command because these repositories do not contain the private API checkout. CI runs the pinned contract tests.

Submit the public repository to Packagist once before the first release. The release job requests a Packagist update; it does not create the package listing.

Packagist can also index Git tags through its own webhook. CI gates this workflow's update request, not an independently configured Packagist webhook.
