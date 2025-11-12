=== SQLite Database Integration ===

Contributors:      wordpressdotorg, aristath, janjakes, zieladam, berislav.grgicak, bpayton, zaerl
Requires at least: 6.4
Tested up to:      6.6.1
Requires PHP:      7.2
Stable tag:        2.2.14
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Tags:              performance, database

SQLite integration plugin by the WordPress Team.

== Description ==

The SQLite plugin is a community, feature plugin. The intent is to allow testing an SQLite integration with WordPress and gather feedback, with the goal of eventually landing it in WordPress core.

This feature plugin includes code from the PHPMyAdmin project (specifically parts of the PHPMyAdmin/sql-parser library), licensed under the GPL v2 or later. More info on the PHPMyAdmin/sql-parser library can be found on [GitHub](https://github.com/phpmyadmin/sql-parser).

== Frequently Asked Questions ==

= What is the purpose of this plugin? =

The primary purpose of the SQLite plugin is to allow testing the use of an SQLite database, with the goal to eventually land in WordPress core.

You can read the original proposal on the [Make blog](https://make.wordpress.org/core/2022/09/12/lets-make-wordpress-officially-support-sqlite/), as well as the [call for testing](https://make.wordpress.org/core/2022/12/20/help-us-test-the-sqlite-implementation/) for more context and useful information.

= Can I use this plugin on my production site? =

Per the primary purpose of the plugin (see above), it can mostly be considered a beta testing plugin. To a degree, it should be okay to use it in production. However, as with every plugin, you are doing so at your own risk.

= Where can I submit my plugin feedback? =

Feedback is encouraged and much appreciated, especially since this plugin is a future WordPress core feature. If you need help with troubleshooting or have a question, suggestions, or requests, you can [submit them as an issue in the SQLite GitHub repository](https://github.com/wordpress/sqlite-database-integration/issues/new).

= How can I contribute to the plugin? =

Contributions are always welcome! Learn more about how to get involved in the [Core Performance Team Handbook](https://make.wordpress.org/performance/handbook/get-involved/).

= How do I use Cloudflare D1 with this plugin? =

The plugin supports Cloudflare D1, Cloudflare's cloud-native SQLite database. To use D1 instead of a local SQLite file, add the following to your wp-config.php:

`define('DB_ENGINE', 'sqlite');
define('WP_SQLITE_AST_DRIVER', true);
define('SQLITE_D1_ENABLE', true);
define('SQLITE_D1_ACCOUNT_ID', 'your-cloudflare-account-id');
define('SQLITE_D1_DATABASE_ID', 'your-d1-database-id');
define('SQLITE_D1_API_TOKEN', 'your-cloudflare-api-token');`

You can find your Cloudflare account ID and D1 database ID in the Cloudflare dashboard. The API token should have D1 read and write permissions.

Optionally, you can specify a custom API URL:

`define('SQLITE_D1_API_URL', 'https://api.cloudflare.com/client/v4');`

This allows WordPress to run on Cloudflare Workers with a D1 database backend, enabling serverless WordPress deployments.
