<?php
/**
 * Plugin Name:       Shakewell Mailer for Amazon SES
 * Plugin URI:        https://github.com/shakewellagency/shakewell-ses-mailer
 * Description:       Sends wp_mail() through Amazon SES with the AWS credentials the server already has. No key is ever stored in WordPress. Off until SHAKEWELL_SES_MAILER_ENABLED is true.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-ses-mailer
 *
 * @package Shakewell\SesMailer
 */

namespace Shakewell\SesMailer;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/SesError.php';
require_once __DIR__ . '/src/Aws.php';
require_once __DIR__ . '/src/Credentials.php';
require_once __DIR__ . '/src/Plugin.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
