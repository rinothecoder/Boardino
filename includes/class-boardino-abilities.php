<?php
/** WordPress Abilities API adapters for the existing board operations. */
defined( 'ABSPATH' ) || exit;

final class Boardino_Abilities {
    public static function boot() {
        add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
    }

    public static function register_category() {
        if ( function_exists( 'wp_register_ability_category' ) ) {
            wp_register_ability_category( 'boardino', array(
                'label' => __( 'Boardino', 'boardino' ),
                'description' => __( 'Manage Boardino cards, conversations, columns, and categories.', 'boardino' ),
            ) );
        }
    }

    private static function object_schema( $properties, $required = array() ) {
        $schema = array( 'type' => 'object', 'additionalProperties' => false );
        if ( $properties ) { $schema['properties'] = $properties; }
        if ( $required ) { $schema['required'] = $required; }
        return $schema;
    }

    private static function integer_schema( $description, $minimum = 1 ) {
        return array( 'type' => 'integer', 'minimum' => $minimum, 'description' => $description );
    }

    private static function text_schema( $description, $max = 60, $min = 0 ) {
        return array( 'type' => 'string', 'minLength' => $min, 'maxLength' => $max, 'description' => $description );
    }

    private static function list_schema( $items ) {
        return array( 'type' => 'array', 'items' => $items );
    }

