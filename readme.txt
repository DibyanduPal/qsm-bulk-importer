=== QSM Bulk Importer ===
Contributors: dibyandupal
Tags: qsm, quiz, import, bulk, spreadsheet, excel, csv, json, quiz-and-survey-master
Requires at least: 5.9
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import questions in bulk into Quiz and Survey Master (QSM) from .xlsx, .xls, .csv and .json files. Designed for backend use by site developers and administrators — supports validation (dry-run), detailed logging, and rollback of imports.

== Description ==

QSM Bulk Importer provides a reliable, secure, and standards-compliant way to import large sets of quiz questions into Quiz and Survey Master (QSM). The plugin is built for professional sites that require a deterministic import workflow, full accountability (import logs), and safe undo (rollback) for batch operations.

Key features

Import from .xlsx, .xls, .csv and .json files.

Required headers: question, option1, option2, option3, option4, correct_answer. Optional headers: option5, explanation.

Drag-and-drop or file-select upload in admin.

Map imports to existing QSM quizzes, categories, and question types.

Default scoring values with per-import override (default: +2.00 for correct, -0.67 for incorrect).

Dry-run (validation only) mode — validates file and reports issues without writing to database.

Detailed import logs stored in a plugin table with timestamps, inserted question IDs and per-row errors.

Recent Imports UI with pagination and rollback capability (deletes questions from a specific import batch).

Uses Composer vendor autoload (PhpSpreadsheet supported via vendor/) for Excel support.

Built with WordPress coding standards, security best practices (nonces, capability checks, sanitization), and i18n-ready strings.

Why use this plugin?
If you maintain or migrate large question banks, performing manual entry in the QSM UI is error-prone and slow. This plugin enables repeatable, auditable, and reversible bulk imports that align with WordPress.org plugin guidelines.

== Installation ==

Upload the qsm-bulk-importer folder to the /wp-content/plugins/ directory, or install via the plugin uploader if packaged as a .zip.

Ensure vendor/ (Composer autoload and packages) is present in the plugin folder. Excel (.xlsx/.xls) support requires PhpSpreadsheet available via vendor/.

Activate the plugin through the 'Plugins' screen in WordPress.

Go to the admin menu QSM Bulk Import → Import Questions.

Prepare your import file with the required headers and upload. Option5 and explanation columns are optional.

If this is the first time, use the dry-run option to validate your file; then perform the real import.

Notes:

The plugin uses the active WordPress table prefix (for example wp_ or a custom prefix) and therefore requires no manual SQL changes.

By default the plugin restricts access to users with administrative capabilities. Adjust capability checks only if you wish to widen access to editors or a custom role.

The plugin stores import logs in a custom table named {prefix}qsm_import_logs. This table is created at activation.

== Frequently Asked Questions ==

= Which file formats are supported? =
.xlsx, .xls, .csv, and .json are supported. Excel (.xlsx/.xls) files require PhpSpreadsheet present in the plugin's vendor/ folder.

= What headers are required in the import file? =
At minimum: question, option1, option2, option3, option4, correct_answer. Optionally include option5 and explanation. The correct_answer may be the option number (1–5) or the exact text of the option (case-insensitive match).

= Can I preview imports before they are written to the database? =
Yes. Use the "Validation only (dry run)" checkbox on the Import screen. The plugin will validate every row and produce a report; no database writes will occur.

= How does rollback work? =
Each import is logged with the IDs of inserted questions. The Recent Imports screen exposes a Rollback action that deletes the questions inserted in that batch and marks the log as RolledBack. Use rollback with care — deleted content cannot be recovered by the plugin after rollback.

= Who can use the import screen? =
By default, the importer is restricted to admin-level users (capability checks are in place). The capability function can be adjusted by developers if different permissions are required.

= Do I need to run composer on the server? =
No. For WordPress.org compatibility, include the fully populated vendor/ directory in the plugin package. The plugin will load vendor/autoload.php if present.

= Will this plugin modify my existing QSM data layout? =
No structural changes are made to QSM core tables. The importer writes rows to QSM tables using the active DB prefix and follows the mapping required to create questions and link categories/terms. The plugin’s own log table is separate and does not alter QSM core schemas.

== Screenshots ==

Import Questions admin screen (drag-and-drop area, options)

Import results (summary, error listing, rollback)

Recent Imports list (pagination and actions)

(Include these images in assets/images/ when submitting to WordPress.org. The templates render sensibly without screenshots but including them improves the listing.)

== Changelog ==

= 1.0.0 =

Initial release.

Add import UI (drag-and-drop), support for .xlsx/.xls/.csv/.json.

Dry-run validation mode, detailed import logs, rollback functionality.

PhpSpreadsheet support via vendor/autoload.php.

Templates, admin assets, public assets, and includes follow WordPress.org guidelines.

== Upgrade Notice ==

= 1.0.0 =
Initial stable release. Review release notes and run a dry-run import before performing a full import on production data.

== Tested Environments & Compatibility ==

The plugin is implemented with best practices to work with standard QSM installations. It respects the active WordPress database prefix and is compatible with sites using a custom table prefix. Ensure the QSM plugin is present and functional before importing.

== Installation & Developer Notes (for maintainers) ==

Activation triggers creation of the {prefix}qsm_import_logs table using dbDelta. The SQL template and PHP installer are in sql/.

Admin pages and handlers are located under admin/. Core functionality is in includes/.

Assets are organized under assets/ (admin/ and public/ subfolders) and enqueued conditionally.

The plugin follows i18n patterns; translation files live in languages/.

Unit tests can be added under tests/ if desired.

== Support ==
For support, usage questions, or to report bugs, please open an issue on the plugin repository or contact the author: Dibyandu Pal — https://civilnotes.in

== License ==
This plugin is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License version 2 (or at your option any later version) as published by the Free Software Foundation.

== Credits ==
Developed and maintained by Dibyandu Pal — https://civilnotes.in

=== End of file ===