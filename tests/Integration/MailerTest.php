<?php
/**
 * Sending through SES with the HTTP layer faked at pre_http_request. Nothing leaves the container.
 *
 * @package Shakewell\SesMailer
 */

namespace Shakewell\SesMailer\Tests;

use Shakewell\SesMailer\Aws;
use Shakewell\SesMailer\Credentials;
use Shakewell\SesMailer\Plugin;
use Shakewell\SesMailer\SesError;
use WP_UnitTestCase;

/**
 * One test per behaviour, named for what it proves.
 */
final class MailerTest extends WP_UnitTestCase {

	private const T = 1793754123;

	private const ENV = array(
		'AWS_ACCESS_KEY_ID',
		'AWS_SECRET_ACCESS_KEY',
		'AWS_SESSION_TOKEN',
		'AWS_REGION',
		'AWS_DEFAULT_REGION',
		'AWS_EC2_METADATA_DISABLED',
		'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI',
		'AWS_CONTAINER_CREDENTIALS_FULL_URI',
		'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE',
		'AWS_CONTAINER_AUTHORIZATION_TOKEN',
	);

	/**
	 * Every HTTP request made: array of [method, url, args].
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Per-route replacements for the fake AWS (token, role, creds, ecs, ses): a response array or WP_Error.
	 *
	 * @var array
	 */
	private $routes = array();

	/**
	 * Original error_log ini value.
	 *
	 * @var string
	 */
	private $old_log;

	/**
	 * Temporary log file.
	 *
	 * @var string
	 */
	private $log_file;

	public function set_up(): void {
		parent::set_up();
		foreach ( self::ENV as $name ) {
			putenv( $name );
		}
		Credentials::reset();
		$this->calls  = array();
		$this->routes = array();

		$this->old_log  = (string) ini_get( 'error_log' );
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'seslog' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.

		add_filter( 'shakewell_ses_mailer_enabled', '__return_true' );
		add_filter(
			'shakewell_ses_mailer_region',
			static function () {
				return 'ap-southeast-2';
			}
		);
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		Plugin::boot();
	}

