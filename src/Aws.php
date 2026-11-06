<?php
/**
 * HTTP, Signature Version 4 and the SES v2 SendEmail call. No AWS SDK.
 *
 * @package Shakewell\SesMailer
 */

namespace Shakewell\SesMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Credentials are passed in and used for one request. Parameters that carry credentials or message
 * content are SensitiveParameter, so PHP leaves them out of backtraces (PHP 8.2 and later).
 */
final class Aws {

	/**
	 * Reduces an AWS error type or WordPress error code to a bare token, or '' if it is anything else.
	 * "MessageRejected:http://internal.amazon.com/..." and "com.amazonaws.sesv2#MessageRejected" both
	 * become "MessageRejected", so nothing but a fixed identifier can reach the log.
	 *
	 * @param string $raw Raw type or code.
	 */
	public static function token( string $raw ): string {
		$raw = explode( ':', $raw, 2 )[0];
		$raw = substr( $raw, (int) strrpos( '#' . $raw, '#' ) );

		return preg_match( '/^[A-Za-z0-9_]{1,64}$/', $raw ) ? $raw : '';
	}

	/**
	 * One HTTP call through the WordPress HTTP API. A transport failure throws, tagged with $stage.
	 * Redirects are refused so credentials in headers never follow one.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body    Request body.
	 * @param int                  $timeout Seconds.
	 * @param string               $stage   Stage token for failures.
	 * @return array{0:int,1:string,2:string} Status, body, x-amzn-errortype header.
	 * @throws SesError On a transport error.
	 */
	public static function http(
		string $method,
		string $url,
		#[\SensitiveParameter] array $headers,
		#[\SensitiveParameter] string $body = '',
		int $timeout = 10,
		string $stage = 'ses'
	): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; it goes to wp_mail_failed, and only fixed tokens are logged.
			throw new SesError( $response->get_error_message(), $stage, 0, self::token( (string) $response->get_error_code() ) );
		}

		return array(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(string) wp_remote_retrieve_header( $response, 'x-amzn-errortype' ),
		);
	}

	/**
	 * Signature Version 4 headers for a JSON POST to the SES v2 API.
	 *
	 * @param array  $creds  AccessKeyId, SecretAccessKey and optional Token.
	 * @param string $region AWS region.
	 * @param string $path   Request path.
	 * @param string $body   JSON body.
	 * @param int    $now    Unix time.
	 * @return array<string,string>
	 */
	public static function sign( #[\SensitiveParameter] array $creds, string $region, string $path, #[\SensitiveParameter] string $body, int $now ): array {
		$token    = $creds['Token'] ?? '';
		$amz_date = gmdate( 'Ymd\THis\Z', $now );
		$day      = gmdate( 'Ymd', $now );
		$scope    = "$day/$region/ses/aws4_request";

		$headers = array(
			'content-type' => 'application/json',
			'host'         => "email.$region.amazonaws.com", // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the SES API host being signed, not an offloaded asset.
			'x-amz-date'   => $amz_date,
		);
		if ( '' !== $token ) {
			$headers['x-amz-security-token'] = $token;
		}
		ksort( $headers );
		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= "$name:$value\n";
		}
		$signed = implode( ';', array_keys( $headers ) );

		$canonical = implode( "\n", array( 'POST', $path, '', $canonical_headers, $signed, hash( 'sha256', $body ) ) );
		$to_sign   = implode( "\n", array( 'AWS4-HMAC-SHA256', $amz_date, $scope, hash( 'sha256', $canonical ) ) );
		$key       = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', 'ses', hash_hmac( 'sha256', $region, hash_hmac( 'sha256', $day, 'AWS4' . $creds['SecretAccessKey'], true ), true ), true ), true );
		$signature = hash_hmac( 'sha256', $to_sign, $key );
		$out       = array(
			'Content-Type'  => 'application/json',
			'X-Amz-Date'    => $amz_date,
			'Authorization' => "AWS4-HMAC-SHA256 Credential={$creds['AccessKeyId']}/$scope, SignedHeaders=$signed, Signature=$signature",
		);
		if ( '' !== $token ) {
			$out['X-Amz-Security-Token'] = $token;
		}

		return $out;
	}

	/**
	 * Sends one SES v2 SendEmail request (Content.Raw). No retry: a failed send is reported once
	 * (wp_mail_failed, one log line) and not queued.
	 *
	 * @param array $request SES SendEmail body, with Content.Raw.Data already base64 encoded.
	 * @throws SesError On any failure.
	 */
	public static function post( #[\SensitiveParameter] array $request ): void {
		$region = Plugin::region();
		if ( '' === $region ) {
			throw new SesError( 'No valid AWS region is configured.', 'config', 0, 'region' );
		}

		$now   = time();
		$path  = '/v2/email/outbound-emails';
		$body  = (string) wp_json_encode( $request );
		$creds = Credentials::get( $now );

		$headers = self::sign( $creds, $region, $path, $body, $now );
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the SES API endpoint (documented in the readme), not an offloaded asset.
		list( $status, $response, $type ) = self::http( 'POST', "https://email.$region.amazonaws.com$path", $headers, $body );

		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $response, true );
			$decoded = is_array( $decoded ) ? $decoded : array();
			$message = $decoded['message'] ?? $response;
			$reason  = self::token( $type ) ? self::token( $type ) : self::token( (string) ( $decoded['__type'] ?? $decoded['code'] ?? '' ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; it goes to wp_mail_failed, and only fixed tokens are logged.
			throw new SesError( sprintf( 'Amazon SES refused the message (HTTP %d): %s', $status, mb_substr( (string) $message, 0, 300 ) ), 'ses', $status, $reason );
		}
	}

	/**
	 * One log line per failed send, through the PHP error log. Stage, status and reason only:
	 * no recipient, subject, body, credential or SES text.
	 *
	 * @param \Throwable $e The failure.
	 */
	public static function report_failure( \Throwable $e ): void {
		if ( $e instanceof SesError ) {
			list( $stage, $status, $reason ) = array( $e->stage, $e->status, $e->reason );
		} else {
			list( $stage, $status, $reason ) = array( 'internal', 0, self::token( ( new \ReflectionClass( $e ) )->getShortName() ) );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the one deliberate log call; the line holds fixed tokens only.
		error_log( sprintf( '[shakewell-ses-mailer] send failed stage=%s status=%d reason=%s', $stage, $status, '' !== $reason ? $reason : 'none' ) );
	}
}
