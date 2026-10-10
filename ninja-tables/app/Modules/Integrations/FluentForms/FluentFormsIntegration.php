<?php

namespace NinjaTables\App\Modules\Integrations\FluentForms;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Modules\DataProviders\FluentFormProvider;
use NinjaTables\App\Modules\Integrations\BaseIntegration;

/**
 * Fluent Forms as a data source. The provider hooks are registered unconditionally
 * (as before) so a table keeps its provider settings even while Fluent Forms is inactive.
 */
class FluentFormsIntegration extends BaseIntegration
{
    public function register()
    {
        add_action('init', function () {
            (new FluentFormProvider())->register();
        });
    }
}
