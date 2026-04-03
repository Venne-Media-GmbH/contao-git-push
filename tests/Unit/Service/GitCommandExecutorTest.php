<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use VennMedia\VmGitPushBundle\Exception\GitException;
use VennMedia\VmGitPushBundle\Service\GitCommandExecutor;

class GitCommandExecutorTest extends TestCase
{
    private GitCommandExecutor $executor;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/git_push_test_' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
        mkdir($this->testDir . '/var/ssh', 0700, true);

        $this->executor = new GitCommandExecutor($this->testDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    public function testGetProjectRoot(): void
    {
        $this->assertSame($this->testDir, $this->executor->getProjectRoot());
    }

    public function testGetSshKeyPaths(): void
    {
        $this->assertSame($this->testDir . '/var/ssh/git_deploy_key', $this->executor->getSshKeyPath());
        $this->assertSame($this->testDir . '/var/ssh/git_deploy_key.pub', $this->executor->getSshPublicKeyPath());
    }

    public function testHasSshKeyReturnsFalseWhenNoKeys(): void
    {
        $this->assertFalse($this->executor->hasSshKey());
    }

    public function testHasSshKeyReturnsTrueWhenKeysExist(): void
    {
        file_put_contents($this->executor->getSshKeyPath(), 'test-key');
        file_put_contents($this->executor->getSshPublicKeyPath(), 'test-pub-key');

        $this->assertTrue($this->executor->hasSshKey());
    }

    public function testRejectsNonGitCommands(): void
    {
        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Only git commands are allowed');
        $this->executor->execute('rm -rf /');
    }

    public function testRejectsDisallowedGitSubcommands(): void
    {
        $this->expectException(GitException::class);
        $this->expectExceptionMessage('not allowed');
        $this->executor->execute('git bisect start');
    }

    public function testExecuteGitInit(): void
    {
        $result = $this->executor->execute('git init');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['returnCode']);
        $this->assertDirectoryExists($this->testDir . '/.git');
    }

    public function testExecuteWithLock(): void
    {
        $this->executor->execute('git init');
        $result = $this->executor->execute('git status', useLock: true);

        $this->assertTrue($result['success']);
    }

    public function testExecuteRaw(): void
    {
        $result = $this->executor->executeRaw('echo "hello"');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('hello', $result['output']);
    }

    public function testFailedCommandReturnsError(): void
    {
        $result = $this->executor->execute('git status');

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec('rmdir /s /q ' . escapeshellarg($dir) . ' 2>NUL');
        } else {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>/dev/null');
        }
    }
}
