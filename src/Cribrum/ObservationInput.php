<?php

namespace App\Cribrum;

final readonly class ObservationInput
{
    public function __construct(
        public string $url,
        public ?string $rawHtml,
        public ?string $domText,
        public ?string $contentType,
        public ?int $httpStatus,
        public ?string $finalUrl,
        public ?string $payload,
        public ?string $expectedEvidence,
        public bool $dialogSeen,
        public ?string $dialogText,
        public array $coverage = [],
    ) {
        if ($rawHtml !== null && strlen($rawHtml) > 1048576) {
            throw new \InvalidArgumentException('Raw HTML exceeds the Cribrum input limit.');
        }
        if ($domText !== null && strlen($domText) > 524288) {
            throw new \InvalidArgumentException('DOM text exceeds the Cribrum input limit.');
        }
    }

    public static function fromArray(array $data): self
    {
        $string = static function (mixed $value, string $name): ?string {
            if ($value === null) return null;
            if (!is_string($value)) throw new \InvalidArgumentException($name.' must be a string.');
            return $value;
        };
        $status = $data['httpStatus'] ?? null;
        if ($status !== null && (!is_int($status) || $status < 100 || $status > 599)) {
            throw new \InvalidArgumentException('httpStatus is invalid.');
        }

        return new self(
            is_string($data['url'] ?? null) ? $data['url'] : throw new \InvalidArgumentException('url is required.'),
            $string($data['rawHtml'] ?? null, 'rawHtml'),
            $string($data['domText'] ?? null, 'domText'),
            $string($data['contentType'] ?? null, 'contentType'),
            $status,
            $string($data['finalUrl'] ?? null, 'finalUrl'),
            $string($data['payload'] ?? null, 'payload'),
            $string($data['expectedEvidence'] ?? null, 'expectedEvidence'),
            ($data['dialogSeen'] ?? false) === true,
            $string($data['dialogText'] ?? null, 'dialogText'),
            is_array($data['coverage'] ?? []) ? ($data['coverage'] ?? []) : throw new \InvalidArgumentException('coverage must be an array.'),
        );
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'rawHtml' => $this->rawHtml,
            'domText' => $this->domText,
            'contentType' => $this->contentType,
            'httpStatus' => $this->httpStatus,
            'finalUrl' => $this->finalUrl,
            'payload' => $this->payload,
            'expectedEvidence' => $this->expectedEvidence,
            'dialogSeen' => $this->dialogSeen,
            'dialogText' => $this->dialogText,
            ...($this->coverage === [] ? [] : ['coverage' => $this->coverage]),
        ];
    }
}
