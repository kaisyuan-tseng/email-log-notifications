<?php
/**
 * Versioned data migration for Email Log & Notifications 2.0.0.
 *
 * @package Email_Log_Notifications
 */

defined( 'ABSPATH' ) || exit;

final class ELN_Migration {

	/**
	 * Meta key map: legacy => current.
	 *
	 * @return array<string, string>
	 */
	private static function meta_map() {
		$keys = array(
			'email',
			'variant',
			'locale',
			'page_url',
			'page_title',
			'page_title_en',
			'ip',
			'lang',
			'timezone',
			'no',
			'fields',
			'field_labels',
			'attachments',
			'mail_sent',
			'mail_error',
			'autoreply_sent',
			'autoreply_error',
			'name',
		);

		$map = array();
		foreach ( $keys as $key ) {
			$map[ Email_Log_Notifications::LEGACY_META_PREFIX . $key ] = Email_Log_Notifications::META_PREFIX . $key;
		}
		return $map;
	}

	/**
	 * Run migration once in the admin.
	 */
	public static function maybe_migrate() {
		self::init_capabilities();

		if ( ! is_admin() ) {
			return;
		}

		if ( get_option( Email_Log_Notifications::OPT_DB_VERSION ) === Email_Log_Notifications::DB_VERSION ) {
			return;
		}

		global $wpdb;

		$steps = array(
			'post_type'   => array( __CLASS__, 'migrate_post_type' ),
			'meta_keys'   => array( __CLASS__, 'migrate_meta_keys' ),
			'options'     => array( __CLASS__, 'migrate_options' ),
			'attachments' => array( __CLASS__, 'migrate_attachments' ),
			'content'     => array( __CLASS__, 'migrate_post_content' ),
		);

		foreach ( $steps as $name => $callback ) {
			if ( ! call_user_func( $callback ) ) {
				update_option(
					'eln_migration_error',
					sprintf( 'Migration step %s failed: %s', $name, (string) $wpdb->last_error ),
					false
				);
				return;
			}
		}

		delete_option( 'eln_migration_error' );
		update_option( Email_Log_Notifications::OPT_DB_VERSION, Email_Log_Notifications::DB_VERSION, false );
	}

	/**
	 * Ensure capabilities exist on every admin request.
	 */
	public static function init_capabilities() {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			$role->add_cap( Email_Log_Notifications::CAP_VIEW );
			$role->add_cap( Email_Log_Notifications::CAP_EDIT );
			$role->add_cap( Email_Log_Notifications::CAP_DELETE );
		}
	}

	/**
	 * Map plugin-specific meta capabilities.
	 *
	 * @param string[] $caps    Primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Capability arguments.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		$map = array(
			'eln_view_inquiry'   => Email_Log_Notifications::CAP_VIEW,
			'eln_edit_inquiry'   => Email_Log_Notifications::CAP_EDIT,
			'eln_delete_inquiry' => Email_Log_Notifications::CAP_DELETE,
		);

		if ( ! isset( $map[ $cap ] ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || Email_Log_Notifications::POST_TYPE !== $post->post_type ) {
			return array( 'do_not_allow' );
		}

		return array( $map[ $cap ] );
	}

	/**
	 * Show a migration failure notice.
	 */
	public static function admin_notice() {
		$error = get_option( 'eln_migration_error', '' );
		if ( '' === $error ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf( 'Email Log & Notifications migration error: %s', $error ) )
		);
	}

	private static function migrate_post_type() {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_type' => Email_Log_Notifications::POST_TYPE ),
			array( 'post_type' => Email_Log_Notifications::LEGACY_POST_TYPE ),
			array( '%s' ),
			array( '%s' )
		);
		return empty( $wpdb->last_error );
	}

	private static function migrate_meta_keys() {
		global $wpdb;
		foreach ( self::meta_map() as $old_key => $new_key ) {
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_key' => $new_key ),
				array( 'meta_key' => $old_key ),
				array( '%s' ),
				array( '%s' )
			);
			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}
		}
		return true;
	}

	private static function migrate_options() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s",
				Email_Log_Notifications::LEGACY_SEQ_PREFIX,
				Email_Log_Notifications::SEQ_PREFIX,
				$wpdb->esc_like( Email_Log_Notifications::LEGACY_SEQ_PREFIX ) . '%'
			)
		);
		if ( ! empty( $wpdb->last_error ) ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . Email_Log_Notifications::LEGACY_RATE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . Email_Log_Notifications::LEGACY_RATE_PREFIX ) . '%'
			)
		);

		return empty( $wpdb->last_error );
	}

	private static function migrate_attachments() {
		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		$old_dir = $base . '/' . Email_Log_Notifications::LEGACY_STORAGE_DIR;
		$new_dir = $base . '/' . Email_Log_Notifications::STORAGE_DIR;

		if ( ! is_dir( $old_dir ) ) {
			wp_mkdir_p( $new_dir );
			self::write_index_php( $new_dir );
			self::write_index_php( $old_dir );
			return true;
		}

		wp_mkdir_p( $new_dir );
		self::write_index_php( $new_dir );

		$entries = scandir( $old_dir );
		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
				continue;
			}
			$source = $old_dir . '/' . $entry;
			$target = $new_dir . '/' . $entry;
			if ( is_dir( $source ) && ! is_dir( $target ) ) {
				if ( ! @rename( $source, $target ) ) {
					return false;
				}
			}
		}

		self::write_index_php( $old_dir );
		self::update_attachment_meta_paths( $old_dir, $new_dir );
		return true;
	}

	/**
	 * Replace old upload paths in attachment metadata.
	 *
	 * @param string $old_dir Old absolute directory.
	 * @param string $new_dir New absolute directory.
	 */
	private static function update_attachment_meta_paths( $old_dir, $new_dir ) {
		$meta_key = Email_Log_Notifications::META_PREFIX . 'attachments';
		$post_ids = get_posts(
			array(
				'post_type'      => Email_Log_Notifications::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$files = get_post_meta( $post_id, $meta_key, true );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as &$file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}
				foreach ( array( 'path', 'web_path', 'url' ) as $field ) {
					if ( isset( $file[ $field ] ) && is_string( $file[ $field ] ) ) {
						$file[ $field ] = str_replace( $old_dir, $new_dir, $file[ $field ] );
						$file[ $field ] = str_replace( '/inquiries/', '/eln-inquiries/', $file[ $field ] );
					}
				}
			}
			unset( $file );
			update_post_meta( $post_id, $meta_key, $files );
		}
	}

	private static function migrate_post_content() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_type = %s",
				'/inquiries/',
				'/eln-inquiries/',
				Email_Log_Notifications::POST_TYPE
			)
		);
		return empty( $wpdb->last_error );
	}

	private static function write_index_php( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$file = $dir . '/index.php';
		if ( ! file_exists( $file ) ) {
			@file_put_contents( $file, "<?php\n// Silence is golden.\n" );
		}
	}
}
