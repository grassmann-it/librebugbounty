<?php

namespace App\Exception;

final class BrowserScreenshotCaptureException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $observation
     */
    public function __construct(
        string $message,
        public readonly ?array $observation = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $finalUrl = null,
        public readonly bool $dialogSeen = false,
        public readonly ?string $dialogType = null,
        public readonly ?string $dialogText = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
