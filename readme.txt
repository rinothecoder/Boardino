=== Boardino ===
Contributors: rinodeboer
Tags: kanban, board, tasks, comments
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.15
License: GPLv2 or later

A simple shared board inside WordPress.

== Description ==
One board with draggable cards, custom columns, one category and multiple assignees per card, comments, and image attachments. A card opens in an overlapping side panel. Existing card edits save when the panel closes. Inline category editing, an image lightbox, and the WordPress media selector keep the work inside the board. Select WordPress user roles in Settings to control board access and assignment options. Administrators always retain access to Settings.

Task and comment images use the standard Media Library. Add up to 10 task images below the description; these appear inside the task only. Folderino is optional: when active, choose a media folder in Boardino Settings. On a fresh installation with Folderino active, a Boards folder is created or reused. Without Folderino, images still upload normally. Changing the folder affects new uploads only.

Cards and comments stay in WordPress. Deactivating Boardino preserves all data. Archived cards can be restored. No data is removed on uninstall.

== Installation ==
1. Upload boardino.zip through Plugins > Add Plugin > Upload Plugin.
2. Activate Boardino.
3. Open Boardino > Settings to select user roles and categories.
4. Open the board and add your first card.

== Changelog ==
= 0.1.15 =
Close the panel after card creation and put new cards first. Show current WordPress avatars, support multiple assignees, and add task images independently from comments. Use compact wrapping people choices and role-based access settings. Simplify card metadata and remove Status and No category controls from card details. Keep existing individual access until roles are first saved. Preserve the public updater and MCP abilities, including support for multiple assignees and task images.

= 0.1.13 =
Add Check for updates to the WordPress plugin row, with a clear result after checking. Includes the MCP abilities introduced in 0.1.12.

= 0.1.12 =
Add WordPress Abilities API tools for cards, comments, columns, and categories, with existing board permissions and concurrency checks. Requires WordPress 6.9+ for abilities; the ordinary board remains compatible with WordPress 6.4+.

= 0.1.11 =
Give Add column the same subtle background and border as the board columns.

= 0.1.10 =
Add columns directly on the board. New columns are immediately available for cards and status selection.

= 0.1.9 =
Use the canonical lowercase GitHub repository name for updates.

= 0.1.8 =
Make the manual Check for updates button work alongside the board forms.

= 0.1.7 =
GitHub release updates and a manual check in Boardino Settings.

= 0.1.6 =
Compact card details, categories, comments, media uploads, and optional Folderino integration.
