<?php
/*
Plugin Name: Signed Posts
Plugin URI: https://wordpress.org/plugins/signed-posts/
Description: Signed Posts allows authors to sign posts, assuring content integrity. Signature verification proves post-signing alteration hasn't occurred.
Version: 0.3
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

// -----------------------------------------------------------
// 1. BACKEND: Field for Public Key in Author Profile
// -----------------------------------------------------------

/**
 * Adds the OpenPGP key URL field to the user profile.
 */
function wppgps_add_user_profile_fields( $user ) {
    $pgp_key_url = get_user_meta( $user->ID, 'pgp_key_url', true );

    // Only the profile owner can edit its URL. Others will see it in read-only.
    $readonly_attr = ( (int) $user->ID === get_current_user_id() ) ? '' : 'readonly';
    ?>
    <h3><?php esc_html_e( 'OpenPGP Signature', 'signed-posts' ); ?></h3>
    <table class="form-table">
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
 * Saves the user profile data.
 * Only the user can edit their own key URL.
 */
function wppgps_save_user_profile_fields( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    // Verify the profile form nonce exists.
    if ( ! isset( $_POST['_wpnonce'] ) ) {
        return;
    }

    // Sanitize + unslash before verifying (WP guideline).
    $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'update-user_' . $user_id ) ) {
        return;
    }

    // Only the profile owner can change its OpenPGP key URL (no editors or administrators on other profiles).
    if ( (int) $user_id !== get_current_user_id() ) {
        return;
    }

    // Save the URL only if valid.
    if ( isset( $_POST['pgp_key_url'] ) ) {
        $url = esc_url_raw( wp_unslash( $_POST['pgp_key_url'] ) );
        if ( $url && wp_http_validate_url( $url ) ) {
            update_user_meta( $user_id, 'pgp_key_url', $url );
        }
    }
}
add_action( 'personal_options_update', 'wppgps_save_user_profile_fields' );
add_action( 'edit_user_profile_update', 'wppgps_save_user_profile_fields' );

// -----------------------------------------------------------
// 2. BACKEND: Field for Signature in the Article (Metabox)
// -----------------------------------------------------------

/**
 * Adds a custom metabox to the article editor for the signature.
 */
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

/**
 * Metabox content.
 */
function wppgps_metabox_callback( $post ) {
    wp_nonce_field( 'wppgps_save_data', 'wppgps_nonce' );

    $pgp_signature   = get_post_meta( $post->ID, '_wppgps_signature', true );
    $post_author_id  = (int) $post->post_author;
    $current_user_id = get_current_user_id();
    $is_author       = ( $post_author_id === $current_user_id );
    $key_url         = get_user_meta( $post_author_id, 'pgp_key_url', true );

    // Check if the author has configured the key.
    if ( empty( $key_url ) ) {
        echo '<p style="color: red; font-weight: bold;">⚠️ ' . esc_html__( 'ERROR: The post author has NOT configured their OpenPGP Public Key URL.', 'signed-posts' ) . '</p>';
        echo '<p>';
        echo esc_html__( 'Please, edit your ', 'signed-posts' );
        echo '<a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'User Profile', 'signed-posts' ) . '</a>';
        echo esc_html__( ' to add your key URL before signing.', 'signed-posts' );
        echo '</p>';
        return;
    }

    // The signature field is read-only if you are not the author.
    $readonly = $is_author ? '' : 'readonly';
    ?>
    <p>
        <strong><?php esc_html_e( 'Public Key Source for Verification:', 'signed-posts' ); ?></strong>
        <code><?php echo esc_html( $key_url ); ?></code>
    </p>

    <label for="wppgps_signature"><?php esc_html_e( 'OpenPGP Signature of the Content (ASCII Armor):', 'signed-posts' ); ?></label>
    <textarea id="wppgps_signature" name="wppgps_signature" rows="8" style="width:100%;" placeholder="-----BEGIN PGP SIGNATURE-----..." <?php echo esc_attr( $readonly ); ?>><?php echo esc_textarea( $pgp_signature ); ?></textarea>

    <p style="font-size: 0.9em; color: #555;">
        *<?php esc_html_e( 'Note: This signature can only be saved or modified by the original author of the post. This prevents signature manipulation by Editors or Administrators.', 'signed-posts' ); ?>*
    </p>
    <?php
}

