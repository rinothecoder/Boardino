<?php
defined( 'ABSPATH' ) || exit;

final class Boardino {
    const TYPE = 'boardino_card';
    const OPTION = 'boardino_settings';
    const API = 'boardino/v1';

    public static function defaults() {
        return array(
            'name' => 'My board', 'members' => array(), 'folder_id' => 0,
            'columns' => array( array( 'id' => 'todo', 'name' => 'To do' ), array( 'id' => 'doing', 'name' => 'Doing' ), array( 'id' => 'done', 'name' => 'Done' ) ),
            'categories' => array(),
        );
    }
    public static function settings() {
        return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
    }
    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_type' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'user_has_cap', array( __CLASS__, 'media_capabilities' ), 20, 4 );
        add_filter( 'upload_mimes', array( __CLASS__, 'media_mimes' ) );
        add_filter( 'upload_size_limit', array( __CLASS__, 'media_size' ) );
        add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'media_attachment' ), 20, 3 );
        add_action( 'add_attachment', array( __CLASS__, 'media_uploaded' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( BOARDINO_FILE ), array( __CLASS__, 'plugin_links' ) );
    }
    private static function folderino_available() {
        if ( ! apply_filters( 'boardino_use_folderino', true ) || ! defined( 'FOLDERINO_TAX' ) || ! defined( 'FOLDERINO_DIR' ) || function_exists( 'rdb_mf_get_tree' ) ) { return false; }
        // Folderino normally boots only in wp-admin. Use its own registration API
        // on Boardino's REST requests rather than creating a second taxonomy.
        if ( ! taxonomy_exists( FOLDERINO_TAX ) ) {
            $file = FOLDERINO_DIR . 'includes/functions.php';
            if ( ! is_readable( $file ) ) { return false; }
            require_once $file;
            if ( function_exists( 'folderino_register_taxonomy' ) ) { folderino_register_taxonomy(); }
        }
        return taxonomy_exists( FOLDERINO_TAX );
    }
    public static function activate() {
        if ( false !== get_option( self::OPTION, false ) ) { return; }
        $settings = self::defaults();
        $settings['members'] = array( get_current_user_id() );
        if ( self::folderino_available() ) {
            $folders = get_terms( array( 'taxonomy' => FOLDERINO_TAX, 'hide_empty' => false, 'parent' => 0 ) );
            if ( ! is_wp_error( $folders ) ) {
                foreach ( $folders as $folder ) {
                    if ( 'Boards' === html_entity_decode( $folder->name, ENT_QUOTES, 'UTF-8' ) ) { $settings['folder_id'] = (int) $folder->term_id; break; }
                }
            }
            if ( ! $settings['folder_id'] ) {
                $created = wp_insert_term( 'Boards', FOLDERINO_TAX );
                if ( ! is_wp_error( $created ) ) {
                    $settings['folder_id'] = (int) $created['term_id'];
                    if ( function_exists( 'folderino_next_order' ) ) { update_term_meta( $created['term_id'], 'folderino_order', folderino_next_order( 0 ) ); }
                }
            }
        }
        add_option( self::OPTION, $settings, '', false );
        add_option( 'boardino_revision', 1, '', false );
    }
    public static function register_type() {
        // Internal plugin records, independent of site content-model plugins.
        register_post_type( self::TYPE, array( 'label' => 'Boardino cards', 'public' => false, 'publicly_queryable' => false, 'show_ui' => false, 'show_in_rest' => false, 'exclude_from_search' => true, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title', 'editor', 'author' ), 'capabilities' => array( 'edit_post' => 'do_not_allow', 'read_post' => 'do_not_allow', 'delete_post' => 'do_not_allow', 'edit_posts' => 'do_not_allow', 'create_posts' => 'do_not_allow', 'delete_posts' => 'do_not_allow', 'read_private_posts' => 'do_not_allow' ), 'map_meta_cap' => false ) );
    }
    public static function allowed( $id = 0 ) {
        $id = $id ? absint( $id ) : get_current_user_id();
        return $id && is_user_member_of_blog( $id ) && ( user_can( $id, 'manage_options' ) || in_array( $id, array_map( 'intval', self::settings()['members'] ), true ) );
    }
    public static function permission() {
        return self::allowed() ? true : new WP_Error( 'boardino_forbidden', 'You do not have access to this board.', array( 'status' => 403 ) );
    }
    public static function admin_permission() {
        return current_user_can( 'manage_options' ) ? true : new WP_Error( 'boardino_forbidden', 'Only site administrators can change board settings.', array( 'status' => 403 ) );
    }
    public static function menu() {
        if ( ! self::allowed() ) { return; }
        $icon_path = plugin_dir_path( BOARDINO_FILE ) . 'assets/boardino-sidebar.svg';
        $menu_icon = is_readable( $icon_path ) ? 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $icon_path ) ) : 'dashicons-screenoptions';
        add_menu_page( 'Boardino', 'Boardino', 'read', 'boardino', array( __CLASS__, 'page' ), $menu_icon, 58 );
        add_submenu_page( 'boardino', 'Board', 'Board', 'read', 'boardino', array( __CLASS__, 'page' ) );
        if ( current_user_can( 'manage_options' ) ) { add_submenu_page( 'boardino', 'Boardino settings', 'Settings', 'manage_options', 'boardino-settings', array( __CLASS__, 'page' ) ); }
    }
    public static function plugin_links( $links ) {
        if ( self::allowed() ) { array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=boardino' ) ) . '">Open board</a>' ); }
        return $links;
    }
    public static function assets( $hook ) {
        if ( ! in_array( $hook, array( 'toplevel_page_boardino', 'boardino_page_boardino-settings' ), true ) || ! self::allowed() ) { return; }
        wp_enqueue_style( 'boardino', plugins_url( 'assets/admin.css', BOARDINO_FILE ), array(), BOARDINO_VERSION );
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_media();
        wp_enqueue_script( 'boardino-icons', plugins_url( 'assets/lucide-icons.js', BOARDINO_FILE ), array(), BOARDINO_VERSION, true );
        wp_enqueue_script( 'boardino', plugins_url( 'assets/admin.js', BOARDINO_FILE ), array( 'jquery', 'jquery-ui-sortable', 'media-views', 'boardino-icons' ), BOARDINO_VERSION, true );
        wp_add_inline_script( 'boardino', 'window.BoardinoConfig = ' . wp_json_encode( array( 'api' => rest_url( self::API . '/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'mediaNonce' => wp_create_nonce( 'boardino_media' ), 'userId' => get_current_user_id(), 'admin' => current_user_can( 'manage_options' ), 'settings' => 'boardino_page_boardino-settings' === $hook, 'boardUrl' => admin_url( 'admin.php?page=boardino' ), 'settingsUrl' => admin_url( 'admin.php?page=boardino-settings' ), 'maxUpload' => min( wp_max_upload_size(), 10 * MB_IN_BYTES ) ) ) . ';', 'before' );
    }
    public static function page() {
        if ( ! self::allowed() ) { wp_die( esc_html__( 'You do not have access to this board.', 'boardino' ) ); }
        echo '<div id="boardino" class="boardino"><header class="bi-header"><a class="bi-brand" href="' . esc_url( admin_url( 'admin.php?page=boardino' ) ) . '"><img class="bi-brand-icon" src="' . esc_url( plugins_url( 'assets/boardino-icon.svg', BOARDINO_FILE ) ) . '" alt=""><h1>Boardino</h1></a><nav aria-label="Boardino"><a href="' . esc_url( admin_url( 'admin.php?page=boardino' ) ) . '">Board</a>';
        if ( current_user_can( 'manage_options' ) ) { echo '<a href="' . esc_url( admin_url( 'admin.php?page=boardino-settings' ) ) . '">Settings</a>'; }
        echo '</nav></header><hr class="wp-header-end"><main id="bi-main" class="bi-main"><div class="bi-loading" role="status"><span></span><span></span><span></span><p>Loading your board…</p></div></main>';
        if ( current_user_can( 'manage_options' ) && 'boardino-settings' === sanitize_key( $_GET['page'] ?? '' ) ) { Boardino_Updates::screen(); }
        echo '<div id="bi-notice" class="bi-toast" role="status" aria-live="polite" hidden></div><div id="bi-layer"></div><noscript><p>Please enable JavaScript to use Boardino.</p></noscript></div>';
    }
    public static function routes() {
        $member = array( __CLASS__, 'permission' ); $admin = array( __CLASS__, 'admin_permission' );
        register_rest_route( self::API, '/board', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'board' ), 'permission_callback' => $member ) );
        register_rest_route( self::API, '/settings', array( array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => $admin ), array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $admin ) ) );
        register_rest_route( self::API, '/cards', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_card' ), 'permission_callback' => $member ) );
        register_rest_route( self::API, '/cards/(?P<id>\d+)', array( array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_card' ), 'permission_callback' => $member ), array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_card' ), 'permission_callback' => $member ) ) );
        register_rest_route( self::API, '/move', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'move_card' ), 'permission_callback' => $member ) );
        register_rest_route( self::API, '/categories', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_category' ), 'permission_callback' => $member ) );
        register_rest_route( self::API, '/cards/(?P<id>\d+)/comments', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_comment' ), 'permission_callback' => $member ) );
        register_rest_route( self::API, '/cards/(?P<id>\d+)/images', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_image' ), 'permission_callback' => $member ) );
    }
    private static function error( $message, $status = 400 ) { return new WP_Error( 'boardino_error', $message, array( 'status' => $status ) ); }
    private static function ids( $items ) { return array_column( $items, 'id' ); }
    private static function category_color( $color ) {
        if ( ! is_string( $color ) ) { return false; }
        if ( in_array( $color, array( 'purple', 'blue', 'green', 'orange', 'rose', 'gray' ), true ) ) { return $color; }
        $hex = sanitize_hex_color( $color );
        return $hex ? strtolower( $hex ) : false;
    }
    private static function revision() { return (int) get_option( 'boardino_revision', 1 ); }
    private static function mutate( $callback ) {
        $token = wp_generate_uuid4();
        if ( ! add_option( 'boardino_write_lock', array( 'token' => $token, 'time' => time() ), '', false ) ) {
            // Never steal a live lock; an interrupted request can be recovered after its execution limit.
            $lock = get_option( 'boardino_write_lock' );
            if ( isset( $lock['time'] ) && time() - $lock['time'] > 120 ) { delete_option( 'boardino_write_lock' ); }
            return self::error( 'Another change is being saved. Please try again.', 409 );
        }
        try { $result = $callback(); if ( ! is_wp_error( $result ) ) { update_option( 'boardino_revision', self::revision() + 1, false ); } return $result; }
        finally { $lock = get_option( 'boardino_write_lock' ); if ( isset( $lock['token'] ) && $token === $lock['token'] ) { delete_option( 'boardino_write_lock' ); } }
    }
    private static function post( $id ) {
        $p = get_post( absint( $id ) );
        return $p && self::TYPE === $p->post_type && 'private' === $p->post_status ? $p : self::error( 'This card could not be found.', 404 );
    }
    private static function users( $all = false ) {
        $out = array();
        foreach ( get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) ) as $u ) {
            if ( $all || self::allowed( $u->ID ) ) { $out[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'admin' => user_can( $u, 'manage_options' ) ); }
        }
        return $out;
    }
    private static function card( $p ) {
        $assignee = (int) get_post_meta( $p->ID, '_bi_assignee', true );
        $u = $assignee ? get_userdata( $assignee ) : false;
        return array( 'id' => (int) $p->ID, 'title' => $p->post_title, 'description' => $p->post_content, 'column' => get_post_meta( $p->ID, '_bi_column', true ), 'category' => get_post_meta( $p->ID, '_bi_category', true ), 'assignee' => $assignee, 'assigneeName' => $u ? $u->display_name : '', 'order' => (int) $p->menu_order, 'version' => (int) get_post_meta( $p->ID, '_bi_version', true ), 'archived' => (bool) get_post_meta( $p->ID, '_bi_archived', true ), 'comments' => (int) get_comments( array( 'post_id' => $p->ID, 'type' => 'boardino', 'status' => 'approve', 'count' => true ) ), 'images' => (int) get_post_meta( $p->ID, '_bi_image_count', true ) );
    }
    public static function board() {
        $posts = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'posts_per_page' => -1, 'orderby' => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ), 'suppress_filters' => true ) );
        $settings = self::settings(); unset( $settings['members'], $settings['folder_id'] );
        return array( 'settings' => $settings, 'users' => self::users(), 'cards' => array_map( array( __CLASS__, 'card' ), $posts ), 'revision' => self::revision() );
    }
    public static function get_settings() {
        $folders = array(); $active = self::folderino_available();
        if ( $active ) {
            $terms = get_terms( array( 'taxonomy' => FOLDERINO_TAX, 'hide_empty' => false ) );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $names = array( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
                    foreach ( get_ancestors( $term->term_id, FOLDERINO_TAX, 'taxonomy' ) as $ancestor ) { $a = get_term( $ancestor, FOLDERINO_TAX ); if ( $a instanceof WP_Term ) { array_unshift( $names, html_entity_decode( $a->name, ENT_QUOTES, 'UTF-8' ) ); } }
                    $folders[] = array( 'id' => (int) $term->term_id, 'name' => implode( ' / ', $names ) );
                }
            }
            usort( $folders, static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
        }
        return array( 'settings' => self::settings(), 'users' => self::users( true ), 'folderino' => $active, 'folders' => $folders, 'revision' => self::revision() );
    }
    public static function save_settings( $r ) {
        return self::mutate( static function() use ( $r ) {
            if ( (int) $r['revision'] !== self::revision() ) { return self::error( 'The board has changed. Reload settings before saving.', 409 ); }
            $old = self::settings(); $new = $r->get_json_params();
            $name = sanitize_text_field( $new['name'] ?? '' );
            if ( ! $name || mb_strlen( $name ) > 100 ) { return self::error( 'Give your board a name of up to 100 characters.' ); }
            $settings = array( 'name' => $name, 'members' => array(), 'folder_id' => absint( $new['folder_id'] ?? 0 ), 'columns' => array(), 'categories' => array() );
            foreach ( array( 'columns', 'categories' ) as $key ) {
                if ( ! isset( $new[$key] ) || ! is_array( $new[$key] ) || count( $new[$key] ) > 30 ) { return self::error( 'Use up to 30 ' . $key . '.' ); }
                $seen = array();
                foreach ( $new[$key] as $item ) {
                    if ( ! is_array( $item ) ) { return self::error( 'Please check the ' . $key . '.' ); }
                    $id = sanitize_key( $item['id'] ?? '' ); $label = sanitize_text_field( $item['name'] ?? '' );
                    if ( ! $id || strlen( $id ) > 40 || ! $label || mb_strlen( $label ) > 60 || isset( $seen[$id] ) ) { return self::error( 'Each ' . ( 'columns' === $key ? 'column' : 'category' ) . ' needs a unique ID and a name of up to 60 characters.' ); }
                    $seen[$id] = true; $row = array( 'id' => $id, 'name' => $label );
                    if ( 'categories' === $key ) { $row['color'] = self::category_color( $item['color'] ?? 'purple' ); if ( ! $row['color'] ) { return self::error( 'Choose a valid category color.' ); } }
                    $settings[$key][] = $row;
                }
            }
            if ( ! $settings['columns'] ) { return self::error( 'Keep at least one column.' ); }
            $all = array_column( self::users( true ), 'id' );
            if ( ! is_array( $new['members'] ?? null ) ) { return self::error( 'Please select the people who can use this board.' ); }
            $settings['members'] = array_values( array_intersect( array_map( 'absint', $new['members'] ), $all ) );
            if ( $settings['folder_id'] ) {
                if ( ! self::folderino_available() ) {
                    if ( $settings['folder_id'] !== (int) $old['folder_id'] ) { return self::error( 'Folderino is not active. Choose the normal Media Library.' ); }
                } else { $term = get_term( $settings['folder_id'], FOLDERINO_TAX ); if ( ! $term instanceof WP_Term ) { return self::error( 'This media folder no longer exists. Choose another folder.' ); } }
            }
            $removed = array_diff( self::ids( $old['columns'] ), self::ids( $settings['columns'] ) );
            if ( $removed ) {
                $occupied = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_bi_column', 'value' => array_values( $removed ), 'compare' => 'IN' ) ) ) );
                if ( $occupied ) { return self::error( 'Move the cards out of a column before removing it, including archived cards.' ); }
            }
            update_option( self::OPTION, $settings, false );
            $cards = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'posts_per_page' => -1, 'fields' => 'ids' ) );
            foreach ( $cards as $id ) {
                $changed = false;
                if ( ! in_array( get_post_meta( $id, '_bi_category', true ), self::ids( $settings['categories'] ), true ) && get_post_meta( $id, '_bi_category', true ) ) { delete_post_meta( $id, '_bi_category' ); $changed = true; }
                $assignee = (int) get_post_meta( $id, '_bi_assignee', true );
                if ( $assignee && ! self::allowed( $assignee ) ) { delete_post_meta( $id, '_bi_assignee' ); $changed = true; }
                if ( $changed ) { update_post_meta( $id, '_bi_version', (int) get_post_meta( $id, '_bi_version', true ) + 1 ); }
            }
            return array( 'saved' => true );
        } );
    }
    private static function validate_card( $data ) {
        $s = self::settings();
        $title = sanitize_text_field( $data['title'] ?? '' );
        if ( ! $title || mb_strlen( $title ) > 200 ) { return self::error( 'Give your card a title of up to 200 characters.' ); }
        $description = sanitize_textarea_field( $data['description'] ?? '' );
        if ( mb_strlen( $description ) > 20000 ) { return self::error( 'Keep the description under 20,000 characters.' ); }
        $column = sanitize_key( $data['column'] ?? '' ); $category = sanitize_key( $data['category'] ?? '' ); $assignee = absint( $data['assignee'] ?? 0 );
        if ( ! in_array( $column, self::ids( $s['columns'] ), true ) ) { return self::error( 'Choose an existing column.' ); }
        if ( $category && ! in_array( $category, self::ids( $s['categories'] ), true ) ) { return self::error( 'Choose an existing category.' ); }
        if ( $assignee && ! self::allowed( $assignee ) ) { return self::error( 'Choose a person who has access to this board.' ); }
        return compact( 'title', 'description', 'column', 'category', 'assignee' );
    }
    private static function next_order( $column ) {
        $posts = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'meta_key' => '_bi_column', 'meta_value' => $column, 'orderby' => 'menu_order', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_bi_archived', 'compare' => 'NOT EXISTS' ) ) ) );
        return $posts ? (int) $posts[0]->menu_order + 1 : 0;
    }
    public static function create_card( $r ) {
        return self::mutate( static function() use ( $r ) {
            $d = self::validate_card( $r->get_json_params() ); if ( is_wp_error( $d ) ) { return $d; }
            $id = wp_insert_post( wp_slash( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'post_title' => $d['title'], 'post_content' => $d['description'], 'post_author' => get_current_user_id(), 'comment_status' => 'closed', 'menu_order' => self::next_order( $d['column'] ) ) ), true );
            if ( is_wp_error( $id ) ) { return $id; }
            foreach ( array( 'column', 'category', 'assignee' ) as $key ) { update_post_meta( $id, '_bi_' . $key, $d[$key] ); }
            update_post_meta( $id, '_bi_version', 1 );
            return self::card( get_post( $id ) );
        } );
    }
    public static function update_card( $r ) {
        return self::mutate( static function() use ( $r ) {
            $p = self::post( $r['id'] ); if ( is_wp_error( $p ) ) { return $p; }
            if ( (int) $r['version'] !== (int) get_post_meta( $p->ID, '_bi_version', true ) ) { return self::error( 'Someone changed this card. Reopen it to see their changes before saving.', 409 ); }
            if ( $r->has_param( 'archived' ) ) {
                if ( ! is_bool( $r['archived'] ) ) { return self::error( 'Please choose whether to archive or restore this card.' ); }
                // Keep archives outside WordPress Trash, which is automatically emptied.
                if ( $r['archived'] ) { update_post_meta( $p->ID, '_bi_archived', time() ); }
                else { delete_post_meta( $p->ID, '_bi_archived' ); wp_update_post( array( 'ID' => $p->ID, 'menu_order' => self::next_order( get_post_meta( $p->ID, '_bi_column', true ) ) ) ); }
            } else {
                if ( get_post_meta( $p->ID, '_bi_archived', true ) ) { return self::error( 'Restore this card before editing it.' ); }
                $d = self::validate_card( $r->get_json_params() ); if ( is_wp_error( $d ) ) { return $d; }
                $patch = array( 'ID' => $p->ID, 'post_title' => $d['title'], 'post_content' => $d['description'] );
                if ( $d['column'] !== get_post_meta( $p->ID, '_bi_column', true ) ) { $patch['menu_order'] = self::next_order( $d['column'] ); }
                $result = wp_update_post( wp_slash( $patch ), true ); if ( is_wp_error( $result ) ) { return $result; }
                foreach ( array( 'column', 'category', 'assignee' ) as $key ) { update_post_meta( $p->ID, '_bi_' . $key, $d[$key] ); }
            }
            update_post_meta( $p->ID, '_bi_version', (int) get_post_meta( $p->ID, '_bi_version', true ) + 1 );
            return self::card( get_post( $p->ID ) );
        } );
    }
    public static function move_card( $r ) {
        return self::mutate( static function() use ( $r ) {
            if ( (int) $r['revision'] !== self::revision() ) { return self::error( 'The board has changed. It has been refreshed; please move the card again.', 409 ); }
            $p = self::post( $r['id'] ); if ( is_wp_error( $p ) ) { return $p; }
            if ( get_post_meta( $p->ID, '_bi_archived', true ) ) { return self::error( 'Restore this card before moving it.' ); }
            $column = sanitize_key( $r['column'] ?? '' );
            if ( ! in_array( $column, self::ids( self::settings()['columns'] ), true ) ) { return self::error( 'Choose an existing column.' ); }
            $order = $r['order']; if ( ! is_array( $order ) ) { return self::error( 'The card order could not be saved.' ); }
            $order = array_map( 'absint', $order );
            $existing = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_bi_column', 'meta_value' => $column, 'meta_query' => array( array( 'key' => '_bi_archived', 'compare' => 'NOT EXISTS' ) ) ) );
            $expected = array_values( array_unique( array_merge( array_map( 'intval', $existing ), array( (int) $p->ID ) ) ) ); $sorted = $order;
            sort( $expected ); sort( $sorted );
            if ( $expected !== $sorted ) { return self::error( 'The board has changed. Refresh it before moving this card.', 409 ); }
            update_post_meta( $p->ID, '_bi_column', $column );
            foreach ( $order as $index => $id ) { wp_update_post( array( 'ID' => $id, 'menu_order' => $index ) ); }
            update_post_meta( $p->ID, '_bi_version', (int) get_post_meta( $p->ID, '_bi_version', true ) + 1 );
            return array( 'saved' => true );
        } );
    }
    private static function image( $id ) {
        $full = wp_get_attachment_url( $id ); $thumb = wp_get_attachment_image_url( $id, 'medium' );
        return $full ? array( 'id' => (int) $id, 'url' => $full, 'thumb' => $thumb ? $thumb : $full, 'name' => get_the_title( $id ) ) : null;
    }
    private static function can_use_image( $id ) {
        $p = get_post( $id );
        if ( ! $p || 'attachment' !== $p->post_type || ! wp_attachment_is_image( $id ) || ! in_array( $p->post_status, array( 'inherit', 'private' ), true ) ) { return false; }
        $card_id = (int) get_post_meta( $id, '_bi_card', true );
        if ( $card_id && ! is_wp_error( self::post( $card_id ) ) && self::allowed() ) { return true; }
        return current_user_can( 'read_post', $id );
    }
    private static function media_context() {
        if ( ! self::allowed() ) { return false; }
        if ( is_admin() && 'admin.php' === ( $GLOBALS['pagenow'] ?? '' ) && ! wp_doing_ajax() && isset( $_GET['page'] ) && in_array( sanitize_key( wp_unslash( $_GET['page'] ) ), array( 'boardino', 'boardino-settings' ), true ) ) { return true; }
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $nonce = isset( $_REQUEST['boardino_media_nonce'] ) && is_string( $_REQUEST['boardino_media_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['boardino_media_nonce'] ) ) : '';
        return in_array( $action, array( 'query-attachments', 'get-attachment', 'upload-attachment' ), true ) && $nonce && wp_verify_nonce( $nonce, 'boardino_media' );
    }
    public static function media_capabilities( $caps, $requested, $args, $user ) {
        // Enable only the media selector on this board and nonce-bound media requests.
        // No role changes, media editing, or media deletion permissions are granted.
        if ( in_array( 'upload_files', $requested, true ) && (int) $user->ID === get_current_user_id() && self::media_context() ) { $caps['upload_files'] = true; }
        return $caps;
    }
    public static function media_mimes( $mimes ) {
        return self::media_context() ? array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif' ) : $mimes;
    }
    public static function media_size( $limit ) { return self::media_context() ? min( $limit, 10 * MB_IN_BYTES ) : $limit; }
    public static function media_attachment( $response, $attachment, $meta ) {
        return self::media_context() && ! self::can_use_image( $attachment->ID ) ? null : $response;
    }
    public static function media_uploaded( $id ) {
        if ( ! self::media_context() || empty( $_REQUEST['boardino_card'] ) || ! wp_attachment_is_image( $id ) ) { return; }
        $card = self::post( absint( $_REQUEST['boardino_card'] ) );
        if ( is_wp_error( $card ) || get_post_meta( $card->ID, '_bi_archived', true ) ) { return; }
        update_post_meta( $id, '_bi_card', $card->ID ); update_post_meta( $id, '_bi_uploader', get_current_user_id() );
        // Only newly uploaded images are categorized; selecting an existing file never moves it.
        $settings = self::settings();
        if ( $settings['folder_id'] && self::folderino_available() ) {
            $term = get_term( $settings['folder_id'], FOLDERINO_TAX );
            if ( $term instanceof WP_Term ) { wp_set_object_terms( $id, array( (int) $term->term_id ), FOLDERINO_TAX ); }
        }
    }
    public static function save_category( $r ) {
        return self::mutate( static function() use ( $r ) {
            $s = self::settings(); $id = sanitize_key( $r['id'] ?? '' ); $name = sanitize_text_field( $r['name'] ?? '' ); $color = self::category_color( $r['color'] ?? 'purple' );
            if ( ! $name || mb_strlen( $name ) > 60 || ! $color ) { return self::error( 'Use a category name of up to 60 characters and choose a valid color.' ); }
            foreach ( $s['categories'] as $c ) { if ( $c['id'] !== $id && 0 === strcasecmp( $c['name'], $name ) ) { return self::error( 'A category with that name already exists.' ); } }
            if ( $id ) {
                $index = array_search( $id, self::ids( $s['categories'] ), true );
                if ( false === $index ) { return self::error( 'This category no longer exists. Reopen the category editor.', 409 ); }
                $c = $s['categories'][$index];
                if ( $r['previous_name'] !== $c['name'] || $r['previous_color'] !== ( $c['color'] ?? 'purple' ) ) { return self::error( 'Someone changed this category. Reopen the category editor before saving.', 409 ); }
                $s['categories'][$index] = array( 'id' => $id, 'name' => $name, 'color' => $color );
            } else {
                if ( count( $s['categories'] ) >= 30 ) { return self::error( 'Use up to 30 categories.' ); }
                $id = 'cat_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 );
                $s['categories'][] = array( 'id' => $id, 'name' => $name, 'color' => $color );
            }
            update_option( self::OPTION, $s, false );
            return array( 'categories' => $s['categories'], 'id' => $id, 'revision' => self::revision() + 1 );
        } );
    }
    public static function get_card( $r ) {
        $p = self::post( $r['id'] ); if ( is_wp_error( $p ) ) { return $p; }
        $comments = array();
        foreach ( get_comments( array( 'post_id' => $p->ID, 'type' => 'boardino', 'status' => 'approve', 'orderby' => 'comment_ID', 'order' => 'ASC', 'number' => 0 ) ) as $c ) {
            $images = array(); foreach ( (array) get_comment_meta( $c->comment_ID, '_bi_images', true ) as $id ) { $image = self::image( absint( $id ) ); if ( $image ) { $images[] = $image; } }
            $user = get_userdata( $c->user_id );
            $comments[] = array( 'id' => (int) $c->comment_ID, 'author' => $user ? $user->display_name : $c->comment_author, 'userId' => (int) $c->user_id, 'text' => $c->comment_content, 'date' => get_comment_date( 'c', $c ), 'images' => $images );
        }
        return array( 'card' => self::card( $p ), 'comments' => $comments );
    }
    public static function add_comment( $r ) {
        return self::mutate( static function() use ( $r ) {
            $p = self::post( $r['id'] ); if ( is_wp_error( $p ) ) { return $p; }
            if ( get_post_meta( $p->ID, '_bi_archived', true ) ) { return self::error( 'Restore this card before commenting.' ); }
            $text = sanitize_textarea_field( $r['text'] ?? '' );
            $raw = $r['images'] ?? array(); if ( ! is_array( $raw ) ) { return self::error( 'Please check the attached images.' ); }
            $images = array_values( array_unique( array_map( 'absint', $raw ) ) );
            if ( ( ! trim( $text ) && ! $images ) || mb_strlen( $text ) > 10000 || count( $images ) > 10 ) { return self::error( 'Add a comment or an image. Use up to 10 images and 10,000 characters.' ); }
            foreach ( $images as $id ) {
                if ( ! self::can_use_image( $id ) ) { return self::error( 'One of these images is unavailable or you do not have access to it.' ); }
            }
            $u = wp_get_current_user();
            $id = wp_insert_comment( wp_slash( array( 'comment_post_ID' => $p->ID, 'comment_content' => $text, 'comment_type' => 'boardino', 'comment_approved' => 1, 'user_id' => $u->ID, 'comment_author' => $u->display_name, 'comment_author_email' => '' ) ) );
            if ( ! $id ) { return self::error( 'Your comment could not be saved.' ); }
            update_comment_meta( $id, '_bi_images', $images );
            foreach ( $images as $image ) {
                if ( (int) get_post_meta( $image, '_bi_card', true ) === (int) $p->ID && (int) get_post_meta( $image, '_bi_uploader', true ) === get_current_user_id() && ! get_post_meta( $image, '_bi_comment', true ) ) { update_post_meta( $image, '_bi_comment', $id ); }
            }
            update_post_meta( $p->ID, '_bi_image_count', (int) get_post_meta( $p->ID, '_bi_image_count', true ) + count( $images ) );
            return self::get_card( $r );
        } );
    }
    public static function upload_image( $r ) {
        $p = self::post( $r['id'] ); if ( is_wp_error( $p ) ) { return $p; }
        if ( get_post_meta( $p->ID, '_bi_archived', true ) ) { return self::error( 'Restore this card before attaching images.' ); }
        $files = $r->get_file_params(); $file = $files['file'] ?? null;
        if ( ! $file || is_array( $file['name'] ) || ! empty( $file['error'] ) ) { return self::error( 'Choose an image to upload.' ); }
        if ( $file['size'] > min( wp_max_upload_size(), 10 * MB_IN_BYTES ) ) { return self::error( 'This image is too large. The limit is ' . size_format( min( wp_max_upload_size(), 10 * MB_IN_BYTES ) ) . '.' ); }
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif' );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
        if ( empty( $check['type'] ) || ! wp_get_image_mime( $file['tmp_name'] ) || ! in_array( wp_get_image_mime( $file['tmp_name'] ), $mimes, true ) ) { return self::error( 'Choose a JPG, PNG, GIF, WebP, or AVIF image.' ); }
        $_FILES['boardino_image'] = $file;
        $id = media_handle_upload( 'boardino_image', $p->ID, array(), array( 'test_form' => false, 'mimes' => $mimes ) ); unset( $_FILES['boardino_image'] );
        if ( is_wp_error( $id ) ) { return $id; }
        update_post_meta( $id, '_bi_card', $p->ID ); update_post_meta( $id, '_bi_uploader', get_current_user_id() );
        $s = self::settings(); $note = '';
        if ( $s['folder_id'] && self::folderino_available() ) {
            $term = get_term( $s['folder_id'], FOLDERINO_TAX );
            if ( $term instanceof WP_Term ) { $result = wp_set_object_terms( $id, array( (int) $term->term_id ), FOLDERINO_TAX ); if ( is_wp_error( $result ) ) { $note = 'Image uploaded to the Media Library, but the folder could not be applied.'; } }
            else { $note = 'Image uploaded to the Media Library. The selected folder no longer exists.'; }
        }
        return array( 'image' => self::image( $id ), 'note' => $note );
    }
}
