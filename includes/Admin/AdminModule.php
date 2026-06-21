<?php
declare(strict_types=1);

namespace Kitgenix\OrderTracking\Admin;

defined('ABSPATH') || exit;

// Keep the implementation in Analytics.php for backwards compatibility,
// but expose it via the new AdminModule class name.
require_once __DIR__ . '/Analytics.php';

if ( ! class_exists( __NAMESPACE__ . '\\AdminModule', false ) ) {
    class_alias( Analytics::class, __NAMESPACE__ . '\\AdminModule' );
}
