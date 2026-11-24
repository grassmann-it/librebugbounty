<?php

declare(strict_types=1);
namespace App\Service;

interface GmailDraftGatewayInterface
{
    public function configured(): bool;
    public function submit(array $request): array;
    public function reconcile(array $request): array;
}
