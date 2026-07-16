<?php
/**
 * Plugin Name: MultiMail
 * Description: Email plugin
 * Version: 0.1.0
 * Author: Jv Secate
 * Text Domain: wc-multimail
 */

defined( 'ABSPATH' ) || exit;

define( 'WCMULTIMAIL_OPTION', 'wc_multimail_settings' );

function wc_multimail_env( $key, $default = '' ) {
	$secret_file = getenv( $key . '_FILE' );
	if ( $secret_file && is_readable( $secret_file ) ) {
		return trim( (string) file_get_contents( $secret_file ) );
	}

	$docker_secret_file = '/run/secrets/' . strtolower( $key );
	if ( is_readable( $docker_secret_file ) ) {
		return trim( (string) file_get_contents( $docker_secret_file ) );
	}

	$value = getenv( $key );
	return false !== $value && '' !== $value ? $value : $default;
}

function wc_multimail_default_settings() {
	return array(
		'global_from_name'    => wc_multimail_env( 'MAIL_FROM_NAME', get_bloginfo( 'name', 'display' ) ),
		'global_from_address' => wc_multimail_env( 'MAIL_FROM_ADDRESS', get_option( 'admin_email' ) ),
		'emails'              => array(),
	);
}

function wc_multimail_get_settings() {
	$settings = get_option( WCMULTIMAIL_OPTION, array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args( $settings, wc_multimail_default_settings() );
}

function wc_multimail_sanitize_settings( $input ) {
	$sanitized = wc_multimail_default_settings();

	if ( ! is_array( $input ) ) {
		return $sanitized;
	}

	$sanitized['global_from_name']    = isset( $input['global_from_name'] ) ? sanitize_text_field( wp_unslash( $input['global_from_name'] ) ) : $sanitized['global_from_name'];
	$sanitized['global_from_address'] = isset( $input['global_from_address'] ) ? sanitize_email( wp_unslash( $input['global_from_address'] ) ) : $sanitized['global_from_address'];
	$sanitized['emails']              = array();

	if ( ! empty( $input['emails'] ) && is_array( $input['emails'] ) ) {
		foreach ( $input['emails'] as $email_id => $email_settings ) {
			$email_id = sanitize_key( $email_id );

			if ( '' === $email_id || ! is_array( $email_settings ) ) {
				continue;
			}

			$sanitized['emails'][ $email_id ] = array(
				'from_name'    => isset( $email_settings['from_name'] ) ? sanitize_text_field( wp_unslash( $email_settings['from_name'] ) ) : '',
				'from_address' => isset( $email_settings['from_address'] ) ? sanitize_email( wp_unslash( $email_settings['from_address'] ) ) : '',
			);
		}
	}

	return $sanitized;
}

function wc_multimail_get_registered_emails() {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->mailer() ) {
		return array();
	}

	$emails = WC()->mailer()->get_emails();
	if ( ! is_array( $emails ) ) {
		return array();
	}

	$registered = array();

	foreach ( $emails as $email ) {
		if ( ! is_object( $email ) || empty( $email->id ) ) {
			continue;
		}

		$title = '';
		if ( method_exists( $email, 'get_title' ) ) {
			$title = $email->get_title();
		} elseif ( isset( $email->title ) ) {
			$title = $email->title;
		}

		$registered[ $email->id ] = array(
			'id'    => $email->id,
			'class' => get_class( $email ),
			'title' => $title,
		);
	}

	return $registered;
}

function wc_multimail_get_email_override( $email_id ) {
	$settings = wc_multimail_get_settings();

	if ( empty( $settings['emails'][ $email_id ] ) || ! is_array( $settings['emails'][ $email_id ] ) ) {
		return array(
			'from_name'    => '',
			'from_address' => '',
		);
	}

	return wp_parse_args(
		$settings['emails'][ $email_id ],
		array(
			'from_name'    => '',
			'from_address' => '',
		)
	);
}

function wc_multimail_get_email_audience_label( $email_id ) {
	$email_id = sanitize_key( $email_id );

	if ( 0 === strpos( $email_id, 'customer_' ) || in_array( $email_id, array( 'customer_new_account', 'customer_note', 'customer_invoice', 'customer_reset_password', 'customer_abandoned_cart_recovery', 'customer_review_request', 'customer_verify_email', 'customer_fulfillment_deleted', 'customer_pos_completed_order', 'customer_pos_refunded_order' ), true ) ) {
		return 'Customer';
	}

	if ( in_array( $email_id, array( 'new_order', 'cancelled_order', 'failed_order', 'admin_payment_gateway_enabled', 'low_stock', 'no_stock' ), true ) ) {
		return 'Admin';
	}

	return 'Other';
}

function wc_multimail_get_global_from_name() {
	$settings = wc_multimail_get_settings();
	if ( '' !== $settings['global_from_name'] ) {
		return $settings['global_from_name'];
	}

	return wc_multimail_env( 'MAIL_FROM_NAME', get_bloginfo( 'name', 'display' ) );
}

function wc_multimail_get_global_from_address() {
	$settings = wc_multimail_get_settings();
	if ( '' !== $settings['global_from_address'] ) {
		return $settings['global_from_address'];
	}

	return wc_multimail_env( 'MAIL_FROM_ADDRESS', get_option( 'admin_email' ) );
}

function wc_multimail_get_sender_name_for_email( $email, $fallback_name ) {
	if ( is_object( $email ) && ! empty( $email->id ) ) {
		$override = wc_multimail_get_email_override( $email->id );
		if ( '' !== $override['from_name'] ) {
			return $override['from_name'];
		}
	}

	$global_from_name = wc_multimail_get_global_from_name();
	if ( '' !== $global_from_name ) {
		return $global_from_name;
	}

	return $fallback_name;
}

