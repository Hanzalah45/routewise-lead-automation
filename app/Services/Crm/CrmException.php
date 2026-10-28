<?php

namespace App\Services\Crm;

use RuntimeException;

/** Base type: something went wrong talking to the CRM. */
abstract class CrmException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
