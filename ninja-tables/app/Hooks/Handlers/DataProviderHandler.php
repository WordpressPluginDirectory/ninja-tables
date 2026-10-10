<?php

namespace NinjaTables\App\Hooks\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Modules\DataProviders\DefaultProvider;
use NinjaTables\Framework\Foundation\Application;

class DataProviderHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function register()
    {
        add_action('init', [$this, 'handle']);
    }

    public function handle()
    {
        $this->app->make(DefaultProvider::class)->register();
    }
}
