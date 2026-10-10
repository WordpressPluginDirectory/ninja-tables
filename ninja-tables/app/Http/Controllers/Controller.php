<?php

namespace NinjaTables\App\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\Framework\Http\Controller as BaseController;

abstract class Controller extends BaseController
{
    const TABLE_POST_TYPE = 'ninja-table';

    /**
     * Whether the id belongs to a Ninja Tables post.
     */
    protected function isNinjaTable($id)
    {
        return get_post_type((int) $id) === static::TABLE_POST_TYPE;
    }

    /**
     * Send the standard "invalid table" error (HTTP 423, matching the legacy API) and return it,
     * or null when the table is valid. Usage: if ($e = $this->assertNinjaTable($id)) return $e;
     */
    protected function assertNinjaTable($id, $message = null, $extra = [])
    {
        if ($this->isNinjaTable($id)) {
            return null;
        }

        return $this->sendError(array_merge([
            'message' => $message ?: __('Invalid table.', 'ninja-tables'),
        ], $extra), 423);
    }
}
