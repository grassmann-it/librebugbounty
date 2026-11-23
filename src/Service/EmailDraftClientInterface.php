<?php

declare(strict_types=1);
namespace App\Service;

interface EmailDraftClientInterface
{
    public function configured(): bool;
    public function model(): string;
    public function reasoning(): string;
    /** @return array{subject:string, opening:string, closing:string, usage:array} */
    public function generate(array $request): array;
}
