<?php

namespace App\Services\Crm;

/** Worth retrying: rate limit, 5xx, timeout, connection failure. */
class CrmTransientException extends CrmException {}