	public function tear_down(): void {
		foreach ( self::ENV as $name ) {
			putenv( $name );
		}
		Credentials::reset();
		ini_set( 'error_log', $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.
		unlink( $this->log_file );
		reset_phpmailer_instance(); // Back to the test suite's mock mailer, so later tests never reach a real PHPMailer.
		parent::tear_down();
	}

	/**
	 * The fake AWS. Defaults are a working instance role and a successful SES call.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->calls[] = array( $args['method'], $url, $args );

		$ok = static function ( string $body, int $code = 200, array $headers = array() ) {
			return array(
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'body'     => $body,
				'headers'  => $headers,
				'cookies'  => array(),
			);
		};
		$creds = static function ( string $id ) use ( $ok ) {
			return $ok(
				(string) wp_json_encode(
					array(
						'AccessKeyId'     => $id,
						'SecretAccessKey' => 'SECRET-' . $id,
						'Token'           => 'SESSION-' . $id,
						'Expiration'      => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
					)
				)
			);
		};

		if ( str_contains( $url, '/latest/api/token' ) ) {
			$key = 'token';
			$def = $ok( 'imds-token' );
		} elseif ( str_ends_with( $url, '/security-credentials/' ) ) {
			$key = 'role';
			$def = $ok( "role-name\n" );
		} elseif ( str_contains( $url, '/security-credentials/' ) ) {
			$key = 'creds';
			$def = $creds( 'AKIDIMDS' );
		} elseif ( str_contains( $url, 'email.' ) ) {
			$key = 'ses';
			$def = $ok( '{"MessageId":"abc"}' );
		} else {
			$key = 'ecs';
			$def = $creds( 'AKIDECS' );
		}

		return $this->routes[ $key ] ?? $def;
	}

	/**
	 * Requests to a host fragment.
	 *
	 * @param string $needle URL fragment.
	 */
	private function calls_to( string $needle ): array {
		return array_values( array_filter( $this->calls, static fn( $c ) => str_contains( $c[1], $needle ) ) );
	}

	/**
	 * Decoded SES request bodies sent so far.
	 */
	private function ses_requests(): array {
		return array_map( static fn( $c ) => json_decode( $c[2]['body'], true ), $this->calls_to( 'email.' ) );
	}

	/**
	 * The raw MIME message of the last SES request.
	 */
	private function raw(): string {
		$requests = $this->ses_requests();

		return (string) base64_decode( end( $requests )['Content']['Raw']['Data'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Log lines written by the plugin.
	 */
	private function log_lines(): array {
		return array_values( array_filter( explode( "\n", (string) file_get_contents( $this->log_file ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	private function ses_error( array $body, int $code, array $headers = array() ): array {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => $headers,
			'cookies'  => array(),
		);
	}

	private function creds_of_last_ses_call(): string {
		$ses = $this->calls_to( 'email.' );

		return end( $ses )[2]['headers']['Authorization'];
	}

	// ---- The switch. ----

	public function test_metadata_and_container_calls_never_use_a_proxy(): void {
		$this->assertFalse( \Shakewell\SesMailer\Aws::no_proxy( null, 'http://169.254.169.254/latest/api/token' ) );
		$this->assertFalse( \Shakewell\SesMailer\Aws::no_proxy( true, 'http://169.254.170.2/v2/credentials/abc' ) );
		$this->assertTrue( \Shakewell\SesMailer\Aws::no_proxy( true, 'https://email.ap-southeast-2.amazonaws.com/v2/email/outbound-emails' ) );
	}

	public function test_off_by_default_registers_nothing_and_sends_nothing(): void {
		remove_all_filters( 'shakewell_ses_mailer_enabled' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_actions( 'phpmailer_init' );
		Plugin::boot();

		$this->assertFalse( has_filter( 'pre_wp_mail', array( Plugin::class, 'pre_wp_mail' ) ) );
		$this->assertFalse( has_action( 'phpmailer_init', array( Plugin::class, 'phpmailer_init' ) ) );
		$this->assertTrue( wp_mail( 'a@example.com', 'Hi', 'Body' ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_switched_on_without_a_region_explains_itself_in_an_admin_notice(): void {
		remove_all_filters( 'shakewell_ses_mailer_region' );
		remove_all_actions( 'admin_notices' );
		Plugin::boot();
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'region_notice' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::region_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'SHAKEWELL_SES_MAILER_REGION', $html );

		remove_all_actions( 'admin_notices' );
		add_filter( 'shakewell_ses_mailer_region', static fn() => 'eu-west-1' );
		Plugin::boot();
		$this->assertFalse( has_action( 'admin_notices', array( Plugin::class, 'region_notice' ) ) );
	}

	public function test_switch_is_a_truthy_constant_or_the_filter(): void {
		remove_all_filters( 'shakewell_ses_mailer_enabled' );
		$this->assertFalse( Plugin::enabled() );
		add_filter( 'shakewell_ses_mailer_enabled', '__return_true' );
		$this->assertTrue( Plugin::enabled() );
	}

	// ---- Signing. ----

	public function test_sigv4_matches_a_recorded_botocore_signature(): void {
		$creds  = array(
			'AccessKeyId'     => 'AKIDEXAMPLE',
			'SecretAccessKey' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
			'Token'           => 'TOKEN123',
		);
		$signed = Aws::sign( $creds, 'ap-southeast-2', '/v2/email/outbound-emails', '{"Content":{"Raw":{"Data":"aGk="}}}', self::T );

		$this->assertStringEndsWith( 'Signature=9bae7711a417b165c60edb0a2bf57f09ba0469ffac1b52ef34f3123104d6b9d3', $signed['Authorization'] );
		$this->assertStringContainsString( 'Credential=AKIDEXAMPLE/20261104/ap-southeast-2/ses/aws4_request', $signed['Authorization'] );
		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-date;x-amz-security-token,', $signed['Authorization'] );
		$this->assertSame( 'TOKEN123', $signed['X-Amz-Security-Token'] );
	}

	public function test_sigv4_without_a_session_token_omits_it_everywhere(): void {
		$signed = Aws::sign( array( 'AccessKeyId' => 'AKID', 'SecretAccessKey' => 'SECRET' ), 'us-east-1', '/v2/email/outbound-emails', '{}', self::T ); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment

		$this->assertArrayNotHasKey( 'X-Amz-Security-Token', $signed );
		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-date,', $signed['Authorization'] );
	}

	// ---- Credential sources and their order. ----

	public function test_environment_variables_come_first_and_make_no_http_call(): void {
		putenv( 'AWS_ACCESS_KEY_ID=AKIDENV' );
		putenv( 'AWS_SECRET_ACCESS_KEY=SECRETENV' );
		putenv( 'AWS_SESSION_TOKEN=SESSIONENV' );
		putenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=/v2/credentials/x' );

		$this->assertTrue( wp_mail( 'a@example.com', 'Hi', 'Body' ) );
		$this->assertCount( 1, $this->calls );
		$this->assertStringContainsString( 'Credential=AKIDENV/', $this->creds_of_last_ses_call() );
		$this->assertSame( 'SESSIONENV', $this->calls[0][2]['headers']['X-Amz-Security-Token'] );
		$this->assertSame( 'env', Credentials::get()['Source'] );
	}

	public function test_environment_keys_without_a_session_token_still_sign(): void {
		putenv( 'AWS_ACCESS_KEY_ID=AKIDENV' );
		putenv( 'AWS_SECRET_ACCESS_KEY=SECRETENV' );

		$this->assertTrue( wp_mail( 'a@example.com', 'Hi', 'Body' ) );
		$this->assertArrayNotHasKey( 'X-Amz-Security-Token', $this->calls[0][2]['headers'] );
	}

	public function test_half_an_environment_pair_is_ignored(): void {
		putenv( 'AWS_ACCESS_KEY_ID=AKIDENV' );

		$this->assertSame( 'imds', Credentials::get( self::T )['Source'] );
	}

	public function test_container_endpoint_beats_instance_metadata(): void {
		putenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=/v2/credentials/abc' );

		$creds = Credentials::get( time() );

		$this->assertSame( 'ecs', $creds['Source'] );
		$this->assertSame( 'AKIDECS', $creds['AccessKeyId'] );
		$this->assertSame( 'http://169.254.170.2/v2/credentials/abc', $this->calls[0][1] );
		$this->assertCount( 1, $this->calls, 'IMDS is never asked when ECS answers.' );
		$this->assertArrayNotHasKey( 'Authorization', $this->calls[0][2]['headers'] );
	}

	public function test_container_full_uri_sends_the_token_from_the_file(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'ecstoken' );
		file_put_contents( $file, "file-token\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		putenv( 'AWS_CONTAINER_CREDENTIALS_FULL_URI=http://127.0.0.1:8000/creds' );
		putenv( 'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE=' . $file );
		putenv( 'AWS_CONTAINER_AUTHORIZATION_TOKEN=ignored-because-file-wins' );

		Credentials::get( time() );
		unlink( $file );

		$this->assertSame( 'http://127.0.0.1:8000/creds', $this->calls[0][1] );
		$this->assertSame( 'file-token', $this->calls[0][2]['headers']['Authorization'] );
	}

	public function test_container_full_uri_over_plain_http_to_a_remote_host_is_refused(): void {
		putenv( 'AWS_CONTAINER_CREDENTIALS_FULL_URI=http://example.com/creds' );

		$this->expectException( SesError::class );
		try {
			Credentials::get();
		} finally {
			$this->assertSame( array(), $this->calls );
		}
	}

	public function test_container_token_with_a_line_break_is_refused(): void {
		putenv( 'AWS_CONTAINER_CREDENTIALS_FULL_URI=https://creds.example.com/x' );
		putenv( "AWS_CONTAINER_AUTHORIZATION_TOKEN=a\r\nX-Evil: 1" );

		$this->expectException( SesError::class );
		Credentials::get();
	}

	public function test_instance_metadata_is_the_last_resort_and_follows_imdsv2(): void {
		$creds = Credentials::get( self::T );

		$this->assertSame( 'imds', $creds['Source'] );
		$this->assertSame( 'AKIDIMDS', $creds['AccessKeyId'] );
		$this->assertCount( 3, $this->calls );
		list( $token, $role, $key ) = $this->calls;
		$this->assertSame( array( 'PUT', 'http://169.254.169.254/latest/api/token' ), array( $token[0], $token[1] ) );
		$this->assertSame( '300', $token[2]['headers']['X-aws-ec2-metadata-token-ttl-seconds'] );
		$this->assertArrayNotHasKey( 'X-aws-ec2-metadata-token', $token[2]['headers'] );
		$this->assertSame( 'imds-token', $role[2]['headers']['X-aws-ec2-metadata-token'] );
		$this->assertSame( 'http://169.254.169.254/latest/meta-data/iam/security-credentials/role-name', $key[1] );
		$this->assertSame( 'imds-token', $key[2]['headers']['X-aws-ec2-metadata-token'] );
		$this->assertSame( array( 2, 2, 2 ), array_column( array_column( $this->calls, 2 ), 'timeout' ) );
	}

	public function test_metadata_can_be_switched_off_with_the_standard_variable(): void {
		putenv( 'AWS_EC2_METADATA_DISABLED=true' );

		try {
			Credentials::get();
			$this->fail( 'Expected SesError.' );
		} catch ( SesError $e ) {
			$this->assertSame( array(), $this->calls );
		}
	}

	public function test_aws_calls_never_follow_redirects(): void {
		Credentials::get( self::T );
		wp_mail( 'a@example.com', 'Hi', 'Body' );

		$this->assertNotEmpty( $this->calls );
		foreach ( $this->calls as $call ) {
			$this->assertSame( 0, $call[2]['redirection'] );
		}
	}

	public function test_credentials_are_reused_until_five_minutes_before_expiry_then_refreshed(): void {
		$expiry              = self::T + 3600;
		$this->routes['creds'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'AccessKeyId'     => 'A',
					'SecretAccessKey' => 'S',
					'Token'           => 'T',
					'Expiration'      => gmdate( 'Y-m-d\TH:i:s\Z', $expiry ),
				)
			),
		);

		Credentials::get( self::T );
		$this->assertCount( 3, $this->calls );
		Credentials::get( $expiry - 301 );
		$this->assertCount( 3, $this->calls, 'Reused 301 seconds before expiry.' );
		Credentials::get( $expiry - 300 );
		$this->assertCount( 6, $this->calls, 'Refreshed 300 seconds before expiry.' );
	}

	public function test_missing_expiration_means_fifteen_minutes(): void {
		$this->routes['creds'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"AccessKeyId":"A","SecretAccessKey":"S","Token":"T"}',
		);

		Credentials::get( self::T );
		Credentials::get( self::T + 599 );
		$this->assertCount( 3, $this->calls );
		Credentials::get( self::T + 600 );
		$this->assertCount( 6, $this->calls );
	}

	public function test_a_failed_lookup_is_not_cached(): void {
		$this->routes['token'] = array( 'response' => array( 'code' => 404 ), 'body' => '' ); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment
		try {
			Credentials::get( self::T );
			$this->fail( 'Expected SesError.' );
		} catch ( SesError $e ) {
			$this->assertSame( array( 'imds', 404, 'token' ), array( $e->stage, $e->status, $e->reason ) );
		}
		$this->routes = array();

		$this->assertSame( 'AKIDIMDS', Credentials::get( self::T )['AccessKeyId'] );
	}

	// ---- What reaches SES. ----

	public function test_plain_mail_goes_to_the_regional_endpoint_signed(): void {
		$this->assertTrue( wp_mail( 'a@example.com', 'Plain subject', 'Hello plain' ) );

		$this->assertCount( 1, $this->calls_to( 'email.' ) );
		$ses = $this->calls_to( 'email.' )[0];
		$this->assertSame( 'POST', $ses[0] );
		$this->assertSame( 'https://email.ap-southeast-2.amazonaws.com/v2/email/outbound-emails', $ses[1] );
		$this->assertSame( 10, $ses[2]['timeout'] );
		$this->assertStringContainsString( 'Credential=AKIDIMDS/', $this->creds_of_last_ses_call() );
		$this->assertSame( 'SESSION-AKIDIMDS', $ses[2]['headers']['X-Amz-Security-Token'] );
		$this->assertSame( array( 'ToAddresses' => array( 'a@example.com' ) ), $this->ses_requests()[0]['Destination'] );
		$this->assertSame( 'ses', $GLOBALS['phpmailer']->Mailer );
		$this->assertStringContainsString( 'Content-Type: text/plain', $this->raw() );
		$this->assertStringContainsString( 'Subject: Plain subject', $this->raw() );
		$this->assertStringContainsString( 'Hello plain', $this->raw() );
		$this->assertSame( array(), $this->log_lines(), 'Nothing is logged for a successful send.' );
	}

	public function test_configured_sender_replaces_only_the_wordpress_default(): void {
		wp_mail( 'a@example.com', 'Hi', 'Body' );
		$this->assertStringContainsString( 'From: Example Site <noreply@example.org>', $this->raw() );

		wp_mail( 'a@example.com', 'Hi', 'Body', array( 'From: Bookings <bookings@example.com>' ) );
		$this->assertStringContainsString( 'From: Bookings <bookings@example.com>', $this->raw() );
	}

	public function test_html_mail_keeps_its_content_type_and_body(): void {
		wp_mail( 'a@example.com', 'Hi', '<p>Hi <b>there</b></p>', array( 'Content-Type: text/html; charset=UTF-8' ) );

		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $this->raw() );
		$this->assertStringContainsString( '<p>Hi <b>there</b></p>', $this->raw() );
	}

	public function test_attachment_is_in_the_mime_message(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'att' );
		file_put_contents( $file, 'attachment-bytes-123' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_mail( 'a@example.com', 'Hi', 'Body', array(), array( $file ) );
		unlink( $file );

		$this->assertStringContainsString( 'multipart/mixed', $this->raw() );
		$this->assertStringContainsString( base64_encode( 'attachment-bytes-123' ), $this->raw() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertStringContainsString( 'filename=', $this->raw() );
	}

	public function test_cc_bcc_and_reply_to_and_custom_headers(): void {
		wp_mail(
			array( 'a@example.com', 'b@example.com' ),
			'Hi',
			'Body',
			array( 'Cc: c@example.com', 'Bcc: hidden@example.com', 'Reply-To: guest@example.com', 'X-Form-Id: 42' )
		);

		$this->assertSame(
			array(
				'ToAddresses'  => array( 'a@example.com', 'b@example.com' ),
				'CcAddresses'  => array( 'c@example.com' ),
				'BccAddresses' => array( 'hidden@example.com' ),
			),
			$this->ses_requests()[0]['Destination']
		);
		$this->assertStringContainsString( 'Cc: c@example.com', $this->raw() );
		$this->assertStringNotContainsString( 'hidden@example.com', $this->raw(), 'Bcc travels in the Destination only.' );
		$this->assertStringContainsString( 'Reply-To: guest@example.com', $this->raw() );
		$this->assertStringContainsString( 'X-Form-Id: 42', $this->raw() );
	}

	public function test_configuration_set_is_sent_only_when_valid(): void {
		wp_mail( 'a@example.com', 'Hi', 'Body' );
		$this->assertArrayNotHasKey( 'ConfigurationSetName', $this->ses_requests()[0] );

		add_filter(
			'shakewell_ses_mailer_configuration_set',
			static function () {
				return 'my-set_1';
			}
		);
		wp_mail( 'a@example.com', 'Hi', 'Body' );
		$this->assertSame( 'my-set_1', $this->ses_requests()[1]['ConfigurationSetName'] );

		remove_all_filters( 'shakewell_ses_mailer_configuration_set' );
		add_filter(
			'shakewell_ses_mailer_configuration_set',
			static function () {
				return 'bad name"}';
			}
		);
		wp_mail( 'a@example.com', 'Hi', 'Body' );
		$this->assertArrayNotHasKey( 'ConfigurationSetName', $this->ses_requests()[2] );
	}

	public function test_region_comes_from_the_environment_and_is_validated(): void {
		remove_all_filters( 'shakewell_ses_mailer_region' );
		putenv( 'AWS_REGION=eu-west-1' );
		wp_mail( 'a@example.com', 'Hi', 'Body' );

		$ses = $this->calls_to( 'email.' )[0];
		$this->assertSame( 'https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', $ses[1] );
		$this->assertStringContainsString( '/eu-west-1/ses/aws4_request', $ses[2]['headers']['Authorization'] );

		putenv( 'AWS_REGION=evil.example.com/x' );
		$this->assertSame( '', Plugin::region() );
	}

	// ---- Failures. ----

	public function test_ses_rejection_returns_false_fires_wp_mail_failed_and_logs_one_clean_line(): void {
		$this->routes['ses'] = $this->ses_error(
			array( 'message' => 'Email address is not verified: secret-rcpt@example.com' ),
			400,
			array( 'x-amzn-errortype' => 'MessageRejected:http://internal.amazon.com/coral/' )
		);
		$failure = null;
		add_action(
			'wp_mail_failed',
			static function ( $error ) use ( &$failure ) {
				$failure = $error;
			}
		);

		$this->assertFalse( wp_mail( 'secret-rcpt@example.com', 'Secret subject', 'Secret body' ) );

		$this->assertInstanceOf( \WP_Error::class, $failure );
		$this->assertSame( 'wp_mail_failed', $failure->get_error_code() );
		$this->assertStringContainsString( 'not verified', $failure->get_error_message() );
		$this->assertSame( 'Secret subject', $failure->get_error_data()['subject'] );

		$lines = $this->log_lines();
		$this->assertCount( 1, $lines );
		$this->assertMatchesRegularExpression( '/\[shakewell-ses-mailer\] send failed stage=ses status=400 reason=MessageRejected$/', $lines[0] );
		foreach ( array( 'secret-rcpt', 'Secret subject', 'Secret body', 'not verified', 'SESSION-AKIDIMDS', 'SECRET-AKIDIMDS', 'AKIDIMDS' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $lines[0] );
		}
	}

	public function test_reason_falls_back_to_the_json_type_and_garbage_becomes_none(): void {
		$this->routes['ses'] = $this->ses_error( array( '__type' => 'com.amazonaws.sesv2#AccountSuspendedException' ), 403 );
		wp_mail( 'a@example.com', 'Hi', 'Body' );
		$this->assertStringEndsWith( 'stage=ses status=403 reason=AccountSuspendedException', $this->log_lines()[0] );

		$this->routes['ses'] = $this->ses_error( array( '__type' => 'has spaces & <script>' ), 500 );
		wp_mail( 'a@example.com', 'Hi', 'Body' );
		$this->assertStringEndsWith( 'stage=ses status=500 reason=none', $this->log_lines()[1] );
	}

	public function test_ses_message_in_the_exception_is_capped(): void {
		$this->routes['ses'] = $this->ses_error( array( 'message' => str_repeat( 'x', 5000 ) ), 400 );
		try {
			Aws::post( array( 'Content' => array() ) );
			$this->fail( 'Expected SesError.' );
		} catch ( SesError $e ) {
			$this->assertLessThanOrEqual( 350, strlen( $e->getMessage() ) );
		}
	}

	public function test_transport_error_is_stage_ses_with_the_wordpress_code_as_reason(): void {
		$this->routes['ses'] = new \WP_Error( 'http_request_failed', 'cURL error 28: timed out for secret-rcpt@example.com' );

		$this->assertFalse( wp_mail( 'secret-rcpt@example.com', 'Hi', 'Body' ) );
		$this->assertStringEndsWith( 'stage=ses status=0 reason=http_request_failed', $this->log_lines()[0] );
		$this->assertStringNotContainsString( 'secret-rcpt', implode( '', $this->log_lines() ) );
	}

	public function test_missing_credentials_fail_the_send_with_stage_imds(): void {
		$this->routes['token'] = new \WP_Error( 'http_request_failed', 'connect to 169.254.169.254 failed' );
		$failed                = false;
		add_action(
			'wp_mail_failed',
			static function () use ( &$failed ) {
				$failed = true;
			}
		);

		$this->assertFalse( wp_mail( 'secret-rcpt@example.com', 'Hi', 'Body' ) );

		$this->assertTrue( $failed );
		$this->assertSame( array(), $this->calls_to( 'email.' ), 'No SES call without credentials.' );
		$lines = $this->log_lines();
		$this->assertCount( 1, $lines );
		$this->assertMatchesRegularExpression( '/stage=imds status=0 reason=http_request_failed$/', $lines[0] );
		$this->assertStringNotContainsString( '169.254', $lines[0] );
		$this->assertStringNotContainsString( 'secret-rcpt', $lines[0] );
	}

	public function test_no_region_fails_the_send_without_calling_aws(): void {
		remove_all_filters( 'shakewell_ses_mailer_region' );

		$this->assertFalse( wp_mail( 'a@example.com', 'Hi', 'Body' ) );
		$this->assertSame( array(), $this->calls );
		$this->assertStringEndsWith( 'stage=config status=0 reason=region', $this->log_lines()[0] );
	}

	public function test_unexpected_exception_logs_stage_internal_with_the_class_name_only(): void {
		Aws::report_failure( new \RuntimeException( 'secret-rcpt@example.com leaked' ) );

		$this->assertSame( '[shakewell-ses-mailer] send failed stage=internal status=0 reason=RuntimeException', preg_replace( '/^\[[^\]]+\] /', '', $this->log_lines()[0] ) );
	}

	// ---- Secrets. ----

	public function test_credentials_never_reach_options_transients_the_log_or_exceptions(): void {
		global $wpdb;
		putenv( 'AWS_ACCESS_KEY_ID=AKIDLEAKCHECK' );
		putenv( 'AWS_SECRET_ACCESS_KEY=SECRETLEAKCHECK' );
		putenv( 'AWS_SESSION_TOKEN=SESSIONLEAKCHECK' );
		$this->routes['ses'] = $this->ses_error( array( 'message' => 'denied' ), 403 );

		wp_mail( 'a@example.com', 'Hi', 'Body' );
		$this->routes = array();
		wp_mail( 'a@example.com', 'Hi', 'Body' );
		try {
			$this->routes['ses'] = $this->ses_error( array( 'message' => 'denied' ), 403 );
			Aws::post( array( 'Content' => array() ) );
		} catch ( SesError $e ) {
			$dump = $e->getMessage() . $e->getTraceAsString() . $e->stage . $e->reason;
		}

		$stored = (string) $wpdb->get_var( "SELECT GROUP_CONCAT(option_value) FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cache  = wp_json_encode( wp_cache_get( 'alloptions', 'options' ) );
		foreach ( array( 'AKIDLEAKCHECK', 'SECRETLEAKCHECK', 'SESSIONLEAKCHECK' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored );
			$this->assertStringNotContainsString( $secret, (string) $cache );
			$this->assertStringNotContainsString( $secret, implode( '', $this->log_lines() ) );
			$this->assertStringNotContainsString( $secret, $dump );
		}
	}

	public function test_source_never_touches_options_cache_or_files_and_logs_in_one_place(): void {
		$source = '';
		foreach ( glob( dirname( __DIR__, 2 ) . '/src/*.php' ) as $file ) {
			$source .= file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$this->assertDoesNotMatchRegularExpression( '/\b(get_option|update_option|add_option|set_transient|get_transient|wp_cache_set|set_site_transient|file_put_contents)\s*\(/', $source );
		$this->assertSame( 1, substr_count( $source, 'error_log(' ) );
	}

	public function test_everything_that_carries_credentials_or_content_is_sensitive_parameter(): void {
		$sensitive = static function ( \ReflectionFunctionAbstract $f, int $i ): bool {
			$attrs = $f->getParameters()[ $i ]->getAttributes( \SensitiveParameter::class );
			return 1 === count( $attrs );
		};

		$this->assertTrue( $sensitive( new \ReflectionMethod( Aws::class, 'sign' ), 0 ) );
		$this->assertTrue( $sensitive( new \ReflectionMethod( Aws::class, 'sign' ), 3 ) );
		$this->assertTrue( $sensitive( new \ReflectionMethod( Aws::class, 'http' ), 2 ) );
		$this->assertTrue( $sensitive( new \ReflectionMethod( Aws::class, 'http' ), 3 ) );
		$this->assertTrue( $sensitive( new \ReflectionMethod( Aws::class, 'post' ), 0 ) );
		$this->assertTrue( $sensitive( new \ReflectionMethod( \Shakewell\SesMailer\Mailer::class, 'sesSend' ), 0 ) );
		$this->assertTrue( $sensitive( new \ReflectionMethod( \Shakewell\SesMailer\Mailer::class, 'sesSend' ), 1 ) );
		$this->assertTrue( $sensitive( new \ReflectionMethod( Credentials::class, 'parse' ), 0 ) );
	}

	// ---- Reliability. ----

	public function test_pre_wp_mail_hands_the_value_back_untouched(): void {
		$this->assertSame( 'untouched', Plugin::pre_wp_mail( 'untouched' ) );
		$this->assertNull( Plugin::pre_wp_mail( null ) );
	}

	public function test_a_failed_send_is_not_retried_or_queued(): void {
		$this->routes['ses'] = $this->ses_error( array( 'message' => 'x' ), 500 );

		$this->assertFalse( wp_mail( 'a@example.com', 'Hi', 'Body' ) );
		$this->assertCount( 1, $this->calls_to( 'email.' ) );
		$this->assertFalse( wp_next_scheduled( 'wp_mail_retry' ) );
	}

	/**
	 * Keep last: it defines a constant that stays for the rest of the process.
	 */
	public function test_zz_configured_reply_to_replaces_the_callers(): void {
		define( 'SHAKEWELL_SES_MAILER_REPLY_TO', 'info@example.org' );

		wp_mail( 'a@example.com', 'Hi', 'Body', array( 'Reply-To: guest@example.com' ) );

		$this->assertStringContainsString( 'Reply-To: info@example.org', $this->raw() );
		$this->assertStringNotContainsString( 'guest@example.com', $this->raw() );
	}
}
