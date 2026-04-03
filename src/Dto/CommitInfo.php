<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Dto;

final class CommitInfo
{
    public function __construct(
        public readonly string $hash,
        public readonly string $shortHash,
        public readonly string $message,
        public readonly string $author = '',
        public readonly string $date = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'shortHash' => $this->shortHash,
            'message' => $this->message,
            'author' => $this->author,
            'date' => $this->date,
        ];
    }
}
