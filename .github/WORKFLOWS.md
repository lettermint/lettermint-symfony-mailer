# GitHub workflows

Packagist updates the package through its repository hook.

- `ci.yml`: runs the compatibility matrix, audits, and package builds on pull requests, pushes to main/next, and manual runs. Keeps build artifacts for seven days.
- `update-changelog.yml`: runs when a stable GitHub release is released. Opens a pull request with the release notes for `CHANGELOG.md`.
- `release-to-discord.yml`: runs when a GitHub release is published, or on a manual run. Skips notifications when the webhook secret is absent.
- `dependabot.yml`: checks GitHub Actions and Composer dependencies each week.

The changelog and Discord workflows use the same release triggers as the PHP SDK. Changelog changes use a pull request to meet this repository's review rule.

## Setup

1. Submit the repository to Packagist and enable its automatic update hook.
2. Set the optional repository secret `DISCORD_RELEASE_WEBHOOK_URL`.
3. Enable GitHub Actions to create pull requests. Changelog pull requests require review before merge.

## Release

1. Check that CI passes for the commit to release.
2. Publish a GitHub release with a version tag, such as `1.0.1`.
3. Review and merge the changelog pull request.

Composer derives the package version from the Git tag. Prereleases do not update the stable changelog. Packagist processes its hook independently of the Discord notification.

Local API source verification remains a separate command because this repository does not contain the private API checkout. CI runs the pinned contract tests.
