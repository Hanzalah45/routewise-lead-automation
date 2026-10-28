<?php

namespace App\Services\Crm;

/** Retrying will not help: bad credentials, rejected data, missing record. A person has to look at it. */
class CrmPermanentException extends CrmException {}
