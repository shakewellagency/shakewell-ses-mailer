<?php
/**
 * Plugin bootstrap and configuration.
 *
 * @package Shakewell\SesMailer
 */

namespace Shakewell\SesMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Core wp_mail() still does all the parsing (HTML and plain bodies, attachments, Reply-To, CC/BCC,
 * headers, filters, wp_mail_failed). Only the transport is swapped, by a PHPMailer subclass.
 *
 * Constants (wp-config.php or the environment):
 * SHAKEWELL_SES_MAILER_ENABLED, _REGION, _CONFIGURATION_SET, _FROM, _FROM_NAME, _REPLY_TO.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded. Does nothing unless the mailer is switched on.
	 */
	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}

		add_filter( 'pre_http_send_through_proxy', array( Aws::class, 'no_proxy' ), 10, 2 );
		add_filter( 'pre_wp_mail', array( self::class, 'pre_wp_mail' ), 1 );
		add_action( 'phpmailer_init', array( self::class, 'phpmailer_init' ), 99 );
		add_filter( 'wp_mail_from', array( self::class, 'from' ), 1 );
		add_filter( 'wp_mail_from_name', array( self::class, 'from_name' ), 1 );
	}

	/**
	 * Whether the mailer is switched on.
	 */
	public static function enabled(): bool {
		$on = defined( 'SHAKEWELL_SES_MAILER_ENABLED' ) && filter_var( constant( 'SHAKEWELL_SES_MAILER_ENABLED' ), FILTER_VALIDATE_BOOLEAN );

		/**
		 * Filters whether the mailer is on. Runs once, on plugins_loaded.
		 *
		 * @param bool $on Whether SHAKEWELL_SES_MAILER_ENABLED is true.
		 */
		return (bool) apply_filters( 'shakewell_ses_mailer_enabled', $on );
	}

	/**
	 * A non-secret string constant, or ''.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant( string $name ): string {
		return defined( $name ) && is_string( constant( $name ) ) ? trim( constant( $name ) ) : '';
	}

	/**
	 * The SES region: constant, then AWS_REGION, then AWS_DEFAULT_REGION, then the filter.
	 * Empty when none is set or it is not a valid region name, so the send fails with reason=region.
	 */
	public static function region(): string {
		$region = self::constant( 'SHAKEWELL_SES_MAILER_REGION' );
		foreach ( array( 'AWS_REGION', 'AWS_DEFAULT_REGION' ) as $name ) {
			if ( '' === $region ) {
				$value  = getenv( $name );
				$region = is_string( $value ) ? trim( $value ) : '';
			}
		}

		/**
		 * Filters the SES region.
		 *
		 * @param string $region Region from the constant or environment, '' if none.
		 */
		$region = (string) apply_filters( 'shakewell_ses_mailer_region', $region );

		return preg_match( '/^[a-z]{2}(-[a-z]+)+-\d$/', $region ) ? $region : '';
	}

	/**
	 * The optional SES configuration set name, or ''.
	 */
	public static function configuration_set(): string {
		/**
		 * Filters the SES configuration set name.
		 *
		 * @param string $name Name from SHAKEWELL_SES_MAILER_CONFIGURATION_SET.
		 */
		$name = (string) apply_filters( 'shakewell_ses_mailer_configuration_set', self::constant( 'SHAKEWELL_SES_MAILER_CONFIGURATION_SET' ) );

		return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $name ) ? $name : '';
	}

	/**
	 * Makes sure wp_mail() uses our PHPMailer subclass. Returns $pre untouched, so core carries on.
	 * Loading the class here keeps PHPMailer out of requests that send nothing.
	 *
	 * @param mixed $pre Value of the pre_wp_mail filter.
	 * @return mixed
	 */
	public static function pre_wp_mail( $pre ) {
		global $phpmailer;

		if ( ! ( $phpmailer instanceof Mailer ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
			require_once __DIR__ . '/Mailer.php';

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core's wp_mail() reuses this global; it is how the transport is swapped.
			$phpmailer             = new Mailer( true );
			$phpmailer::$validator = static function ( $email ) {
				return (bool) is_email( $email );
			};
		}

		return $pre;
	}

	/**
	 * Switches the transport to ours, and applies the Reply-To override.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer The mailer wp_mail() is about to send with.
	 */
	public static function phpmailer_init( $mailer ): void {
		if ( ! ( $mailer instanceof Mailer ) ) {
			return;
		}

		$mailer->Mailer = 'ses'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.

		$reply_to = self::constant( 'SHAKEWELL_SES_MAILER_REPLY_TO' );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$mailer->clearReplyTos();
			$mailer->addReplyTo( $reply_to );
		}
	}

	/**
	 * Replaces only the WordPress default sender (wordpress@host); an explicit From is kept.
	 *
	 * @param string $from Sender address.
	 */
	public static function from( $from ) {
		$host       = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		$default    = 'wordpress@' . ( str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host );
		$configured = self::constant( 'SHAKEWELL_SES_MAILER_FROM' );

		return ( $from === $default && '' !== $configured && is_email( $configured ) ) ? $configured : $from;
	}

	/**
	 * Replaces the default sender name "WordPress".
	 *
	 * @param string $name Sender name.
	 */
	public static function from_name( $name ) {
		$configured = self::constant( 'SHAKEWELL_SES_MAILER_FROM_NAME' );

		return ( 'WordPress' === $name && '' !== $configured ) ? $configured : $name;
	}
}
