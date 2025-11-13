<?php
/**
 * Example configuration for using Cloudflare D1 with WordPress.
 *
 * This file demonstrates how to configure WordPress to use Cloudflare D1
 * instead of a local SQLite file. Add these constants to your wp-config.php.
 *
 * @package wp-sqlite-integration
 * @since 3.0.0
 */

/*
 * ==========================================================================
 * Cloudflare D1 Configuration Example
 * ==========================================================================
 *
 * To use Cloudflare D1 with this WordPress installation, add the following
 * constants to your wp-config.php file.
 *
 * REQUIREMENTS:
 * - PHP cURL extension (ext-curl) must be enabled
 * - SQLite AST driver must be enabled
 */

// Enable SQLite database engine.
define( 'DB_ENGINE', 'sqlite' );

// Enable the new SQLite AST driver (required for D1).
define( 'WP_SQLITE_AST_DRIVER', true );

// Enable Cloudflare D1 mode.
define( 'SQLITE_D1_ENABLE', true );

// Your Cloudflare account ID (found in the Cloudflare dashboard).
define( 'SQLITE_D1_ACCOUNT_ID', 'your-cloudflare-account-id' );

// Your D1 database ID (found in the D1 dashboard).
define( 'SQLITE_D1_DATABASE_ID', 'your-d1-database-id' );

// Your Cloudflare API token with D1 read/write permissions.
// Create this at: https://dash.cloudflare.com/profile/api-tokens
define( 'SQLITE_D1_API_TOKEN', 'your-cloudflare-api-token' );

// Optional: Custom Cloudflare API URL (defaults to production URL if not set).
// define( 'SQLITE_D1_API_URL', 'https://api.cloudflare.com/client/v4' );

/*
 * ==========================================================================
 * Getting Your Credentials
 * ==========================================================================
 *
 * 1. Account ID:
 *    - Log in to Cloudflare dashboard
 *    - Select your account
 *    - Copy the Account ID from the right sidebar
 *
 * 2. D1 Database ID:
 *    - Go to Workers & Pages > D1
 *    - Select your database or create a new one
 *    - Copy the Database ID from the database details
 *
 * 3. API Token:
 *    - Go to https://dash.cloudflare.com/profile/api-tokens
 *    - Click "Create Token"
 *    - Use "Edit Cloudflare Workers" template or create custom token
 *    - Include D1 Edit permissions
 *    - Copy the generated token (shown only once!)
 *
 * ==========================================================================
 * Security Best Practices
 * ==========================================================================
 *
 * - Store your API token securely (never commit to version control)
 * - Use environment variables when possible:
 *   define( 'SQLITE_D1_API_TOKEN', getenv('CLOUDFLARE_D1_TOKEN') );
 * - Restrict API token permissions to only D1 operations
 * - Rotate API tokens periodically
 * - Monitor API usage in the Cloudflare dashboard
 *
 * ==========================================================================
 * Benefits of Using D1
 * ==========================================================================
 *
 * - Serverless: No database server to manage or maintain
 * - Global: Low-latency access from Cloudflare's edge network
 * - Scalable: Automatically scales with your traffic
 * - Cost-effective: Pay only for what you use
 * - Compatible: Drop-in replacement for local SQLite
 * - Worker-ready: Perfect for WordPress on Cloudflare Workers
 */
