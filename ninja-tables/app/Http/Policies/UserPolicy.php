<?php

namespace NinjaTables\App\Http\Policies;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kept for backward compatibility; all admin routes now use AdminPolicy.
 */
class UserPolicy extends AdminPolicy
{
    //
}
