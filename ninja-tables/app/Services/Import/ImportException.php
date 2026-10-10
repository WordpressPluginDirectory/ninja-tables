<?php

namespace NinjaTables\App\Services\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Carries the exact HTTP payload/status an import service wants the controller to send.
 */
class ImportException extends \RuntimeException
{
    protected $payload;
    protected $status;
    protected $plainJson;

    /**
     * @param array $payload   Response body
     * @param int   $status    HTTP status
     * @param bool  $plainJson true = Controller::json(), false = Controller::sendError()
     */
    public function __construct(array $payload, $status = 422, $plainJson = false)
    {
        parent::__construct(is_string($payload['message'] ?? null) ? $payload['message'] : 'Import failed', $status);
        $this->payload   = $payload;
        $this->status    = $status;
        $this->plainJson = $plainJson;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function isPlainJson()
    {
        return $this->plainJson;
    }
}
