<?php

namespace NinjaTables\App\Modules\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Modules\Integrations\FluentCart\FluentCartIntegration;
use NinjaTables\App\Modules\Integrations\FluentCart\ProductComparison;
use NinjaTables\App\Modules\Integrations\FluentForms\FluentFormsIntegration;
use NinjaTables\App\Modules\Integrations\Gutenberg\GutenbergIntegration;

class IntegrationsInit
{
    /** @var string[] */
    protected $integrations = [
        GutenbergIntegration::class,
        FluentFormsIntegration::class,
        FluentCartIntegration::class,
    ];

    public function register()
    {
        foreach ($this->integrations as $class) {
            /** @var BaseIntegration $integration */
            $integration = new $class();

            if ($integration->isAvailable()) {
                $integration->register();
            }
        }

        // Product comparison hooks are provider-scoped (wp_fct) and were always registered.
        (new ProductComparison())->register();
    }
}
