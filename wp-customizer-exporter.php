<?php
/**
 * Plugin Name:       WP Customizer Exporter
 * Plugin URI:        https://github.com/Azanumer/wp-customizer-exporter
 * Description:       Export your theme's Customizer settings (theme mods + Customizer CSS) as JSON, and import them into another site or theme with a dry-run preview.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://github.com/Azanumer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-customizer-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCE_VERSION', '1.0.0' );
define( 'WCE_PAGE', 'wp-customizer-exporter' );

/**
 * Collect the exportable Customizer data for the current theme.
 *
 * @return array
 */
function wce_collect_export() {
    $theme = wp_get_theme();
    $mods  = get_theme_mods();
    if ( ! is_array( $mods ) ) {
        $mods = array();
    }
    // Custom CSS lives in a separate option and is part of the Customizer.
    $custom_css_post_id = (int) get_theme_mod( 'custom_css_post_id', 0 );
    $custom_css         = '';
    if ( $custom_css_post_id > 0 ) {
        $post = get_post( $custom_css_post_id );
        if ( $post && 'custom_css' === $post->post_type ) {
            $custom_css = $post->post_content;
        }
    }
    return array(
        'plugin'     => 'wp-customizer-exporter',
        'version'    => WCE_VERSION,
        'exported'   => gmdate( 'c' ),
        'theme'      => $theme->get_stylesheet(),
        'theme_name' => $theme->get( 'Name' ),
        'mods'       => $mods,
        'custom_css' => $custom_css,
    );
}

/**
 * Validate an import payload and return a preview diff.
 *
 * @param array $data Decoded JSON payload.
 * @return array|WP_Error Preview array, or WP_Error on invalid input.
 */
function wce_preview_import( $data ) {
    if ( ! is_array( $data ) || ! isset( $data['mods'] ) || ! is_array( $data['mods'] ) ) {
        return new WP_Error( 'wce_invalid', __( 'Not a valid Customizer export file.', 'wp-customizer-exporter' ) );
    }
    $current = get_theme_mods();
    if ( ! is_array( $current ) ) {
        $current = array();
    }
    $changes = array();
    foreach ( $data['mods'] as $key => $value ) {
        if ( ! is_string( $key ) || '' === $key ) {
            continue;
        }
        // Theme mods are keyed by setting name; skip anything that is not a
        // plain scalar/array value we can store safely.
        if ( ! is_scalar( $value ) && ! is_array( $value ) && null !== $value ) {
            continue;
        }
        $exists = array_key_exists( $key, $current );
        $status = 'added';
        if ( $exists ) {
            $status = ( $current[ $key ] === $value ) ? 'unchanged' : 'changed';
        }
        $changes[] = array(
            'key'    => $key,
            'status' => $status,
        );
    }
    return array(
        'theme'      => isset( $data['theme'] ) ? (string) $data['theme'] : '',
        'theme_name' => isset( $data['theme_name'] ) ? (string) $data['theme_name'] : '',
        'has_css'    => ! empty( $data['custom_css'] ),
        'changes'    => $changes,
    );
}

/**
 * Apply an import payload. Assumes wce_preview_import() already validated it.
 *
 * @param array $data Decoded JSON payload.
 * @return int Number of mods written.
 */
function wce_apply_import( $data ) {
    $written = 0;
    foreach ( $data['mods'] as $key => $value ) {
        if ( ! is_string( $key ) || '' === $key ) {
            continue;
        }
        if ( ! is_scalar( $value ) && ! is_array( $value ) && null !== $value ) {
            continue;
        }
        set_theme_mod( $key, $value );
        $written++;
    }
    if ( ! empty( $data['custom_css'] ) && is_string( $data['custom_css'] ) ) {
        // Reuse the existing custom-CSS post if there is one, else create it.
        $css_post_id = (int) get_theme_mod( 'custom_css_post_id', 0 );
        $args        = array(
            'post_content' => wp_strip_all_tags( $data['custom_css'] ),
            'post_type'    => 'custom_css',
            'post_status'  => 'publish',
            'post_title'   => get_stylesheet(),
            'post_name'    => get_stylesheet(),
        );
        if ( $css_post_id > 0 && get_post( $css_post_id ) ) {
            $args['ID'] = $css_post_id;
            wp_update_post( $args );
        } else {
            $new_id = wp_insert_post( $args );
            if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
                set_theme_mod( 'custom_css_post_id', $new_id );
            }
        }
    }
    return $written;
}

/**
 * Register the Tools page.
 */
function wce_admin_menu() {
    add_management_page(
        __( 'Customizer Exporter', 'wp-customizer-exporter' ),
        __( 'Customizer Exporter', 'wp-customizer-exporter' ),
        'manage_options',
        WCE_PAGE,
        'wce_tools_page'
    );
}
add_action( 'admin_menu', 'wce_admin_menu' );

/**
 * Handle export download, import preview and import confirm.
 */
