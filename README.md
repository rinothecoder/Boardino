# Boardino

A simple Trello-style task board inside WordPress.

Draggable cards, category bubbles, selected WordPress users, comments, and images. Card details open in an overlapping side panel and save when you close it. Folderino is optional for organising new comment images.

## Install

Download `boardino.zip` from a release and upload it through **Plugins → Add Plugin → Upload Plugin**, then activate Boardino. Select your team in **Boardino → Settings**.

Requires WordPress 6.4+ and PHP 8.0+.

## Updates

Boardino checks published stable GitHub releases through the normal WordPress update system. You can also use **Boardino → Settings → Check for updates**. No GitHub token is needed. Automatic updates follow your WordPress settings.

Updates preserve cards, comments, settings, and media. Deactivation and uninstall also preserve board data.

## Publish a release

1. Update the version in `boardino.php` (header and `BOARDINO_VERSION`) and the stable tag/changelog in `readme.txt`.
2. Commit and push the plugin files.
3. Publish a non-draft GitHub release with a matching tag, for example `v0.1.8`. Pre-releases are ignored.
4. Attach a WordPress-installable ZIP containing one `boardino/` folder for first-time installations.

The updater resolves the release tag to its commit and installs that immutable source archive. It verifies the plugin version and required files, and preserves the `boardino/` installation directory.

## License

GPL-2.0-or-later. The bundled Lucide icons retain their ISC license in `assets/lucide-LICENSE.txt`.
