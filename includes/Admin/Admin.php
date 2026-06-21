<?php
declare(strict_types=1);

namespace Kitgenix\OrderTracking\Admin;

defined('ABSPATH') || exit;

final class Admin
{
    public static function init(): void
    {
        Order_Meta_Box::init();
        AdminModule::init();
    }
}