/**
 * Saves the signature data from the metabox.
 * *** CRITICAL: Only the post author can save the signature. ***
 */
function wppgps_save_post_data( $post_id ) {
    // The 'isset' check is separated from the 'wp_verify_nonce' check for clarity.
    if ( ! isset( $_POST['wppgps_nonce'] ) ) {
        return $post_id;
    }

    // Sanitize + unslash before verifying (WP guideline).
    $nonce = sanitize_text_field( wp_unslash( $_POST['wppgps_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'wppgps_save_data' ) ) {
        return $post_id;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return $post_id;
    }

    // Get the post author ID.
    $post_author_id = (int) get_post_field( 'post_author', $post_id );

    // If the current user is not the post author, we ignore the OpenPGP signature fields.
    if ( get_current_user_id() !== $post_author_id ) {
        return $post_id;
    }

    // Save the signature field.
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
// 3. FRONTEND: Verification, Signature block & Author badge
// -----------------------------------------------------------

/**
 * Enqueues the OpenPGP.js script, the verification script, CSS and Dashicons.
 */
function wppgps_enqueue_scripts() {
    if ( is_singular( 'post' ) ) {
        global $post;

        $pgp_signature   = get_post_meta( $post->ID, '_wppgps_signature', true );
        $post_author_id  = get_post_field( 'post_author', $post->ID );
        $pgp_key_url     = get_user_meta( $post_author_id, 'pgp_key_url', true );

        // Only if there is a signature AND a key, we enqueue the script and data.
        if ( ! empty( $pgp_signature ) && ! empty( $pgp_key_url ) ) {

            // Styles for signature block and author badge
            wp_enqueue_style(
                'wppgps-styles',
                plugin_dir_url( __FILE__ ) . 'signed-posts.css',
                array(),
                '0.3',
                'all'
            );

            // Dashicons for the shield icon
            wp_enqueue_style( 'dashicons' );

            // OpenPGP.js (minified third-party lib)
            wp_enqueue_script(
                'openpgp-js',
                plugin_dir_url( __FILE__ ) . 'openpgp.min.js',
                array(),
                '6.2.2',
                true
            );

            // Plugin verifier JS
            wp_enqueue_script(
                'wppgps-verifier',
                plugin_dir_url( __FILE__ ) . 'signed-posts.js',
                array( 'openpgp-js' ),
                '0.3',
                true
            );

            // Build canonical message (match GPG behavior)
            $signed_message = get_post_field( 'post_content', $post->ID );
            $signed_message = str_replace( "\r\n", "\n", $signed_message );
            $signed_message = trim( $signed_message );
            if ( ! empty( $signed_message ) ) {
                $signed_message .= "\n";
            }

            // Pass data to JavaScript (also expose postId for author badge)
            wp_localize_script(
                'wppgps-verifier',
                'wppgpsData',
                array(
                    'postId'       => (int) $post->ID,
                    'message'      => $signed_message,
                    'signature'    => $pgp_signature,
                    'publicKeyUrl' => $pgp_key_url,
                    'badgeToken'   => '[[WPPGPS_BADGE:' . (int) $post->ID . ']]',
                )
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'wppgps_enqueue_scripts' );

/**
 * Appends the verification block to the end of the article content.
 */
function wppgps_append_signature_block( $content ) {
    if ( is_singular( 'post' ) && in_the_loop() && is_main_query() ) {
        global $post;

        $pgp_signature  = get_post_meta( $post->ID, '_wppgps_signature', true );
        $post_author_id = get_post_field( 'post_author', $post->ID );
        $pgp_key_url    = get_user_meta( $post_author_id, 'pgp_key_url', true );

        // Only show the block if there is a signature AND a key URL configured.
        if ( ! empty( $pgp_signature ) && ! empty( $pgp_key_url ) ) {

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
                                <span class="pgp-detail-value">' . esc_html__( 'Verified in your browser with OpenPGP.js', 'signed-posts' ) . '</span>
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

/**
 * Returns true if we should append the badge on this request.
 */
function wppgps_should_append_badge() {
    if ( ! is_singular( 'post' ) ) {
        return false;
    }
    global $post;
    if ( ! $post ) {
        return false;
    }
    $pgp_signature = get_post_meta( $post->ID, '_wppgps_signature', true );
    $pgp_key_url   = get_user_meta( (int) $post->post_author, 'pgp_key_url', true );
    return ( ! empty( $pgp_signature ) && ! empty( $pgp_key_url ) );
}

/**
 * Returns the author badge token. JS will replace this token with the real HTML.
 */
function wppgps_get_author_badge_token( $post_id ) {
    return '[[WPPGPS_BADGE:' . (int) $post_id . ']]';
}

/**
 * Helper: safely append badge TOKEN (not HTML) if not already present.
 */
function wppgps_append_badge_to_output( $output ) {
    if ( ! wppgps_should_append_badge() ) {
        return $output;
    }
    // Prevent duplicates if multiple filters are hit by a theme (check token presence).
    if ( false !== strpos( $output, '[[WPPGPS_BADGE:' ) ) {
        return $output;
    }
    global $post;
    return $output . wppgps_get_author_badge_token( (int) $post->ID );
}

/**
 * If the theme prints an author LINK (the_author_posts_link or get_the_author_posts_link),
 * WordPress applies the 'the_author_posts_link' filter. Append after it.
 */
function wppgps_filter_the_author_posts_link( $link ) {
    return wppgps_append_badge_to_output( $link );
}
add_filter( 'the_author_posts_link', 'wppgps_filter_the_author_posts_link', 20 );

/**
 * If the theme prints a plain author (the_author).
 */
function wppgps_filter_the_author( $display_name ) {
    return wppgps_append_badge_to_output( $display_name );
}
add_filter( 'the_author', 'wppgps_filter_the_author', 20 );

/**
 * If the theme retrieves the author as a string (get_the_author).
 */
function wppgps_filter_get_the_author( $display_name ) {
    return wppgps_append_badge_to_output( $display_name );
}
add_filter( 'get_the_author', 'wppgps_filter_get_the_author', 20 );

/**
 * If the theme uses the explicit display name getter.
 */
function wppgps_filter_get_the_author_display_name( $display_name ) {
    return wppgps_append_badge_to_output( $display_name );
}
add_filter( 'get_the_author_display_name', 'wppgps_filter_get_the_author_display_name', 20 );

// -----------------------------------------------------------
// 4. ADMIN: Uninstall cleanup preferences UI (options stored)
// -----------------------------------------------------------

// Defaults on activation
register_activation_hook( __FILE__, function() {
    if ( false === get_option( 'wppgps_delete_post_signatures', false ) ) {
        add_option( 'wppgps_delete_post_signatures', '0' );
    }
    if ( false === get_option( 'wppgps_delete_user_key_urls', false ) ) {
        add_option( 'wppgps_delete_user_key_urls', '0' );
    }
});

// Render checkboxes under the PGP URL area
function wppgps_render_cleanup_checkboxes( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $del_sigs = get_option( 'wppgps_delete_post_signatures', '0' ) === '1';
    $del_urls = get_option( 'wppgps_delete_user_key_urls', '0' ) === '1';
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
                        <label for="wppgps_delete_post_signatures" style="margin-left: 12px;">
                        <input type="checkbox" name="wppgps_delete_post_signatures" id="wppgps_delete_post_signatures" value="1" <?php checked( $del_sigs ); ?> />
                        <?php esc_html_e( 'Delete all signatures stored in post meta. You will need to re-sign and post the signature on all posts, if you check this box.', 'signed-posts' ); ?>
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

// Save checkboxes
function wppgps_save_cleanup_checkboxes( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Nonce verification to satisfy Plugin Check
    $nonce = isset( $_POST['wppgps_cleanup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wppgps_cleanup_nonce'] ) ) : '';
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wppgps_cleanup_save' ) ) {
        return;
    }

    update_option( 'wppgps_delete_post_signatures', isset( $_POST['wppgps_delete_post_signatures'] ) ? '1' : '0' );
    update_option( 'wppgps_delete_user_key_urls', isset( $_POST['wppgps_delete_user_key_urls'] ) ? '1' : '0' );
}

add_action( 'personal_options_update', 'wppgps_save_cleanup_checkboxes' );
add_action( 'edit_user_profile_update', 'wppgps_save_cleanup_checkboxes' );
