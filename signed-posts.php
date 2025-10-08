<?php
/*
Plugin Name: Signed Posts
Plugin URI: https://wordpress.org/plugins/signed-posts/
Description: Signed Posts allows authors to sign posts, assuring content integrity. Signature verification proves post-signing alteration hasn't occurred.
Version: 0.4
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.0
Tested up to PHP: 8.3
Author: Marc Armengou
Author URI: https://www.marcarmengou.com/
Text Domain: signed-posts
License: GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Utilities
 */
function wppgps_normalize_post_content_for_signing( $post_id ) {
    $signed_message = get_post_field( 'post_content', $post_id );
    $signed_message = str_replace( "\r\n", "\n", $signed_message );
    $signed_message = trim( $signed_message );
    if ( ! empty( $signed_message ) ) {
        $signed_message .= "\n";
    }
    return $signed_message;
}

/**
 * Default options on activation (cleanup preferences).
 */
register_activation_hook( __FILE__, function() {
    if ( false === get_option( 'wppgps_delete_post_signatures', false ) ) {
        add_option( 'wppgps_delete_post_signatures', '0' );
    }
    if ( false === get_option( 'wppgps_delete_user_key_urls', false ) ) {
        add_option( 'wppgps_delete_user_key_urls', '0' );
    }
    if ( false === get_option( 'wppgps_delete_user_did_identifiers', false ) ) {
        add_option( 'wppgps_delete_user_did_identifiers', '0' );
    }
} );

// -----------------------------------------------------------
// 1. BACKEND: Author profile fields (OpenPGP URL + DID)
// -----------------------------------------------------------

/**
 * Adds the OpenPGP key URL field to the user profile.
 * (Existing behavior kept intact)
 */
function wppgps_add_user_profile_fields( $user ) {
    $pgp_key_url = get_user_meta( $user->ID, 'pgp_key_url', true );

    // Only the profile owner can edit its URL. Others will see it read-only.
    $readonly_attr = ( (int) $user->ID === get_current_user_id() ) ? '' : 'readonly';
    ?>
    <h2><?php esc_html_e( 'Signed Posts Settings', 'signed-posts' ); ?></h2>
    <h3><?php esc_html_e( 'OpenPGP', 'signed-posts' ); ?></h3>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="pgp_key_url"><?php esc_html_e( 'URL of your OpenPGP Public Key', 'signed-posts' ); ?></label></th>
            <td>
                <input type="url" name="pgp_key_url" id="pgp_key_url" value="<?php echo esc_attr( $pgp_key_url ); ?>" class="regular-text" <?php echo esc_attr( $readonly_attr ); ?> />
                <p class="description">
                    <?php esc_html_e( 'Full URL to your OpenPGP public key. Must be hosted externally with CORS enabled. This URL is the trusted source for verification.', 'signed-posts' ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'wppgps_add_user_profile_fields' );
add_action( 'edit_user_profile', 'wppgps_add_user_profile_fields' );

/**
 * Adds the DID field to the user profile.
 * Accepts did:key:... or did:web:example.com (optionally with path fragments).
 */
function wppgps_add_user_profile_fields_did( $user ) {
    $did = get_user_meta( $user->ID, 'did_identifier', true );
    $readonly_attr = ( (int) $user->ID === get_current_user_id() || current_user_can('manage_options') ) ? '' : 'readonly';
    ?>
    <h3><?php esc_html_e( 'Decentralized Identifiers (DID)', 'signed-posts' ); ?></h3>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="did_identifier"><?php esc_html_e( 'Your DID (did:key or did:web)', 'signed-posts' ); ?></label></th>
            <td>
                <input type="text" name="did_identifier" id="did_identifier" value="<?php echo esc_attr( $did ); ?>" class="regular-text" <?php echo esc_attr( $readonly_attr ); ?> placeholder="did:key:z6Mk... or did:web:example.com" />
                <p class="description">
                    <?php esc_html_e( 'Used for verifying detached JWS (Ed25519). For did:web, ensure a valid https://<host>/.well-known/did.json exists.', 'signed-posts' ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'wppgps_add_user_profile_fields_did', 11 );
add_action( 'edit_user_profile', 'wppgps_add_user_profile_fields_did', 11 );

/**
 * Saves profile data.
 * - Only the user can edit their own OpenPGP URL.
 * - did_identifier can be edited by the user or admins.
 */
function wppgps_save_user_profile_fields( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    if ( ! isset( $_POST['_wpnonce'] ) ) {
        return;
    }
    $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'update-user_' . $user_id ) ) {
        return;
    }

    // OpenPGP URL: only profile owner can change it.
    if ( (int) $user_id === get_current_user_id() && isset( $_POST['pgp_key_url'] ) ) {
        $url = esc_url_raw( wp_unslash( $_POST['pgp_key_url'] ) );
        if ( $url && wp_http_validate_url( $url ) ) {
            update_user_meta( $user_id, 'pgp_key_url', $url );
        }
    }

    // DID: allow owner or admins.
    if ( isset( $_POST['did_identifier'] ) && ( (int) $user_id === get_current_user_id() || current_user_can('manage_options') ) ) {
        $val = sanitize_text_field( wp_unslash( $_POST['did_identifier'] ) );
        if ( '' === $val || preg_match( '#^did:(key|web):#', $val ) ) {
            update_user_meta( $user_id, 'did_identifier', $val );
        }
    }
}
add_action( 'personal_options_update', 'wppgps_save_user_profile_fields' );
add_action( 'edit_user_profile_update', 'wppgps_save_user_profile_fields' );