function wce_handle_actions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( ! $screen || 'tools_page_' . WCE_PAGE !== $screen->id ) {
        return;
    }
    // Export download.
    if ( isset( $_GET['wce_export'] ) && '1' === $_GET['wce_export'] && check_admin_referer( 'wce_export' ) ) {
        $data     = wce_collect_export();
        $filename = sanitize_file_name( 'customizer-' . $data['theme'] . '-' . gmdate( 'Y-m-d' ) . '.json' );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }
    // Import upload -> preview stored in a transient for the confirm step.
    if ( isset( $_POST['wce_import_preview'] ) && check_admin_referer( 'wce_import' ) ) {
        if ( empty( $_FILES['wce_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wce_file']['tmp_name'] ) ) {
            add_settings_error( 'wce', 'wce_no_file', __( 'Please choose a JSON export file to import.', 'wp-customizer-exporter' ), 'error' );
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- file read, JSON-decoded below.
        $raw  = file_get_contents( $_FILES['wce_file']['tmp_name'] );
        $data = json_decode( $raw, true );
        $preview = wce_preview_import( $data );
        if ( is_wp_error( $preview ) ) {
            add_settings_error( 'wce', 'wce_invalid', $preview->get_error_message(), 'error' );
            return;
        }
        set_transient( 'wce_import_' . get_current_user_id(), $data, 10 * MINUTE_IN_SECONDS );
        $GLOBALS['wce_preview'] = $preview;
    }
    // Confirm step applies the staged import.
    if ( isset( $_POST['wce_import_confirm'] ) && check_admin_referer( 'wce_import_confirm' ) ) {
        $data = get_transient( 'wce_import_' . get_current_user_id() );
        if ( ! is_array( $data ) ) {
            add_settings_error( 'wce', 'wce_expired', __( 'The import preview expired. Please upload the file again.', 'wp-customizer-exporter' ), 'error' );
            return;
        }
        delete_transient( 'wce_import_' . get_current_user_id() );
        $written = wce_apply_import( $data );
        add_settings_error(
            'wce',
            'wce_imported',
            sprintf(
                /* translators: %d: number of theme mods written. */
                __( 'Import complete: %d Customizer setting(s) applied.', 'wp-customizer-exporter' ),
                $written
            ),
            'updated'
        );
    }
}
add_action( 'current_screen', 'wce_handle_actions' );

/**
 * Render the Tools page.
 */
function wce_tools_page() {
    $current_theme = wp_get_theme();
    $preview       = isset( $GLOBALS['wce_preview'] ) ? $GLOBALS['wce_preview'] : null;
    settings_errors( 'wce' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Customizer Exporter', 'wp-customizer-exporter' ); ?></h1>
        <p><?php esc_html_e( 'Move your theme\'s Customizer settings between sites or keep a backup before switching themes.', 'wp-customizer-exporter' ); ?></p>

        <h2><?php esc_html_e( 'Export', 'wp-customizer-exporter' ); ?></h2>
        <p>
            <?php
            printf(
                /* translators: %s: current theme name. */
                esc_html__( 'Current theme: %s. The export contains every theme mod plus the Customizer CSS.', 'wp-customizer-exporter' ),
                '<strong>' . esc_html( $current_theme->get( 'Name' ) ) . '</strong>'
            );
            ?>
        </p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=' . WCE_PAGE . '&wce_export=1' ), 'wce_export' ) ); ?>">
                <?php esc_html_e( 'Download Customizer Export (.json)', 'wp-customizer-exporter' ); ?>
            </a>
        </p>

        <hr />
        <h2><?php esc_html_e( 'Import', 'wp-customizer-exporter' ); ?></h2>
        <?php if ( $preview ) : ?>
            <div class="notice notice-warning inline"><p>
                <?php
                if ( '' !== $preview['theme'] && $preview['theme'] !== $current_theme->get_stylesheet() ) {
                    printf(
                        /* translators: 1: export theme, 2: current theme. */
                        esc_html__( 'Warning: this export is from theme "%1$s" but the current theme is "%2$s". Settings that the current theme does not use will be stored but have no visible effect.', 'wp-customizer-exporter' ),
                        esc_html( $preview['theme'] ),
                        esc_html( $current_theme->get_stylesheet() )
                    );
                } else {
                    esc_html_e( 'This export matches the current theme.', 'wp-customizer-exporter' );
                }
                ?>
            </p></div>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Setting', 'wp-customizer-exporter' ); ?></th><th><?php esc_html_e( 'Change', 'wp-customizer-exporter' ); ?></th></tr></thead>
                <tbody>
                    <?php foreach ( $preview['changes'] as $change ) : ?>
                        <tr><td><code><?php echo esc_html( $change['key'] ); ?></code></td><td><?php echo esc_html( $change['status'] ); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ( $preview['has_css'] ) : ?>
                        <tr><td><code>custom_css</code></td><td><?php esc_html_e( 'changed', 'wp-customizer-exporter' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <form method="post">
                <?php wp_nonce_field( 'wce_import_confirm' ); ?>
                <p><input type="submit" name="wce_import_confirm" class="button button-primary" value="<?php esc_attr_e( 'Confirm Import', 'wp-customizer-exporter' ); ?>" /></p>
            </form>
        <?php else : ?>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'wce_import' ); ?>
                <p>
                    <input type="file" name="wce_file" accept=".json,application/json" required />
                </p>
                <p><input type="submit" name="wce_import_preview" class="button button-secondary" value="<?php esc_attr_e( 'Preview Import', 'wp-customizer-exporter' ); ?>" /></p>
            </form>
            <p class="description"><?php esc_html_e( 'Import is a two-step process: preview first, then confirm. Nothing is changed until you confirm.', 'wp-customizer-exporter' ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
