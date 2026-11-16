<?php
/**
 * Privacy policy content, exporter and eraser for Email Log & Notifications.
 *
 * @package Email_Log_Notifications
 */

defined( 'ABSPATH' ) || exit;

final class ELN_Privacy {

	/**
	 * Register privacy hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'Email Log & Notifications',
			__( 'This plugin stores inquiry data submitted through the site contact form: name, email, message, form fields, browser language, timezone, IP address, source page, and any uploaded attachments. Records are visible only to authorized administrators and are used to send inquiry notifications and delivery confirmations. Use the WordPress privacy tools to export or erase records by email address; deleting an inquiry also removes its attachments.', 'email-log-notifications' )
		);
	}

	/**
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['email-log-notifications'] = array(
			'exporter_friendly_name' => __( 'Email Log & Notifications', 'email-log-notifications' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['email-log-notifications'] = array(
			'eraser_friendly_name' => __( 'Email Log & Notifications', 'email-log-notifications' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export inquiry records for an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page index.
	 * @return array
	 */
	public static function export( $email_address, $page = 1 ) {
		$posts = self::query( $email_address );
		$data  = array();

		foreach ( $posts as $post ) {
			$fields = array();
			foreach ( self::meta_keys() as $key => $label ) {
				$value = get_post_meta( $post->ID, $key, true );
				if ( '' !== $value && false !== $value ) {
					$fields[] = array(
						'name'  => $label,
						'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
					);
				}
			}

			$data[] = array(
				'group_id'    => 'eln_inquiry',
				'group_label' => __( 'Inquiries', 'email-log-notifications' ),
				'item_id'     => (string) $post->ID,
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase inquiry records for an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page index.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) {
		$posts          = self::query( $email_address );
		$items_removed  = 0;
		$items_retained = 0;

		foreach ( $posts as $post ) {
			if ( wp_delete_post( $post->ID, true ) ) {
				++$items_removed;
			} else {
				++$items_retained;
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => array(),
			'done'           => true,
		);
	}

	private static function meta_keys() {
		return array(
			Email_Log_Notifications::META_PREFIX . 'email' => __( 'Email', 'email-log-notifications' ),
			Email_Log_Notifications::META_PREFIX . 'fields' => __( 'Form fields', 'email-log-notifications' ),
			Email_Log_Notifications::META_PREFIX . 'ip'    => __( 'IP address', 'email-log-notifications' ),
			Email_Log_Notifications::META_PREFIX . 'lang'  => __( 'Browser language', 'email-log-notifications' ),
			Email_Log_Notifications::META_PREFIX . 'timezone' => __( 'Timezone', 'email-log-notifications' ),
			Email_Log_Notifications::META_PREFIX . 'page_url' => __( 'Source page', 'email-log-notifications' ),
			Email_Log_Notifications::META_PREFIX . 'attachments' => __( 'Attachments', 'email-log-notifications' ),
		);
	}

	private static function query( $email_address ) {
		return get_posts(
			array(
				'post_type'      => Email_Log_Notifications::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => Email_Log_Notifications::META_PREFIX . 'email',
				'meta_value'     => sanitize_email( $email_address ),
			)
		);
	}
}
