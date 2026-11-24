<?php
namespace App\Service;

/** Providers that transmit a domain to a third party only after an explicit acknowledgement. */
interface ExplicitContactDiscoveryProviderInterface extends ContactDiscoveryProviderInterface
{
    public function configured(): bool;
}
