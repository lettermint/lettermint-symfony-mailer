# GitHub workflows

Packagist updates the package through its repository hook. GitHub Actions does not call the Packagist API.

- `ci.yml`: pull requests, pushes to main/next, manual checks, and release checks. Runs the compatibility matrix, audits, and package builds. Keeps build artifacts for seven days.
- `release.yml`: a published GitHub release runs the full CI matrix, checks the tag, and builds the package. Keeps release artifacts for 30 days.
- `update-changelog.yml`: called after the release checks and build pass. Opens a pull request with stable release notes for `CHANGELOG.md`.
- `release-to-discord.yml`: called after the release checks and build pass. Skips notifications when the webhook secret is absent.
- `dependabot.yml`: weekly updates for GitHub Actions and Composer dependencies.

## Setup

1. Submit the repository to Packagist and enable its automatic update hook.
2. Set the optional repository secret `DISCORD_RELEASE_WEBHOOK_URL`.
3. Enable GitHub Actions to create pull requests if you use the changelog workflow. Its pull request requires review; the workflow does not bypass branch rules.

No Packagist credentials or release environment are required by these workflows.

## Release

Publish a GitHub release with a tag such as `v0.1.0`. Composer derives the package version from the Git tag. Prereleases do not update the stable changelog.

Packagist processes its hook independently. The release workflow does not check whether Packagist has finished indexing the tag before it sends a Discord notification.

Local API source verification remains a separate command because this repository does not contain the private API checkout. CI runs the pinned contract tests.
