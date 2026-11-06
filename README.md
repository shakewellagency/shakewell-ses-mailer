# Shakewell Mailer for Amazon SES

Sends `wp_mail()` through Amazon SES with the AWS credentials the server already has. No key is stored in WordPress. Free, open source (GPL-2.0-or-later). Setup, configuration and external services are in [readme.txt](readme.txt).

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8892, tests site on 8893, needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check
npm run env:stop
```

Release: bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, add a changelog line, tag `vX.Y.Z`.
