<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Dto;

final class GitResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $output = '',
        public readonly string $error = '',
        public readonly int $returnCode = 0,
    ) {
    }

    public static function success(string $message, string $output = ''): self
    {
        return new self(true, $message, $output);
    }

    public static function failure(string $message, string $output = '', string $error = '', int $returnCode = 1): self
    {
        return new self(false, $message, $output, $error, $returnCode);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'output' => $this->output,
            'error' => $this->error,
        ];
    }
}
