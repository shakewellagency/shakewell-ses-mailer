=== Shakewell Mailer for Amazon SES ===
Contributors: shakewellagency
Tags: email, ses, amazon ses, wp_mail, aws
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress email through Amazon SES using the AWS credentials your server already has. No key is ever stored in WordPress.

== Description ==

WordPress hands every email to `wp_mail()`. This plugin keeps that function exactly as it is (HTML and plain bodies, attachments, CC, BCC, Reply-To, custom headers, filters and the `wp_mail_failed` action) and replaces only the last step: instead of PHP's `mail()` or an SMTP server, the finished message goes to the Amazon SES v2 API in one signed HTTPS request.

Why it exists: most SES plugins ask you to paste an access key into the WordPress dashboard, where it sits in the database. This one never asks. It reads the credentials that the server's own AWS identity already provides, in the standard AWS order:

1. Environment variables: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and optionally `AWS_SESSION_TOKEN`.
2. The container credentials endpoint used by Amazon ECS and AWS Fargate (`AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` or `AWS_CONTAINER_CREDENTIALS_FULL_URI`, with an optional authorization token or token file).
3. The EC2 instance role, through the instance metadata service (IMDSv2, token first).

Credentials live in memory for the request. They are never read from or written to the database, options, transients, the object cache or files, and never logged.

What it deliberately does not do: no settings screen, no email log, no queue, no retry, no AWS SDK, no tracking. A failed send returns `false` from `wp_mail()`, fires `wp_mail_failed`, and writes one line to the PHP error log naming the stage, HTTP status and error type, never a recipient, subject, body or credential.

It is off until you switch it on, so installing it changes nothing by itself.

= Configuration =

Set these as PHP constants (for example in `wp-config.php`). None of them is a secret.

* `SHAKEWELL_SES_MAILER_ENABLED`: `true` turns the mailer on. Default: off.
* `SHAKEWELL_SES_MAILER_REGION`: the SES region, for example `eu-west-1`. If unset, the `AWS_REGION` or `AWS_DEFAULT_REGION` environment variable is used. There is no built-in default: with no valid region, sending fails with `reason=region` and an admin notice tells administrators to set one.
* `SHAKEWELL_SES_MAILER_CONFIGURATION_SET`: optional SES configuration set name.
* `SHAKEWELL_SES_MAILER_FROM`: replaces the WordPress default sender (`wordpress@your-site`). An explicit From address is always kept.
* `SHAKEWELL_SES_MAILER_FROM_NAME`: replaces the default sender name "WordPress".
* `SHAKEWELL_SES_MAILER_REPLY_TO`: a Reply-To that replaces any the caller set.

Filters: `shakewell_ses_mailer_enabled`, `shakewell_ses_mailer_region` and `shakewell_ses_mailer_configuration_set`.

= External services =

This plugin connects to the following services, only while it is switched on and only when WordPress sends an email.

**Amazon Simple Email Service (Amazon SES)**, run by Amazon Web Services. Each email WordPress sends is delivered to `https://email.REGION.amazonaws.com` (the region you configure): the full message including sender, recipients (To, CC and BCC), subject, body, headers and attachments, signed with your AWS credentials. Nothing is sent when no email is sent.

* AWS Service Terms: https://aws.amazon.com/service-terms/
* AWS Privacy Notice: https://aws.amazon.com/privacy/

**AWS instance metadata service (EC2) and container credentials endpoint (ECS and Fargate)**, local to the server and run by Amazon Web Services. When no credentials are set in environment variables, the plugin asks `http://169.254.169.254` (EC2) or `http://169.254.170.2` (or the address in `AWS_CONTAINER_CREDENTIALS_FULL_URI`, for ECS and Fargate) for the temporary credentials of the server's own AWS role. It sends no personal data, only the request for credentials (and, for the container endpoint, the authorization token you configured). These addresses are reachable only from inside AWS. The credentials are used to sign the SES request and are not stored.

* AWS Service Terms: https://aws.amazon.com/service-terms/
* AWS Privacy Notice: https://aws.amazon.com/privacy/

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer, and activate it.
2. Verify your sending domain or address in Amazon SES and move the account out of the SES sandbox if you need to send to unverified recipients.
3. Give the server an AWS identity with the policy below, using one of the three setups that follow.
4. Add the constants (at least `SHAKEWELL_SES_MAILER_ENABLED` and the region) and send a test email.

If WP Offload SES or another plugin that replaces `wp_mail()` is active, deactivate it first. Only one plugin can own `wp_mail()`.

= Minimal IAM policy =

`{ "Version": "2012-10-17", "Statement": [ { "Effect": "Allow", "Action": [ "ses:SendEmail", "ses:SendRawEmail" ], "Resource": "*" } ] }`

Narrow `Resource` to your verified identity ARN (`arn:aws:ses:REGION:ACCOUNT:identity/example.com`) if you prefer. The plugin calls `SendEmail` with raw content.

= Amazon EC2 or Elastic Beanstalk =

Attach an instance profile with the policy above. Nothing else is needed: the plugin finds the role through the metadata service. IMDSv2 is supported and used; a hop limit of 1 is fine for PHP running directly on the instance, but use 2 if PHP runs in a container on it.

= Amazon ECS or AWS Fargate =

Give the task a task role with the policy above. ECS sets `AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` for the container. Make sure PHP can see it: with PHP-FPM, set `clear_env = no` in the pool configuration, or pass the variable on explicitly.

= Environment variables =

Set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and, for temporary credentials, `AWS_SESSION_TOKEN` in the server's environment (not in WordPress). Prefer a role over long-lived keys wherever you can.

== Frequently Asked Questions ==

= Does it store my AWS credentials? =

No. They are read for each request from the server's environment or AWS metadata endpoints, held in memory and discarded. There is no settings field for them.

= Which credential source is used? =

The first that answers, in this order: environment variables, the ECS or Fargate container endpoint, the EC2 instance role.

= Can I check what is failing? =

Failed sends write one line to the PHP error log, for example `[shakewell-ses-mailer] send failed stage=ses status=400 reason=MessageRejected`. `stage` is `config`, `credentials`, `imds`, `ecs`, `ses` or `internal`. `status` is the HTTP status, or 0 when there was no response. `reason` is the AWS error type or a short token. The line never contains recipients, subjects, bodies, credentials or text from SES.

= Does it retry or queue email? =

No. Email is sent during the request that called `wp_mail()`. A slow SES adds up to 10 seconds to that request (2 seconds per credential lookup). A failure is reported once and not retried.

= How do I turn it off? =

Remove the constant or set it to `false`. The plugin then registers nothing and `wp_mail()` behaves as if it were not installed.

= Does it work with PHP 8.1? =

Yes. On PHP 8.2 and later, PHP also redacts credentials and message content from stack traces, because the plugin marks those parameters as sensitive.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.1 =
* Works on any site: removed wording that assumed one hosting setup.
* Shows a dismissible admin notice when the mailer is switched on but no valid AWS region is set.
* Readme contributor corrected.

= 0.1.0 =
* First release.
