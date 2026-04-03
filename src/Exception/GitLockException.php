<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Exception;

class GitLockException extends GitException
{
    public function __construct(string $message = 'Another Git operation is currently in progress. Please try again later.')
    {
        parent::__construct($message);
    }
}
