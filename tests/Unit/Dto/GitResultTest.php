<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VennMedia\VmGitPushBundle\Dto\GitResult;

class GitResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = GitResult::success('All good', 'output text');

        $this->assertTrue($result->success);
        $this->assertSame('All good', $result->message);
        $this->assertSame('output text', $result->output);
        $this->assertSame('', $result->error);
        $this->assertSame(0, $result->returnCode);
    }

    public function testFailureFactory(): void
    {
        $result = GitResult::failure('Something broke', 'stdout', 'stderr', 128);

        $this->assertFalse($result->success);
        $this->assertSame('Something broke', $result->message);
        $this->assertSame('stdout', $result->output);
        $this->assertSame('stderr', $result->error);
        $this->assertSame(128, $result->returnCode);
    }

    public function testToArray(): void
    {
        $result = GitResult::success('ok', 'out');
        $array = $result->toArray();

        $this->assertArrayHasKey('success', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('output', $array);
        $this->assertArrayHasKey('error', $array);
        $this->assertTrue($array['success']);
    }
}