// -----------------------------------------------------------
// 1.1 ADMIN: Uninstall cleanup preferences UI (options stored)
// -----------------------------------------------------------

function wppgps_render_cleanup_checkboxes( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $del_sigs = get_option( 'wppgps_delete_post_signatures', '0' ) === '1';
    $del_urls = get_option( 'wppgps_delete_user_key_urls', '0' ) === '1';
    $del_dids = get_option( 'wppgps_delete_user_did_identifiers', '0' ) === '1';
    ?>
    <h2><?php esc_html_e( 'Cleanup preferences for Signed Posts', 'signed-posts' ); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'On plugin uninstall', 'signed-posts' ); ?></th>
            <td>
                <fieldset>
                    <?php wp_nonce_field( 'wppgps_cleanup_save', 'wppgps_cleanup_nonce' ); ?>
                    <p>
                        <label for="wppgps_delete_user_key_urls">
                        <input type="checkbox" name="wppgps_delete_user_key_urls" id="wppgps_delete_user_key_urls" value="1" <?php checked( $del_urls ); ?> />
                        <?php esc_html_e( 'Delete all public key URLs stored in user meta', 'signed-posts' ); ?>
                        </label>
                    </p>
                    <p>
                        <label for="wppgps_delete_user_did_identifiers" style="margin-left: 12px;">
                        <input type="checkbox" name="wppgps_delete_user_did_identifiers" id="wppgps_delete_user_did_identifiers" value="1" <?php checked( $del_dids ); ?> />
                        <?php esc_html_e( 'Delete all DID identifiers stored in user meta', 'signed-posts' ); ?>
                        </label>
                    </p>
                    <p>
                        <label for="wppgps_delete_post_signatures" style="margin-left: 12px;">
                        <input type="checkbox" name="wppgps_delete_post_signatures" id="wppgps_delete_post_signatures" value="1" <?php checked( $del_sigs ); ?> />
                        <?php esc_html_e( 'Delete all signatures stored in post meta. You will need to re-sign and paste the signature on all posts if you check this box.', 'signed-posts' ); ?>
                        </label>
                    </p><br />
                </fieldset>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'wppgps_render_cleanup_checkboxes', 20 );
add_action( 'edit_user_profile', 'wppgps_render_cleanup_checkboxes', 20 );

function wppgps_save_cleanup_checkboxes( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $nonce = isset( $_POST['wppgps_cleanup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wppgps_cleanup_nonce'] ) ) : '';
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wppgps_cleanup_save' ) ) {
        return;
    }

    update_option( 'wppgps_delete_post_signatures', isset( $_POST['wppgps_delete_post_signatures'] ) ? '1' : '0' );
    update_option( 'wppgps_delete_user_key_urls', isset( $_POST['wppgps_delete_user_key_urls'] ) ? '1' : '0' );
    update_option( 'wppgps_delete_user_did_identifiers', isset( $_POST['wppgps_delete_user_did_identifiers'] ) ? '1' : '0' );
}
add_action( 'personal_options_update', 'wppgps_save_cleanup_checkboxes' );
add_action( 'edit_user_profile_update', 'wppgps_save_cleanup_checkboxes' );

