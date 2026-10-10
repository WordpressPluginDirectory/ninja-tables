<?php

namespace NinjaTables\App\Http\Policies;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\Framework\Http\Request\Request;
use NinjaTables\Framework\Foundation\Policy;

/**
 * Gate for every admin REST route: the configured Ninja Tables admin capability.
 */
class AdminPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return current_user_can(ninja_table_admin_role());
    }
}
