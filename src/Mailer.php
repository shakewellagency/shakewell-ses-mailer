<?php
/**
 * PHPMailer transport. Loaded on the first send, once core's PHPMailer classes exist.
 *
 * @package Shakewell\SesMailer
 */

namespace Shakewell\SesMailer;

defined( 'ABSPATH' ) || exit;

/**
 * PHPMailer calls "<Mailer>Send" for an unknown Mailer name, so Mailer = 'ses' lands in sesSend().
 * Mailer 'ses' is neither mail nor sendmail, so PHPMailer leaves the Bcc header out of the message;
 * Bcc recipients travel in the SES Destination only.
 */
final class Mailer extends \WP_PHPMailer {

	/**
	 * Sends the finished MIME message through SES.
	 *
	 * @param string $header MIME headers.
	 * @param string $body   MIME body.
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception When SES or the credentials fail, so wp_mail() fires wp_mail_failed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPMailer dispatches to "<Mailer>Send".
	 */
	protected function sesSend( #[\SensitiveParameter] $header, #[\SensitiveParameter] $body ) {
		// phpcs:enable
		$addresses = static function ( array $entries ): array {
			return array_values( array_map( static fn( $a ) => $a[0], $entries ) );
		};

		$request = array(
			'Destination' => array_filter(
				array(
					'ToAddresses'  => $addresses( $this->getToAddresses() ),
					'CcAddresses'  => $addresses( $this->getCcAddresses() ),
					'BccAddresses' => $addresses( $this->getBccAddresses() ),
				)
			),
			'Content'     => array( 'Raw' => array( 'Data' => base64_encode( $this->getSentMIMEMessage() ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SES raw content is base64 by definition.
		);

		$set = Plugin::configuration_set();
		if ( '' !== $set ) {
			$request['ConfigurationSetName'] = $set;
		}

		try {
			Aws::post( $request );
		} catch ( \Throwable $e ) {
			// One safe log line, then the failure goes back the usual way: postSend() only treats
			// PHPMailer's own exception as a send failure, and wp_mail() turns that into wp_mail_failed.
			Aws::report_failure( $e );
			throw new \PHPMailer\PHPMailer\Exception( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; wp_mail() hands it to wp_mail_failed.
		}

		return true;
	}
}