// -----------------------------------------------------------
// 2. BACKEND: Metabox (signature + method selector)
// -----------------------------------------------------------

function wppgps_add_metabox() {
    add_meta_box(
        'wppgps_signature_box',
        esc_html__( 'Signed Posts', 'signed-posts' ),
        'wppgps_metabox_callback',
        'post',
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'wppgps_add_metabox' );

function wppgps_metabox_callback( $post ) {
    wp_nonce_field( 'wppgps_save_data', 'wppgps_nonce' );

    $sig            = get_post_meta( $post->ID, '_wppgps_signature', true );
    $method         = get_post_meta( $post->ID, '_wppgps_sig_method', true );
    if ( ! in_array( $method, array( 'openpgp', 'did:key', 'did:web' ), true ) ) {
        $method = 'openpgp'; // backward-compatible default
    }

    $post_author_id  = (int) $post->post_author;
    $current_user_id = get_current_user_id();
    $is_author       = ( $post_author_id === $current_user_id );

    $pgp_key_url = get_user_meta( $post_author_id, 'pgp_key_url', true );
    $did_id      = get_user_meta( $post_author_id, 'did_identifier', true );

    // Minimal guidance depending on selected method
    ?>
    <p>
      <label for="wppgps_sig_method"><strong><?php esc_html_e( 'Signature method', 'signed-posts' ); ?></strong></label><br/>
      <select id="wppgps_sig_method" name="wppgps_sig_method">
        <option value="openpgp" <?php selected( $method, 'openpgp' ); ?>>OpenPGP</option>
        <option value="did:key" <?php selected( $method, 'did:key' ); ?>>DID (did:key)</option>
        <option value="did:web" <?php selected( $method, 'did:web' ); ?>>DID (did:web)</option>
      </select>
    </p>
    <?php

    // Error hints if the author hasn't configured the required identity
    if ( 'openpgp' === $method && empty( $pgp_key_url ) ) {
        echo '<p style="color: red; font-weight: bold;">⚠️ ' . esc_html__( 'ERROR: The post author has NOT configured their OpenPGP Public Key URL.', 'signed-posts' ) . '</p>';
        echo '<p>' . esc_html__( 'Please edit your', 'signed-posts' ) . ' <a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'User Profile', 'signed-posts' ) . '</a> ' . esc_html__( 'to add your key URL before signing.', 'signed-posts' ) . '</p>';
        // do not return; allow pasting signature for later
    }
    if ( ( 'did:key' === $method || 'did:web' === $method ) && empty( $did_id ) ) {
        echo '<p style="color: #d98300; font-weight: bold;">⚠️ ' . esc_html__( 'WARNING: The post author has NOT configured a DID in their profile.', 'signed-posts' ) . '</p>';
    }

    $readonly = $is_author ? '' : 'readonly';
    ?>
    <p>
        <strong><?php esc_html_e( 'Verification source:', 'signed-posts' ); ?></strong>
        <code>
            <?php
            if ( 'openpgp' === $method ) {
                echo esc_html( $pgp_key_url ?: '—' );
            } else {
                echo esc_html( $did_id ?: '—' );
            }
            ?>
        </code>
    </p>

    <label for="wppgps_signature"><?php esc_html_e( 'Content signature (OpenPGP ASCII armor OR JWS compact detached, depending on method):', 'signed-posts' ); ?></label>
    <textarea id="wppgps_signature" name="wppgps_signature" rows="8" style="width:100%;" placeholder="<?php echo ( 'openpgp' === $method ) ? esc_attr__( '-----BEGIN PGP SIGNATURE-----...', 'signed-posts' ) : esc_attr__( 'eyJhbGciOiJFZERTQSIsImI2NCI6ZmFsc2UsImtpZCI6ImRpZDp...<detached JWS>', 'signed-posts' ); ?>" <?php echo esc_attr( $readonly ); ?>><?php echo esc_textarea( $sig ); ?></textarea>

    <p style="font-size: 0.9em; color: #555;">
        *<?php esc_html_e( 'Only the original author can save or modify the signature. This prevents manipulation by Editors or Administrators.', 'signed-posts' ); ?>*
    </p>
    <?php
}

function wppgps_save_post_data( $post_id ) {
    if ( ! isset( $_POST['wppgps_nonce'] ) ) {
        return $post_id;
    }
    $nonce = sanitize_text_field( wp_unslash( $_POST['wppgps_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'wppgps_save_data' ) ) {
        return $post_id;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return $post_id;
    }

    $post_author_id = (int) get_post_field( 'post_author', $post_id );
    if ( get_current_user_id() !== $post_author_id ) {
        return $post_id;
    }

    // Method
    if ( isset( $_POST['wppgps_sig_method'] ) ) {
        $method = sanitize_text_field( wp_unslash( $_POST['wppgps_sig_method'] ) );
        if ( in_array( $method, array( 'openpgp', 'did:key', 'did:web' ), true ) ) {
            update_post_meta( $post_id, '_wppgps_sig_method', $method );
        }
    }

    // Signature
    if ( isset( $_POST['wppgps_signature'] ) ) {
        update_post_meta(
            $post_id,
            '_wppgps_signature',
            sanitize_textarea_field( wp_unslash( $_POST['wppgps_signature'] ) )
        );
    }
}
add_action( 'save_post', 'wppgps_save_post_data' );

// -----------------------------------------------------------
// 3. FRONTEND: Enqueue, Signature block & Author badge
// -----------------------------------------------------------

function wppgps_enqueue_scripts() {
    if ( ! is_singular( 'post' ) ) {
        return;
    }

    global $post;

    $signature     = get_post_meta( $post->ID, '_wppgps_signature', true );
    $method        = get_post_meta( $post->ID, '_wppgps_sig_method', true );
    if ( ! in_array( $method, array( 'openpgp', 'did:key', 'did:web' ), true ) ) {
        $method = 'openpgp';
    }

    $author_id     = (int) get_post_field( 'post_author', $post->ID );
    $pgp_key_url   = get_user_meta( $author_id, 'pgp_key_url', true );
    $did_identifier= get_user_meta( $author_id, 'did_identifier', true );

    // Common CSS and Dashicons if we have signature and required identity
    $has_identity = ( 'openpgp' === $method )
        ? ( ! empty( $pgp_key_url ) )
        : ( ! empty( $did_identifier ) );

    if ( ! empty( $signature ) && $has_identity ) {
        wp_enqueue_style(
            'wppgps-styles',
            plugin_dir_url( __FILE__ ) . 'signed-posts.css',
            array(),
            '0.4',
            'all'
        );
        wp_enqueue_style( 'dashicons' );

        // Prepare canonical message
        $message = wppgps_normalize_post_content_for_signing( $post->ID );

        if ( 'openpgp' === $method ) {
            // OpenPGP path (unchanged)
            wp_enqueue_script(
                'openpgp-js',
                plugin_dir_url( __FILE__ ) . 'openpgp.min.js',
                array(),
                '6.2.2',
                true
            );
            wp_enqueue_script(
                'wppgps-verifier',
                plugin_dir_url( __FILE__ ) . 'signed-posts.js',
                array( 'openpgp-js' ),
                '0.4',
                true
            );
            wp_localize_script(
                'wppgps-verifier',
                'wppgpsData',
                array(
                    'postId'       => (int) $post->ID,
                    'method'       => 'openpgp',
                    'message'      => $message,
                    'signature'    => $signature,
                    'publicKeyUrl' => $pgp_key_url,
                    'badgeToken'   => '[[WPPGPS_BADGE:' . (int) $post->ID . ']]',
                )
            );
        } else {
            // DID path
            wp_enqueue_script(
                'wppgps-did',
                plugin_dir_url( __FILE__ ) . 'signed-posts.did.js',
                array(),
                '0.4',
                true
            );
            wp_localize_script(
                'wppgps-did',
                'wppgpsData',
                array(
                    'postId'       => (int) $post->ID,
                    'method'       => $method,            // 'did:key' or 'did:web'
                    'message'      => $message,
                    'signature'    => $signature,         // JWS Compact (detached)
                    'did'          => $did_identifier,
                    'badgeToken'   => '[[WPPGPS_BADGE:' . (int) $post->ID . ']]',
                )
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'wppgps_enqueue_scripts' );

/**
 * Appends the verification block at the end of the content.
 * Shows for OpenPGP (key URL present) or DID (DID present).
 */
function wppgps_append_signature_block( $content ) {
    if ( is_singular( 'post' ) && in_the_loop() && is_main_query() ) {
        global $post;

        $signature  = get_post_meta( $post->ID, '_wppgps_signature', true );
        $method     = get_post_meta( $post->ID, '_wppgps_sig_method', true );
        if ( ! in_array( $method, array( 'openpgp', 'did:key', 'did:web' ), true ) ) {
            $method = 'openpgp';
        }

        $author_id  = (int) get_post_field( 'post_author', $post->ID );
        $pgp_key_url= get_user_meta( $author_id, 'pgp_key_url', true );
        $did_id     = get_user_meta( $author_id, 'did_identifier', true );

        $has_identity = ( 'openpgp' === $method ) ? ( ! empty( $pgp_key_url ) ) : ( ! empty( $did_id ) );

        if ( ! empty( $signature ) && $has_identity ) {
            // Keep original structure, small wording kept generic so DID JS can tweak text live.
            $signature_block = '
                <div id="pgp-signature-block" class="pgp-signature-block">
                    <div class="pgp-header">
                        <h3 class="pgp-title">' . esc_html__( 'Status of the Signature', 'signed-posts' ) . '</h3>
                        <span class="pgp-icon">🔒</span>
                    </div>

                    <div class="pgp-content">
                        <div id="pgp-verification-result" class="pgp-status-container">
                            <span class="pgp-status-text pgp-status-pending">' . esc_html__( 'Starting verification...', 'signed-posts' ) . '</span>
                        </div>

                        <div class="pgp-details">
                            <p>
                                <span class="pgp-detail-label">' . esc_html__( 'Method:', 'signed-posts' ) . '</span>
                                <span id="pgp-method-value" class="pgp-detail-value">' . ( 'openpgp' === $method ? esc_html__( 'Verified in your browser with OpenPGP.js', 'signed-posts' ) : esc_html__( 'Verified in your browser with DID (Ed25519 JWS)', 'signed-posts' ) ) . '</span>
                            </p>
                            <p>
                                <span class="pgp-detail-label">' . esc_html__( 'Source:', 'signed-posts' ) . '</span>
                                <span id="pgp-key-url-link" class="pgp-detail-value pgp-link" style="word-break: break-all;"></span>
                            </p>
                            <p class="pgp-result-message">
                                <span class="pgp-detail-label">' . esc_html__( 'Result:', 'signed-posts' ) . '</span>
                                <span id="pgp-result-details" class="pgp-detail-value"></span>
                            </p>
                        </div>
                    </div>
                </div>
            ';

            return $content . $signature_block;
        }
    }
    return $content;
}
add_filter( 'the_content', 'wppgps_append_signature_block' );

// -----------------------------------------------------------
// 3.1 AUTHOR BADGE: append after author output (never inside)
// -----------------------------------------------------------

function wppgps_should_append_badge() {
    if ( ! is_singular( 'post' ) ) {
        return false;
    }
    global $post;
    if ( ! $post ) {
        return false;
    }
    $signature = get_post_meta( $post->ID, '_wppgps_signature', true );
    if ( empty( $signature ) ) return false;

    $method  = get_post_meta( $post->ID, '_wppgps_sig_method', true );
    $method  = in_array( $method, array('openpgp','did:key','did:web'), true ) ? $method : 'openpgp';

    if ( 'openpgp' === $method ) {
        $pgp_key_url = get_user_meta( (int) $post->post_author, 'pgp_key_url', true );
        return ! empty( $pgp_key_url );
    } else {
        $did = get_user_meta( (int) $post->post_author, 'did_identifier', true );
        return ! empty( $did );
    }
}

function wppgps_get_author_badge_token( $post_id ) {
    return '[[WPPGPS_BADGE:' . (int) $post_id . ']]';
}

function wppgps_append_badge_to_output( $output ) {
    if ( ! wppgps_should_append_badge() ) {
        return $output;
    }
    if ( false !== strpos( $output, '[[WPPGPS_BADGE:' ) ) {
        return $output;
    }
    global $post;
    return $output . wppgps_get_author_badge_token( (int) $post->ID );
}

add_filter( 'the_author_posts_link', 'wppgps_append_badge_to_output', 20 );
add_filter( 'the_author', 'wppgps_append_badge_to_output', 20 );
add_filter( 'get_the_author', 'wppgps_append_badge_to_output', 20 );
add_filter( 'get_the_author_display_name', 'wppgps_append_badge_to_output', 20 );
