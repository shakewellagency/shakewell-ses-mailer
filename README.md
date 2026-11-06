# Shakewell plugin template

The starting point for every Shakewell WordPress plugin. Each plugin built from it is free and open source (GPL-2.0-or-later), Shakewell-owned and maintained, and written so it can be listed in the WordPress plugin directory.

## Rules every plugin follows

- **Small and single-purpose.** Build only what the sites need. Fewer lines means less to attack and less to break.
- **Generic.** Anything site-specific is a setting, a constant or a filter, never a value in code.
- **No paid dependencies** where it can be avoided. Core WordPress storage first. A plugin that extends ACF or Gravity Forms says so in its readme.
- **Directory rules from day one** ([guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)): GPL, no tracking or calls home, no locked features, every outside service named in the readme, translatable strings.
- **Security basics on every action:** capability check, nonce check, sanitise input, escape output, prepared SQL, no secrets in options or logs.
- **Naming:** repository, folder, main file, slug and text domain are all `shakewell-<name>`. PHP namespace `Shakewell\<Name>`, global prefix `shakewell_<name>_`. Never start a name with someone else's trademark ("Shakewell Mailer for Amazon SES", not "Amazon SES Mailer"), and never use the words "plugin", "WordPress" or "WP" in a name: the directory refuses them. The template's own name breaks this on purpose; it is never listed.

## Make a new plugin

1. On GitHub: **Use this template** → `shakewellagency/shakewell-<name>`, private until it is listed.
2. Rename, all in one go: `shakewell-ses-mailer` → `shakewell-<name>`, `Shakewell\SesMailer` → `Shakewell\<Name>`, `shakewell_ses_mailer` → `shakewell_<name>`, `Shakewell Mailer for Amazon SES` → the plugin's name. Rename the main file to `shakewell-<name>.php`.
3. Write `readme.txt` (description, external services, changelog) and turn on private vulnerability reporting (Settings → Code security).

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8888 (admin / password), needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check, as the directory runs it
```

CI runs the same on every pull request, on the lowest supported WordPress and PHP and on the latest.

## Release

1. Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, and add a changelog line.
2. Tag `vX.Y.Z` and push. The release workflow checks the three versions match and attaches `shakewell-<name>.zip` to a GitHub release.

## Install on a client site (Bedrock)

Until a plugin is listed, install from the private repository:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/shakewellagency/shakewell-<name>" }
],
"require": { "shakewellagency/shakewell-<name>": "^1.0" }
```

The build needs a read-only GitHub token for the repository (`composer config github-oauth.github.com <token>`, stored as a pipeline secret). Once listed, switch to `wpackagist-plugin/shakewell-<name>` and drop the token.
