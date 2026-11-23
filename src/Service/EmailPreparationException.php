<?php

declare(strict_types=1);
namespace App\Service;

/** Contains only public, fixed messages; never provider responses or case data. */
final class EmailPreparationException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
