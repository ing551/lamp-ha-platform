<?php
define('DB_NAME', 'wordpress');
define('DB_USER', 'wpuser');
define('DB_PASSWORD', '123456');
define('DB_HOST', 'mysql');
define('DB_CHARSET', 'utf8mb4');

define('AUTH_KEY',         'docker-lamp-project');
define('SECURE_AUTH_KEY',  'docker-lamp-project');
define('LOGGED_IN_KEY',    'docker-lamp-project');
define('NONCE_KEY',        'docker-lamp-project');
define('AUTH_SALT',        'docker-lamp-project');
define('SECURE_AUTH_SALT', 'docker-lamp-project');
define('LOGGED_IN_SALT',   'docker-lamp-project');
define('NONCE_SALT',       'docker-lamp-project');

$table_prefix = 'wp_';
define('WP_DEBUG', false);

# Redis 缓存
define('WP_REDIS_HOST', 'redis');
define('WP_REDIS_PORT', 6379);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
