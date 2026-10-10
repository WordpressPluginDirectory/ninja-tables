<?php

namespace NinjaTables\App\Modules\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A third-party plugin integration. IntegrationsInit calls register() only when isAvailable().
 */
abstract class BaseIntegration
{
    /**
     * Whether the host plugin/feature is present.
     */
    public function isAvailable()
    {
        return true;
    }

    abstract public function register();
}
