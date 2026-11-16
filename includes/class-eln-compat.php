<?php
/**
 * One-version compatibility layer for Email Log & Notifications 2.0.x.
 *
 * Remove this entire file in 2.1.0.
 *
 * @package Email_Log_Notifications
 */

defined( 'ABSPATH' ) || exit;

final class ELN_Compat {

	/**
	 * Keep legacy admin actions and AJAX actions working for one release.
	 */
	public static function register_legacy_hooks() {
		$legacy = array(
			'admin_post_inquiry_delete'         => 'handle_delete',
			'admin_post_inquiry_test_mail'      => 'handle_test_mail',
			'admin_post_inquiry_reset_template' => 'handle_reset_template',
			'admin_post_inquiry_reply_addlang'  => 'handle_add_reply_lang',
			'admin_post_inquiry_reply_dellang'  => 'handle_delete_reply_lang',
			'admin_post_inquiry_reply_reset'    => 'handle_reply_reset',
			'wp_ajax_inquiry_preview'           => 'ajax_preview',
		);

		foreach ( $legacy as $hook => $method ) {
			if ( is_callable( array( 'Email_Log_Notifications', $method ) ) ) {
				add_action( $hook, array( 'Email_Log_Notifications', $method ) );
			}
		}
	}

	/**
	 * Read a meta value with legacy key fallback.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Suffix without the plugin prefix.
	 * @return mixed
	 */
	public static function meta( $post_id, $key ) {
		$value = get_post_meta( $post_id, Email_Log_Notifications::META_PREFIX . $key, true );
		if ( '' !== $value && false !== $value ) {
			return $value;
		}
		return get_post_meta( $post_id, Email_Log_Notifications::LEGACY_META_PREFIX . $key, true );
	}
}