function wc_multimail_get_sender_address_for_email( $email, $fallback_address ) {
	if ( is_object( $email ) && ! empty( $email->id ) ) {
		$override = wc_multimail_get_email_override( $email->id );
		if ( '' !== $override['from_address'] ) {
			return $override['from_address'];
		}
	}

	$global_from_address = wc_multimail_get_global_from_address();
	if ( '' !== $global_from_address ) {
		return $global_from_address;
	}

	return $fallback_address;
}

add_action( 'phpmailer_init', function ( $phpmailer ) {
	$transport = strtolower( wc_multimail_env( 'MAIL_TRANSPORT', 'mailpit' ) );

	$phpmailer->isSMTP();

	if ( 'resend' === $transport || 'smtp' === $transport ) {
		$port   = (int) wc_multimail_env( 'SMTP_PORT', '587' );
		$secure  = wc_multimail_env( 'SMTP_SECURE', 'tls' );
		$host    = wc_multimail_env( 'SMTP_HOST', 'smtp.resend.com' );
		$user    = wc_multimail_env( 'SMTP_USERNAME', 'resend' );
		$pass    = wc_multimail_env( 'SMTP_PASSWORD', '' );

		$phpmailer->Host       = $host;
		$phpmailer->Port       = $port;
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = $user;
		$phpmailer->Password   = $pass;
		$phpmailer->SMTPSecure = 'none' === strtolower( $secure ) ? '' : $secure;
		$phpmailer->SMTPAutoTLS = '' !== $phpmailer->SMTPSecure;

		return;
	}

	$phpmailer->Host        = wc_multimail_env( 'MAILCATCHER_HOST', wc_multimail_env( 'MAILPIT_HOST', 'mailpit' ) );
	$phpmailer->Port        = (int) wc_multimail_env( 'MAILCATCHER_PORT', wc_multimail_env( 'MAILPIT_PORT', '1025' ) );
	$phpmailer->SMTPAuth    = false;
	$phpmailer->SMTPSecure  = '';
	$phpmailer->SMTPAutoTLS = false;
} );

add_filter( 'wp_mail_from', function ( $from ) {
	return wc_multimail_get_global_from_address() ?: wc_multimail_env( 'MAIL_FROM_ADDRESS', $from );
} );

add_filter( 'wp_mail_from_name', function ( $name ) {
	return wc_multimail_get_global_from_name() ?: wc_multimail_env( 'MAIL_FROM_NAME', $name );
} );

add_filter( 'woocommerce_email_from_name', function ( $from_name, $email ) {
	return wc_multimail_get_sender_name_for_email( $email, $from_name );
}, 10, 2 );

add_filter( 'woocommerce_email_from_address', function ( $from_address, $email ) {
	return wc_multimail_get_sender_address_for_email( $email, $from_address );
}, 10, 2 );

add_action( 'admin_init', function () {
	register_setting(
		'wc_multimail_settings_group',
		WCMULTIMAIL_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wc_multimail_sanitize_settings',
			'default'           => wc_multimail_default_settings(),
		)
	);
} );

add_action( 'admin_menu', function () {
	add_options_page(
		'WC MultiMail',
		'WC MultiMail',
		'manage_options',
		'wc-multimail',
		'wc_multimail_render_settings_page'
	);
} );

function wc_multimail_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = wc_multimail_get_settings();
	$emails   = wc_multimail_get_registered_emails();
	?>
	<div class="wrap">
		<h1>WC MultiMail</h1>
		<p>Set the sender name and sender email globally, or override them per WooCommerce transactional email.</p>

		<?php settings_errors(); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'wc_multimail_settings_group' ); ?>

			<h2>Global sender defaults</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wc_multimail_global_from_name">From name</label></th>
					<td><input name="<?php echo esc_attr( WCMULTIMAIL_OPTION ); ?>[global_from_name]" id="wc_multimail_global_from_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['global_from_name'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_multimail_global_from_address">From email</label></th>
					<td><input name="<?php echo esc_attr( WCMULTIMAIL_OPTION ); ?>[global_from_address]" id="wc_multimail_global_from_address" type="email" class="regular-text" value="<?php echo esc_attr( $settings['global_from_address'] ); ?>" /></td>
				</tr>
			</table>

			<h2>WooCommerce transactional email overrides</h2>
			<p>Leave a field empty to inherit the global sender defaults.</p>

			<?php if ( empty( $emails ) ) : ?>
				<p>WooCommerce is not available yet, so no transactional email list can be shown.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Email</th>
							<th>Sent to</th>
							<th>Class</th>
							<th>From name</th>
							<th>From email</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $emails as $email_id => $email ) :
							$override = wc_multimail_get_email_override( $email_id );
							$audience = wc_multimail_get_email_audience_label( $email_id );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $email_id ); ?></strong><br />
									<?php echo esc_html( $email['title'] ? $email['title'] : $email_id ); ?>
								</td>
								<td><?php echo esc_html( $audience ); ?></td>
								<td><?php echo esc_html( $email['class'] ); ?></td>
								<td>
									<input type="text" class="regular-text" name="<?php echo esc_attr( WCMULTIMAIL_OPTION . '[emails][' . $email_id . '][from_name]' ); ?>" value="<?php echo esc_attr( $override['from_name'] ); ?>" />
								</td>
								<td>
									<input type="email" class="regular-text" name="<?php echo esc_attr( WCMULTIMAIL_OPTION . '[emails][' . $email_id . '][from_address]' ); ?>" value="<?php echo esc_attr( $override['from_address'] ); ?>" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php submit_button( 'Save settings' ); ?>
		</form>
	</div>
	<?php
}
