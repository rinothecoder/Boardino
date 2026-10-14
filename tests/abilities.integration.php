<?php
/** Integration contracts: run only on a disposable local WordPress installation. */
if ( ! defined( 'BOARDINO_TEST_SITE' ) || true !== BOARDINO_TEST_SITE ) { throw new RuntimeException( 'Use a disposable local WordPress test site.' ); }
require_once '/wordpress/wp-load.php';
$checks = 0;
function bi_assert( $condition, $message ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function bi_call( $slug, $input = array() ) {
    $result = wp_get_ability( 'boardino/' . $slug )->execute( $input );
    if ( is_wp_error( $result ) ) { throw new RuntimeException( $slug . ': ' . $result->get_error_message() ); }
    return $result;
}
function bi_error( $slug, $input, $status = null ) {
    $before = Boardino::board();
    $result = wp_get_ability( 'boardino/' . $slug )->execute( $input );
    bi_assert( is_wp_error( $result ), $slug . ' should fail.' );
    if ( null !== $status ) {
        if ( 403 === $status ) { bi_assert( in_array( $result->get_error_code(), array( 'boardino_forbidden', 'ability_invalid_permissions' ), true ), $slug . ' denies permission.' ); }
        else { bi_assert( ( $result->get_error_data()['status'] ?? 0 ) === $status, $slug . ' error status: ' . wp_json_encode( $result ) ); }
    }
    bi_assert( Boardino::board() === $before, $slug . ' failure must preserve cards, settings, comments counts, and revision.' );
    return $result;
}
wp_set_current_user( 1 );
$names = array( 'get-board', 'list-cards', 'get-card', 'create-card', 'update-card', 'move-card', 'archive-card', 'restore-card', 'add-comment', 'add-column', 'create-category', 'update-category' );
foreach ( $names as $name ) {
    $ability = wp_get_ability( 'boardino/' . $name );
    bi_assert( $ability instanceof WP_Ability, $name . ' registered via the core API.' );
    bi_assert( $ability->get_category() === 'boardino', 'Own category.' );
    bi_assert( $ability->get_meta()['mcp']['public'] === true, 'MCP discovery opted in.' );
    bi_assert( $ability->get_meta()['show_in_rest'] === true, 'Core REST execution opted in.' );
    bi_assert( $ability->get_input_schema()['additionalProperties'] === false, 'Undocumented input rejected.' );
    bi_assert( $ability->get_output_schema()['type'] === 'object', 'Structured output schema.' );
}
$member = wp_insert_user( array( 'user_login' => 'boardino-member', 'user_pass' => 'local-test-only', 'role' => 'subscriber', 'display_name' => 'Board member' ) );
$outsider = wp_insert_user( array( 'user_login' => 'boardino-outsider', 'user_pass' => 'local-test-only', 'role' => 'editor' ) );
$settings = Boardino::settings(); $settings['roles'] = array( 'administrator', 'subscriber' ); $settings['folder_id'] = 999;
update_option( Boardino::OPTION, $settings );
$inputs = array(
    'get-board' => array(), 'list-cards' => array(), 'get-card' => array( 'id' => 99999 ),
    'create-card' => array( 'title' => 'Denied' ), 'update-card' => array( 'id' => 99999, 'version' => 1, 'title' => 'Denied' ),
    'move-card' => array( 'id' => 99999, 'column' => 'todo', 'revision' => 1 ),
    'archive-card' => array( 'id' => 99999, 'version' => 1 ), 'restore-card' => array( 'id' => 99999, 'version' => 1 ),
    'add-comment' => array( 'id' => 99999, 'text' => 'Denied' ), 'add-column' => array( 'name' => 'Denied', 'revision' => 1 ),
    'create-category' => array( 'name' => 'Denied' ),
    'update-category' => array( 'id' => 'denied', 'name' => 'Denied', 'color' => 'purple', 'previous_name' => 'Denied', 'previous_color' => 'purple' ),
);
foreach ( array( 0, $outsider ) as $user ) {
    wp_set_current_user( $user );
    foreach ( $inputs as $slug => $input ) { bi_error( $slug, $input, 403 ); }
}
wp_set_current_user( $member );
$board = bi_call( 'get-board' );
bi_assert( ! isset( $board['members'], $board['folder_id'], $board['settings'] ), 'Only board-facing settings exposed.' );
$assignable = array_column( $board['users'], 'id' ); sort( $assignable );
bi_assert( $assignable === array( 1, $member ), 'Only assignable users returned.' );
bi_assert( ! isset( $board['users'][0]['user_email'] ), 'No user emails.' );
bi_error( 'add-column', array( 'name' => 'Denied', 'revision' => $board['revision'] ), 403 );
bi_error( 'get-board', array( 'unexpected' => true ) );
bi_error( 'create-card', array( 'title' => array( 'invalid' ) ) );
bi_error( 'create-card', array( 'title' => 'Test', 'unexpected' => true ) );
bi_error( 'create-card', array( 'title' => str_repeat( 'x', 201 ) ) );
bi_error( 'create-card', array( 'title' => '  ' ), 400 );
bi_error( 'create-card', array( 'title' => 'Test', 'column' => 'missing' ), 400 );
bi_error( 'create-card', array( 'title' => 'Test', 'category' => 'missing' ), 400 );
bi_error( 'create-card', array( 'title' => 'Test', 'assignee' => $outsider ), 400 );
$category = bi_call( 'create-category', array( 'name' => 'Priority', 'color' => '#edae3a' ) );
bi_error( 'create-category', array( 'name' => 'Priority' ), 400 );
bi_error( 'create-category', array( 'name' => 'Invalid', 'color' => 'not-a-color' ), 400 );
$a = bi_call( 'create-card', array( 'title' => '<b>First</b>', 'description' => 'Keep this description', 'category' => $category['id'], 'assignee' => $member ) );
bi_assert( $a['title'] === 'First' && $a['column'] === 'todo' && $a['version'] === 1, 'Sanitized title and To do default.' );
$b = bi_call( 'create-card', array( 'title' => 'Second', 'column' => 'doing' ) );
$c = bi_call( 'create-card', array( 'title' => 'Third', 'column' => 'doing' ) );
bi_assert( $b['description'] === '' && $b['assignee'] === 0 && $b['category'] === '', 'Optional creation fields have defaults.' );
$list = bi_call( 'list-cards', array( 'limit' => 1, 'offset' => 1 ) );
bi_assert( $list['total'] === 3 && count( $list['cards'] ) === 1 && $list['cards'][0]['id'] === $a['id'], 'Pagination and stable order.' );
bi_assert( bi_call( 'list-cards', array( 'search' => 'DESCRIPTION' ) )['total'] === 1, 'Case-insensitive description search.' );
bi_assert( bi_call( 'list-cards', array( 'column' => 'doing', 'category' => '', 'assignee' => 0 ) )['total'] === 2, 'Combined filters include empty category and unassigned.' );
bi_assert( bi_call( 'list-cards', array( 'assignee' => '0', 'archived' => 'false' ) )['total'] === 2, 'Normalize scalar strings accepted by core schemas before filtering.' );
bi_assert( bi_call( 'list-cards', array( 'category' => $category['id'], 'assignee' => $member ) )['total'] === 1, 'Category and assignee filtering.' );
bi_error( 'list-cards', array( 'limit' => 101 ) );
bi_error( 'list-cards', array( 'offset' => -1 ) );
bi_error( 'list-cards', array( 'column' => 'missing' ), 400 );
bi_error( 'get-card', array( 'id' => 99999 ), 404 );
$ordinary = wp_insert_post( array( 'post_title' => 'Ordinary post', 'post_status' => 'publish' ) );
bi_error( 'get-card', array( 'id' => $ordinary ), 404 );
bi_error( 'update-card', array( 'id' => $a['id'], 'version' => $a['version'] ), 400 );
$edited = bi_call( 'update-card', array( 'id' => $a['id'], 'version' => $a['version'], 'title' => 'Changed' ) );
bi_assert( $edited['description'] === $a['description'] && $edited['category'] === $a['category'] && $edited['assignee'] === $member, 'Partial update preserves omitted fields.' );
bi_assert( $edited['version'] === 2, 'Version increment.' );
bi_error( 'update-card', array( 'id' => $a['id'], 'version' => 1, 'title' => 'Stale' ), 409 );
bi_error( 'update-card', array( 'id' => $a['id'], 'version' => 2, 'archived' => true ) );
bi_error( 'update-card', array( 'id' => $a['id'], 'version' => 2, 'description' => str_repeat( 'x', 20001 ) ) );
$cleared = bi_call( 'update-card', array( 'id' => $a['id'], 'version' => 2, 'description' => '', 'category' => '', 'assignee' => 0 ) );
bi_assert( $cleared['description'] === '' && $cleared['category'] === '' && $cleared['assignee'] === 0, 'Explicit empty values clear fields.' );
$revision = bi_call( 'get-board' )['revision'];
bi_error( 'move-card', array( 'id' => $a['id'], 'column' => 'doing', 'revision' => $revision - 1 ), 409 );
bi_error( 'move-card', array( 'id' => $a['id'], 'column' => 'doing', 'revision' => $revision, 'position' => 99 ), 400 );
bi_error( 'move-card', array( 'id' => $a['id'], 'column' => 'missing', 'revision' => $revision ), 400 );
bi_call( 'move-card', array( 'id' => $a['id'], 'column' => 'doing', 'revision' => $revision, 'position' => 1 ) );
$list = bi_call( 'list-cards', array( 'column' => 'doing' ) );
bi_assert( array_column( $list['cards'], 'id' ) === array( $c['id'], $a['id'], $b['id'] ), 'Insert between destination cards without losing any.' );
bi_call( 'move-card', array( 'id' => $a['id'], 'column' => 'doing', 'revision' => $list['revision'] ) );
$list = bi_call( 'list-cards', array( 'column' => 'doing' ) );
bi_assert( array_column( $list['cards'], 'id' ) === array( $c['id'], $b['id'], $a['id'] ), 'Same-column move appends by default.' );
bi_error( 'add-comment', array( 'id' => $a['id'] ), 400 );
bi_error( 'add-comment', array( 'id' => $a['id'], 'text' => 'Spoof', 'user_id' => 1 ) );
$commented = bi_call( 'add-comment', array( 'id' => $a['id'], 'text' => '<b>Hello</b>' ) );
bi_assert( $commented['comments'][0]['text'] === 'Hello' && $commented['comments'][0]['userId'] === $member, 'Comment sanitized and attributed to authenticated user.' );
$attachment = wp_insert_attachment( array( 'post_title' => 'Accessible image', 'post_mime_type' => 'image/png', 'post_status' => 'private', 'post_author' => $member ) );
update_post_meta( $attachment, '_wp_attached_file', 'boardino-test.png' );
$inaccessible = wp_insert_attachment( array( 'post_title' => 'Private image', 'post_mime_type' => 'image/png', 'post_status' => 'private', 'post_author' => 1 ) );
update_post_meta( $inaccessible, '_wp_attached_file', 'private-test.png' );
bi_error( 'add-comment', array( 'id' => $a['id'], 'images' => array( $ordinary ) ), 400 );
bi_error( 'add-comment', array( 'id' => $a['id'], 'images' => array( $inaccessible ) ), 400 );
$with_image = bi_call( 'add-comment', array( 'id' => $a['id'], 'images' => array( $attachment ) ) );
bi_assert( $with_image['card']['images'] === 1 && $with_image['comments'][1]['images'][0]['id'] === $attachment, 'Accessible existing image can be attached without upload.' );
bi_assert( ! get_post_meta( $attachment, '_bi_card', true ), 'Selected existing image is not moved or owned by the board.' );
$multi = bi_call( 'create-card', array( 'title' => 'Multiple assignees and task images', 'assignees' => array( 1, $member ), 'images' => array( $attachment ) ) );
bi_assert( $multi['assignees'] === array( 1, $member ) && count( $multi['assigneeUsers'] ) === 2 && $multi['taskImages'][0]['id'] === $attachment, 'Multiple assignees and task images exposed through validated ability schemas.' );
bi_assert( bi_call( 'list-cards', array( 'assignee' => $member ) )['total'] === 1, 'Filter matches any person assigned to a card.' );
$multi_edited = bi_call( 'update-card', array( 'id' => $multi['id'], 'version' => $multi['version'], 'title' => 'Keep people and task images' ) );
bi_assert( $multi_edited['assignees'] === $multi['assignees'] && $multi_edited['taskImages'] === $multi['taskImages'], 'Partial update keeps multiple assignees and task images.' );
bi_error( 'update-card', array( 'id' => $multi['id'], 'version' => $multi_edited['version'], 'assignee' => 0, 'assignees' => array() ), 400 );
$multi_cleared = bi_call( 'update-card', array( 'id' => $multi['id'], 'version' => $multi_edited['version'], 'assignees' => array(), 'images' => array() ) );
bi_assert( $multi_cleared['assignees'] === array() && $multi_cleared['taskImages'] === array() && get_post( $attachment ) instanceof WP_Post, 'Explicit clearing preserves the media file.' );
wp_delete_post( $multi['id'], true );
$archived = bi_call( 'archive-card', array( 'id' => $a['id'], 'version' => $with_image['card']['version'] ) );
bi_assert( $archived['archived'] && bi_call( 'list-cards' )['total'] === 2 && bi_call( 'list-cards', array( 'archived' => true ) )['total'] === 1, 'Archive filters active and archived lists.' );
bi_assert( count( bi_call( 'get-card', array( 'id' => $a['id'] ) )['comments'] ) === 2, 'Archived comments remain readable.' );
bi_error( 'update-card', array( 'id' => $a['id'], 'version' => $archived['version'], 'title' => 'No' ), 400 );
bi_error( 'move-card', array( 'id' => $a['id'], 'column' => 'todo', 'revision' => bi_call( 'get-board' )['revision'] ), 400 );
bi_error( 'add-comment', array( 'id' => $a['id'], 'text' => 'No' ), 400 );
bi_error( 'restore-card', array( 'id' => $a['id'], 'version' => $archived['version'] - 1 ), 409 );
$restored = bi_call( 'restore-card', array( 'id' => $a['id'], 'version' => $archived['version'] ) );
bi_assert( ! $restored['archived'] && $restored['comments'] === 2 && $restored['images'] === 1, 'Restoring preserves conversation and images.' );
$edit_category = array( 'id' => $category['id'], 'name' => 'Urgent', 'color' => 'rose', 'previous_name' => 'Priority', 'previous_color' => '#edae3a' );
bi_call( 'update-category', $edit_category );
bi_error( 'update-category', $edit_category, 409 );
wp_set_current_user( 1 );
$before = Boardino::settings(); $revision = bi_call( 'get-board' )['revision'];
$column = bi_call( 'add-column', array( 'name' => 'Review', 'revision' => $revision ) );
$after = Boardino::settings(); $after['columns'] = $before['columns'];
bi_assert( $before === $after, 'Adding a column preserves members, folder, categories and existing columns.' );
bi_error( 'add-column', array( 'name' => 'Stale', 'revision' => $revision ), 409 );
bi_error( 'add-column', array( 'name' => str_repeat( 'x', 61 ), 'revision' => $column['revision'] ) );
$custom = bi_call( 'create-card', array( 'title' => 'Custom column task', 'column' => $column['column']['id'] ) );
bi_assert( $custom['column'] === $column['column']['id'], 'Custom column is usable via abilities.' );
add_option( 'boardino_write_lock', array( 'token' => 'other-writer', 'time' => time() ) );
bi_error( 'create-card', array( 'title' => 'Locked' ), 409 );
bi_assert( get_option( 'boardino_write_lock' )['token'] === 'other-writer', 'Do not steal another writer’s lock.' );
delete_option( 'boardino_write_lock' );
// Exercise the actual core REST abilities route and permission enforcement.
$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/boardino/get-board/run' );
$request->set_param( 'input', array() );
wp_set_current_user( $member );
$response = rest_do_request( $request );
bi_assert( $response->get_status() === 200 && $response->get_data()['name'] === 'My board', 'Core ability REST execution.' );
wp_set_current_user( $outsider );
$response = rest_do_request( $request );
bi_assert( $response->get_status() === 403, 'Core ability REST rejects non-member.' );
wp_set_current_user( 1 );
// Optional official MCP Adapter integration, installed only on this test site.
if ( wp_get_ability( 'mcp-adapter/discover-abilities' ) ) {
    $discovered = wp_get_ability( 'mcp-adapter/discover-abilities' )->execute();
    bi_assert( ! is_wp_error( $discovered ), 'Official adapter discovery executes: ' . wp_json_encode( $discovered ) );
    $found = array_column( $discovered['abilities'], 'name' );
    foreach ( $names as $name ) { bi_assert( in_array( 'boardino/' . $name, $found, true ), 'Official adapter discovers ' . $name ); }
    $proxy = wp_get_ability( 'mcp-adapter/execute-ability' );
    $proxied = $proxy->execute( array( 'ability_name' => 'boardino/get-board', 'parameters' => array() ) );
    bi_assert( $proxied['success'] && $proxied['data']['name'] === 'My board', 'Official adapter executes a Boardino ability.' );
    wp_set_current_user( $member );
    $proxied = $proxy->execute( array( 'ability_name' => 'boardino/create-card', 'parameters' => array( 'title' => 'MCP created' ) ) );
    bi_assert( ! is_wp_error( $proxied ) && $proxied['success'] && $proxied['data']['column'] === 'todo', 'Official adapter creates a card as a board member: ' . wp_json_encode( $proxied ) );
    $proxied = $proxy->execute( array( 'ability_name' => 'boardino/add-column', 'parameters' => array( 'name' => 'Denied', 'revision' => bi_call( 'get-board' )['revision'] ) ) );
    bi_assert( is_wp_error( $proxied ) || ! $proxied['success'], 'Official adapter enforces administrator-only columns.' );
    wp_set_current_user( $outsider );
    $proxied = $proxy->execute( array( 'ability_name' => 'boardino/get-board', 'parameters' => array() ) );
    bi_assert( is_wp_error( $proxied ) || ! $proxied['success'], 'Official adapter rejects non-members.' );
    wp_set_current_user( 1 );
}
// The board may legitimately have no To do column after an administrator edits settings.
$before = Boardino::settings(); $without_todo = $before;
$without_todo['columns'] = array_values( array_filter( $without_todo['columns'], static fn( $column ) => 'todo' !== $column['id'] ) );
update_option( Boardino::OPTION, $without_todo );
$fallback = bi_call( 'create-card', array( 'title' => 'First available column' ) );
bi_assert( $fallback['column'] === $without_todo['columns'][0]['id'], 'Use first column if To do no longer exists.' );
update_option( Boardino::OPTION, $before );
bi_assert( get_option( 'boardino_write_lock', false ) === false, 'All writes release their lock.' );
echo 'PASS: ' . $checks . " local WordPress ability checks\n";
