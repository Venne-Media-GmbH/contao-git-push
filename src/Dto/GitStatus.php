<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Dto;

final class GitStatus
{
    /**
     * @param string[] $modified
     * @param string[] $added
     * @param string[] $deleted
     * @param string[] $untracked
     */
    public function __construct(
        public readonly array $modified = [],
        public readonly array $added = [],
        public readonly array $deleted = [],
        public readonly array $untracked = [],
    ) {
    }

    public function hasChanges(): bool
    {
        return !empty($this->modified) || !empty($this->added) || !empty($this->deleted) || !empty($this->untracked);
    }

    public function toArray(): array
    {
        return [
            'success' => true,
            'hasChanges' => $this->hasChanges(),
            'changes' => [
                'modified' => $this->modified,
                'added' => $this->added,
                'deleted' => $this->deleted,
                'untracked' => $this->untracked,
            ],
        ];
    }
}
