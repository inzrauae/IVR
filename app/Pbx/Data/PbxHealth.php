<?php

namespace App\Pbx\Data;

final class PbxHealth
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly bool $online,
        public readonly ?string $version = null,
        public readonly ?string $error = null,
        public readonly array $details = [],
    ) {}

    public static function offline(string $error): self
    {
        return new self(online: false, error: $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'online' => $this->online,
            'version' => $this->version,
            'error' => $this->error,
            'details' => $this->details,
        ];
    }
}