    private static function image_schema() {
        return self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'url' => array( 'type' => 'string' ), 'thumb' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ) ), array( 'id', 'url', 'thumb', 'name' ) );
    }

    private static function card_schema() {
        return self::object_schema( array(
            'id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ),
            'description' => array( 'type' => 'string' ), 'column' => array( 'type' => 'string' ),
            'category' => array( 'type' => 'string' ), 'assignee' => array( 'type' => 'integer' ),
            'assigneeName' => array( 'type' => 'string' ), 'order' => array( 'type' => 'integer' ),
            'version' => array( 'type' => 'integer' ), 'archived' => array( 'type' => 'boolean' ),
            'comments' => array( 'type' => 'integer' ), 'images' => array( 'type' => 'integer', 'description' => 'Legacy comment-image count.' ),
            'assignees' => self::list_schema( array( 'type' => 'integer' ) ),
            'assigneeUsers' => self::list_schema( self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'avatarUrl' => array( 'type' => 'string' ) ), array( 'id', 'name', 'avatarUrl' ) ) ),
            'taskImages' => self::list_schema( self::image_schema() ),
        ), array( 'id', 'title', 'description', 'column', 'category', 'assignee', 'assigneeName', 'order', 'version', 'archived', 'comments', 'images', 'assignees', 'assigneeUsers', 'taskImages' ) );
    }

    public static function register() {
        // WordPress 6.4–6.8 still runs the ordinary plugin without this optional API.
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        $id = self::integer_schema( 'Card ID from list-cards or get-card.' );
        $version = self::integer_schema( 'Latest card version. On a conflict, read the card again before deciding whether to retry.' );
        $revision = self::integer_schema( 'Latest board revision from get-board or list-cards. On a conflict, read the board again before retrying.' );
        $fields = array(
            'title' => self::text_schema( 'Short plain-text card title.', 200, 1 ),
            'description' => self::text_schema( 'Plain-text description. An empty string clears it.', 20000 ),
            'column' => self::text_schema( 'Existing column ID from get-board.', 40, 1 ),
            'category' => self::text_schema( 'Existing category ID from get-board. An empty string removes the category.', 40 ),
            'assignee' => self::integer_schema( 'Legacy single user ID from get-board.users. Use 0 to unassign. Prefer assignees for multiple people; do not supply both.', 0 ),
            'assignees' => array( 'type' => 'array', 'items' => self::integer_schema( 'User ID from get-board.users.' ), 'uniqueItems' => true, 'description' => 'Assigned people. An empty array unassigns everyone.' ),
            'images' => array( 'type' => 'array', 'items' => self::integer_schema( 'Accessible existing Media Library image ID.' ), 'uniqueItems' => true, 'maxItems' => 10, 'description' => 'Task images, separate from comment images. An empty array removes task-image links without deleting media.' ),
        );
        $named = self::object_schema( array( 'id' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ) ), array( 'id', 'name' ) );
        $category = $named; $category['properties']['color'] = array( 'type' => 'string' ); $category['required'][] = 'color';
        $image = self::image_schema();
        $comment = self::object_schema( array(
            'id' => array( 'type' => 'integer' ), 'author' => array( 'type' => 'string' ),
            'userId' => array( 'type' => 'integer' ), 'avatarUrl' => array( 'type' => 'string' ), 'text' => array( 'type' => 'string' ),
            'date' => array( 'type' => 'string' ), 'images' => self::list_schema( $image ),
        ), array( 'id', 'author', 'userId', 'text', 'date', 'images' ) );
        $detail = self::object_schema( array( 'card' => self::card_schema(), 'comments' => self::list_schema( $comment ) ), array( 'card', 'comments' ) );
        $user = self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'admin' => array( 'type' => 'boolean' ), 'avatarUrl' => array( 'type' => 'string' ) ), array( 'id', 'name', 'admin', 'avatarUrl' ) );
        $filters = array(
            'column' => $fields['column'], 'category' => $fields['category'], 'assignee' => $fields['assignee'],
            'archived' => array( 'type' => 'boolean', 'default' => false, 'description' => 'False lists active cards; true lists archived cards.' ),
            'search' => self::text_schema( 'Case-insensitive text search in title and description.', 200 ),
            'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
            'offset' => self::integer_schema( 'Number of matching cards to skip.', 0 ),
        );
        $color = self::text_schema( 'Hex color (#rgb or #rrggbb), or purple, blue, green, orange, rose, gray.', 20, 1 );
        $category_fields = array( 'name' => self::text_schema( 'Unique plain-text category name.', 60, 1 ), 'color' => $color );
        $category_result = self::object_schema( array( 'categories' => self::list_schema( $category ), 'id' => array( 'type' => 'string' ), 'revision' => array( 'type' => 'integer' ) ), array( 'categories', 'id', 'revision' ) );

        self::ability( 'get-board', 'Get board', 'Get the board name, columns, categories, assignable users, and revision. Read this before choosing IDs or adding a column. Does not expose media-folder settings or non-member users.', array(), array(), self::object_schema( array(
            'name' => array( 'type' => 'string' ), 'columns' => self::list_schema( $named ), 'categories' => self::list_schema( $category ),
            'users' => self::list_schema( $user ), 'revision' => array( 'type' => 'integer' ),
        ), array( 'name', 'columns', 'categories', 'users', 'revision' ) ), 'get_board', true );
        self::ability( 'list-cards', 'List cards', 'List and search cards, with optional column, category, assignee and archive filters. Defaults to active cards, 50 per page, ordered by card position and ID. Use get-card to read comments.', $filters, array(), self::object_schema( array(
            'cards' => self::list_schema( self::card_schema() ), 'total' => array( 'type' => 'integer' ),
            'limit' => array( 'type' => 'integer' ), 'offset' => array( 'type' => 'integer' ), 'revision' => array( 'type' => 'integer' ),
        ), array( 'cards', 'total', 'limit', 'offset', 'revision' ) ), 'list_cards', true );
        self::ability( 'get-card', 'Get card', 'Read one active or archived card, its latest version, comments, and comment images.', array( 'id' => $id ), array( 'id' ), $detail, 'get_card', true );
        self::ability( 'create-card', 'Create card', 'Create a card. Omitted column uses To do when available, otherwise the first column; other optional fields start empty or unassigned. Repeated calls create separate cards.', $fields, array( 'title' ), self::card_schema(), 'create_card' );
        self::ability( 'update-card', 'Update card', 'Change only the supplied card fields, keeping omitted fields. Requires id, latest version, and at least one field. Archived cards must be restored first.', array_merge( array( 'id' => $id, 'version' => $version ), $fields ), array( 'id', 'version' ), self::card_schema(), 'update_card', false, true );
        self::ability( 'move-card', 'Move card', 'Move an active card to an existing column. Optional zero-based position inserts it in that column; omitted position appends. Requires the latest board revision and preserves the other cards in the destination.', array(
            'id' => $id, 'column' => $fields['column'], 'revision' => $revision, 'position' => self::integer_schema( 'Zero-based destination position. Omit to append.', 0 ),
        ), array( 'id', 'column', 'revision' ), self::object_schema( array( 'saved' => array( 'type' => 'boolean' ) ), array( 'saved' ) ), 'move_card', false, true );
        self::ability( 'archive-card', 'Archive card', 'Archive a card without deleting its data or comments. Requires the latest card version. Use restore-card to make it active again.', array( 'id' => $id, 'version' => $version ), array( 'id', 'version' ), self::card_schema(), 'archive_card', false, true );
        self::ability( 'restore-card', 'Restore card', 'Restore an archived card to the end of its column. Requires the latest card version.', array( 'id' => $id, 'version' => $version ), array( 'id', 'version' ), self::card_schema(), 'restore_card' );
        self::ability( 'add-comment', 'Add comment', 'Add a plain-text comment as the authenticated WordPress user, optionally attaching existing accessible Media Library image IDs. Requires text or images. Does not upload images or move existing media to another folder. Repeated calls create separate comments.', array(
            'id' => $id, 'text' => self::text_schema( 'Comment text.', 10000 ),
            'images' => array( 'type' => 'array', 'items' => self::integer_schema( 'Existing Media Library image ID.' ), 'maxItems' => 10, 'uniqueItems' => true ),
        ), array( 'id' ), $detail, 'add_comment' );
        self::ability( 'add-column', 'Add column', 'Administrator only: append a named column, keeping all existing board settings. Requires the latest board revision. Maximum 30 columns.', array( 'name' => self::text_schema( 'Plain-text column name.', 60, 1 ), 'revision' => $revision ), array( 'name', 'revision' ), self::object_schema( array( 'column' => $named, 'revision' => array( 'type' => 'integer' ) ), array( 'column', 'revision' ) ), 'add_column', false, false, true );
        self::ability( 'create-category', 'Create category', 'Add a uniquely named category for card labels. Maximum 30 categories. Omitted color defaults to purple.', $category_fields, array( 'name' ), $category_result, 'create_category' );
        self::ability( 'update-category', 'Update category', 'Rename or recolor an existing category. Supply the intended name and color, plus previous_name and previous_color from get-board to protect concurrent edits.', array_merge( $category_fields, array(
            'id' => self::text_schema( 'Existing category ID.', 40, 1 ),
            'previous_name' => self::text_schema( 'Current category name from get-board.', 60, 1 ),
            'previous_color' => $color,
        ) ), array( 'id', 'name', 'color', 'previous_name', 'previous_color' ), $category_result, 'update_category', false, true );
    }

    private static function ability( $slug, $label, $description, $properties, $required, $output, $callback, $readonly = false, $destructive = false, $admin = false ) {
        $input_schema = self::object_schema( $properties, $required );
        wp_register_ability( 'boardino/' . $slug, array(
            'label' => $label, 'description' => $description, 'category' => 'boardino',
            'input_schema' => $input_schema, 'output_schema' => $output,
            'permission_callback' => array( 'Boardino', $admin ? 'admin_permission' : 'permission' ),
            'execute_callback' => static function( $input ) use ( $input_schema, $callback ) {
                // Core schema validation also accepts some scalar strings. Normalize
                // them to their declared types before strict ID/filter comparisons.
                $input = rest_sanitize_value_from_schema( $input, $input_schema );
                return is_wp_error( $input ) ? $input : self::$callback( $input );
            },
            'meta' => array(
                'show_in_rest' => true,
                'mcp' => array( 'public' => true, 'type' => 'tool' ),
                'annotations' => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $readonly ),
            ),
        ) );
    }

    private static function request( $input ) {
        $request = new WP_REST_Request( 'POST' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $input ) );
        return $request;
    }

    public static function get_board( $input = array() ) {
        $board = Boardino::board();
        return array( 'name' => $board['settings']['name'], 'columns' => $board['settings']['columns'], 'categories' => $board['settings']['categories'], 'users' => $board['users'], 'revision' => $board['revision'] );
    }

    public static function list_cards( $input ) {
        $board = Boardino::board();
        foreach ( array( 'column' => 'columns', 'category' => 'categories' ) as $field => $setting ) {
            if ( ! empty( $input[$field] ) && ! in_array( $input[$field], array_column( $board['settings'][$setting], 'id' ), true ) ) {
                return new WP_Error( 'boardino_error', 'Choose an existing ' . $field . '.', array( 'status' => 400 ) );
            }
        }
        $cards = array_values( array_filter( $board['cards'], static function( $card ) use ( $input ) {
            if ( $card['archived'] !== ( $input['archived'] ?? false ) ) { return false; }
            foreach ( array( 'column', 'category' ) as $field ) {
                if ( isset( $input[$field] ) && $card[$field] !== $input[$field] ) { return false; }
            }
            if ( isset( $input['assignee'] ) && ( $input['assignee'] === 0 ? !empty( $card['assignees'] ) : !in_array( $input['assignee'], $card['assignees'], true ) ) ) { return false; }
            return empty( $input['search'] ) || false !== mb_stripos( $card['title'] . "\n" . $card['description'], $input['search'] );
        } ) );
        $limit = $input['limit'] ?? 50; $offset = $input['offset'] ?? 0;
        return array( 'cards' => array_map( array( __CLASS__, 'card_output' ), array_slice( $cards, $offset, $limit ) ), 'total' => count( $cards ), 'limit' => $limit, 'offset' => $offset, 'revision' => $board['revision'] );
    }

    public static function card_output( $card ) {
        if ( is_wp_error( $card ) ) { return $card; }
        $card['taskImages'] = $card['images'];
        $card['images'] = (int) get_post_meta( $card['id'], '_bi_image_count', true );
        $card['assignee'] = $card['assignees'][0] ?? 0;
        $card['assigneeName'] = $card['assigneeUsers'][0]['name'] ?? '';
        return $card;
    }
    private static function detail_output( $detail ) {
        if ( ! is_wp_error( $detail ) ) { $detail['card'] = self::card_output( $detail['card'] ); }
        return $detail;
    }
    private static function normalize_assignees( $input ) {
        if ( array_key_exists( 'assignee', $input ) ) {
            if ( array_key_exists( 'assignees', $input ) ) { return new WP_Error( 'boardino_error', 'Supply assignees or the legacy assignee field, not both.', array( 'status' => 400 ) ); }
            $input['assignees'] = $input['assignee'] ? array( $input['assignee'] ) : array();
            unset( $input['assignee'] );
        }
        return $input;
    }
    public static function get_card( $input ) { return self::detail_output( Boardino::get_card( self::request( $input ) ) ); }

    public static function create_card( $input ) {
        $input = self::normalize_assignees( $input ); if ( is_wp_error( $input ) ) { return $input; }
        $columns = array_column( Boardino::settings()['columns'], 'id' );
        $input += array( 'column' => in_array( 'todo', $columns, true ) ? 'todo' : ( $columns[0] ?? '' ), 'description' => '', 'category' => '', 'assignees' => array(), 'images' => array() );
        return self::card_output( Boardino::create_card( self::request( $input ) ) );
    }

    public static function update_card( $input ) {
        $input = self::normalize_assignees( $input ); if ( is_wp_error( $input ) ) { return $input; }
        $fields = array( 'title', 'description', 'column', 'category', 'assignees', 'images' );
        if ( ! array_intersect( array_keys( $input ), $fields ) ) {
            return new WP_Error( 'boardino_error', 'Supply at least one card field to update.', array( 'status' => 400 ) );
        }
        $detail = Boardino::get_card( self::request( $input ) );
        if ( is_wp_error( $detail ) ) { return $detail; }
        // Merge for partial updates; the original method rechecks the supplied
        // version inside the write lock before storing any of these fields.
        foreach ( $fields as $field ) {
            if ( ! array_key_exists( $field, $input ) ) { $input[$field] = 'images' === $field ? array_column( $detail['card']['images'], 'id' ) : $detail['card'][$field]; }
        }
        return self::card_output( Boardino::update_card( self::request( $input ) ) );
    }

    public static function move_card( $input ) {
        $board = Boardino::board();
        $order = array();
        foreach ( $board['cards'] as $card ) {
            if ( ! $card['archived'] && $card['column'] === $input['column'] && $card['id'] !== $input['id'] ) { $order[] = $card['id']; }
        }
        $position = $input['position'] ?? count( $order );
        if ( $position > count( $order ) ) { return new WP_Error( 'boardino_error', 'Choose a position within the destination column.', array( 'status' => 400 ) ); }
        array_splice( $order, $position, 0, array( $input['id'] ) );
        $input['order'] = $order;
        // Existing move logic verifies revision and the complete destination
        // order under its write lock, including changes since this snapshot.
        return Boardino::move_card( self::request( $input ) );
    }

    public static function archive_card( $input ) { $input['archived'] = true; return self::card_output( Boardino::update_card( self::request( $input ) ) ); }
    public static function restore_card( $input ) { $input['archived'] = false; return self::card_output( Boardino::update_card( self::request( $input ) ) ); }
    public static function add_comment( $input ) { return self::detail_output( Boardino::add_comment( self::request( $input ) ) ); }
    public static function add_column( $input ) { return Boardino::add_column( self::request( $input ) ); }
    public static function create_category( $input ) { return Boardino::save_category( self::request( $input ) ); }
    public static function update_category( $input ) { return Boardino::save_category( self::request( $input ) ); }
}
