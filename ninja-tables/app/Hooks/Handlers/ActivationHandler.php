<?php

namespace NinjaTables\App\Hooks\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\Framework\Foundation\Application;
use NinjaTables\Database\DBMigrator;

class ActivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle($networkWide = false)
    {
        DBMigrator::run($networkWide);
    }
}
