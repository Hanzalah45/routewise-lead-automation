<?php

namespace App\Services\Crm;

use App\Models\Lead;

/** What the sync service needs from any CRM. Adapters: CrmClient (Mock CRM), HubSpotGateway, PipedriveGateway. */
interface CrmGateway
{
    /**
     * Creates or updates the contact for this lead (matched by email) and returns the CRM's contact id.
     *
     * @throws CrmTransientException|CrmPermanentException
     */
    public function upsertContact(Lead $lead): string;

    /**
     * Opens a deal for the contact and returns the CRM's deal id.
     *
     * @throws CrmTransientException|CrmPermanentException
     */
    public function createDeal(Lead $lead, string $contactId): string;
}
