<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Exception;

class GitException extends \RuntimeException
{
    private string $gitOutput;
    private int $gitReturnCode;

    public function __construct(string $message, string $gitOutput = '', int $gitReturnCode = 1, ?\Throwable $previous = null)
    {
        $this->gitOutput = $gitOutput;
        $this->gitReturnCode = $gitReturnCode;
        parent::__construct($message, 0, $previous);
    }

    public function getGitOutput(): string
    {
        return $this->gitOutput;
    }

    public function getGitReturnCode(): int
    {
        return $this->gitReturnCode;
    }
}
