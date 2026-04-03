<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Dto;

final class RemoteStatus
{
    public function __construct(
        public readonly int $ahead = 0,
        public readonly int $behind = 0,
    ) {
    }

    public function isSynced(): bool
    {
        return $this->ahead === 0 && $this->behind === 0;
    }

    public function toArray(): array
    {
        return [
            'ahead' => $this->ahead,
            'behind' => $this->behind,
            'synced' => $this->isSynced(),
        ];
    }
}
