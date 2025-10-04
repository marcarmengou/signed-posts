<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Read admin preferences
$delete_post_sigs = get_option( 'wppgps_delete_post_signatures', '0' ) === '1';
$delete_user_urls = get_option( 'wppgps_delete_user_key_urls', '0' ) === '1';

// Remove all post signatures if requested
if ( $delete_post_sigs && function_exists( 'delete_post_meta_by_key' ) ) {
    delete_post_meta_by_key( '_wppgps_signature' );
}

// Remove all stored public key URLs from user meta if requested
if ( $delete_user_urls ) {
    delete_metadata( 'user', 0, 'pgp_key_url', '', true );
}

// Clean our own options
delete_option( 'wppgps_delete_post_signatures' );
delete_option( 'wppgps_delete_user_key_urls' );
