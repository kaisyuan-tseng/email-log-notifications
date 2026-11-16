<?php
/**
 * Uninstall cleanup for Email Log & Notifications.
 *
 * @package Email_Log_Notifications
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$eln_options = array(
	'eln_template',
	'eln_autoreply',
	'eln_reply',
	'eln_numbers_backfilled',
	'eln_db_version',
	'eln_migration_error',
	'eln_migration_map',
	'inquiry_numbers_backfilled',
);

foreach ( $eln_options as $eln_option ) {
	delete_option( $eln_option );
}

$eln_like_patterns = array(
	'eln_seq_',
	'inquiry_seq_',
);

foreach ( $eln_like_patterns as $eln_prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $eln_prefix ) . '%'
		)
	);
}

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_eln_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_eln_rl_' ) . '%',
		$wpdb->esc_like( '_transient_eln_field_keys' ) . '%',
		$wpdb->esc_like( '_transient_timeout_eln_field_keys' ) . '%'
	)
);

$eln_post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s)",
		'eln_inquiry',
		'inquiry'
	)
);

foreach ( (array) $eln_post_ids as $eln_post_id ) {
	wp_delete_post( (int) $eln_post_id, true );
}

$eln_uploads = wp_upload_dir();
$eln_base    = wp_normalize_path( $eln_uploads['basedir'] );

/**
 * Recursively remove a directory only when it lives inside the uploads base.
 *
 * @param string $dir    Directory to remove.
 * @param string $basedir Uploads base directory.
 * @return void
 */
function eln_uninstall_remove_dir( $dir, $basedir ) {
	$dir = wp_normalize_path( $dir );
	if ( '' === $dir || strpos( $dir, trailingslashit( $basedir ) ) !== 0 || ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}

	@rmdir( $dir );
}

eln_uninstall_remove_dir( $eln_base . '/eln-inquiries', $eln_base );
eln_uninstall_remove_dir( $eln_base . '/inquiries', $eln_base );
eln_uninstall_remove_dir( $eln_base . '/eln-migration', $eln_base );

$eln_capabilities = array(
	'eln_view_inquiries',
	'eln_edit_inquiries',
	'eln_delete_inquiries',
);

foreach ( array( 'administrator', 'editor' ) as $eln_role_name ) {
	$eln_role = get_role( $eln_role_name );
	if ( ! $eln_role ) {
		continue;
	}
	foreach ( $eln_capabilities as $eln_capability ) {
		$eln_role->remove_cap( $eln_capability );
	}
}
