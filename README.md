# Boardino

A simple Trello-style task board inside WordPress.

Draggable cards, category bubbles, selected WordPress users, comments, and images. Administrators can add columns directly on the board; rename, reorder, and remove empty columns in Settings. Card details open in an overlapping side panel and save when you close it. Folderino is optional for organising new comment images.

## Install

Download `boardino.zip` from a release and upload it through **Plugins → Add Plugin → Upload Plugin**, then activate Boardino. Select your team in **Boardino → Settings**.

Requires WordPress 6.4+ and PHP 8.0+.

## Updates

Boardino checks published stable GitHub releases through the normal WordPress update system. Use **Plugins → Boardino → Check for updates**, or **Boardino → Settings → Check for updates**. The plugin-row action refreshes the release check and WordPress update list, then shows whether an update is available; it does not install the update. No GitHub token is needed. Automatic updates follow your WordPress settings.

Updates preserve cards, comments, settings, and media. Deactivation and uninstall also preserve board data.

## AI agents and MCP

Boardino registers twelve abilities through the official WordPress Abilities API on WordPress 6.9+. On older WordPress versions, the ordinary board still works and the abilities stay inactive. Boardino does not bundle an MCP server: use the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) or another connector that discovers registered WordPress abilities. Authenticate the connector as a WordPress user with board access.

All abilities declare input/output schemas, a `boardino` category, core annotations, `meta.show_in_rest`, and `meta.mcp.public`. Public exposure enables discovery; it does **not** allow anonymous board access. Board administrators and selected members retain the same permissions as the interface. Adding columns requires `manage_options`. No additional users, media permissions, or tokens are created.

| Ability | Action |
| --- | --- |
| `boardino/get-board` | Board name, columns, categories, assignable users, and revision |
| `boardino/list-cards` | Paginated cards; filter by column, category, assignee, archive, or text |
| `boardino/get-card` | One card with current version, comments, and image details |
| `boardino/create-card` | Create; defaults to To do, or the first column if To do was removed |
| `boardino/update-card` | Partial update; omitted fields remain unchanged |
| `boardino/move-card` | Move to a column and optional zero-based position; otherwise append |
| `boardino/archive-card` | Recoverable archive; preserves comments and media |
| `boardino/restore-card` | Restore to the end of its column |
| `boardino/add-comment` | Comment as the authenticated user; optionally attach accessible existing image IDs |
| `boardino/add-column` | Administrator only: append a column |
| `boardino/create-category` | Create a named category with a preset or hex color |
| `boardino/update-category` | Rename or recolor, with previous values to detect conflicting edits |

Start with `get-board` to obtain IDs, then `list-cards` or `get-card`. Updates, archive and restore require the card’s latest `version`; move and add-column require the latest board `revision`. After a conflict, read again and reassess rather than blindly retrying. No permanent deletion or remote image uploads are exposed. Creation and comments are not idempotent: retrying a completed request creates another record.

For example, call `boardino/create-card` with `{"title":"Review homepage"}`, then `boardino/update-card` with `{"id":123,"version":1,"description":"Check mobile spacing"}` using the ID and version actually returned by creation. Use `category: ""`, `description: ""`, or `assignee: 0` to clear those fields. Existing Media Library images attached to comments keep their original folder.

## Local integration tests

Run `npm ci` and `npm run test:abilities` with Node.js 20.18+ (or newer). The runner boots a disposable local WordPress Playground instance, installs the official MCP Adapter, and checks real abilities registration, schemas, permissions, card workflows, media access, conflicts, core REST execution, adapter discovery/execution, and the plugin-row update link, feedback, permissions and package validation. It never connects to your live WordPress site. The first run downloads WordPress, PHP WebAssembly, and the adapter from official sources. Set `BI_TEST_WP` and `BI_TEST_PHP` to test another supported combination, for example `BI_TEST_WP=latest BI_TEST_PHP=8.3 npm run test:abilities`.

## Publish a release

1. Update the version in `boardino.php` (header and `BOARDINO_VERSION`) and the stable tag/changelog in `readme.txt`.
2. Commit and push the plugin files.
3. Publish a non-draft GitHub release with a matching tag, for example `v0.1.8`. Pre-releases are ignored.
4. Attach a WordPress-installable ZIP containing one `boardino/` folder for first-time installations.

The updater resolves the release tag to its commit and installs that immutable source archive. It verifies the plugin version and required files, and preserves the `boardino/` installation directory.

## License

GPL-2.0-or-later. The bundled Lucide icons retain their ISC license in `assets/lucide-LICENSE.txt`.
