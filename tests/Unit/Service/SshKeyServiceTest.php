<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VennMedia\VmGitPushBundle\Service\GitCommandExecutor;
use VennMedia\VmGitPushBundle\Service\SshKeyService;

class SshKeyServiceTest extends TestCase
{
    private SshKeyService $service;
    private GitCommandExecutor $executor;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/ssh_test_' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
        mkdir($this->testDir . '/var/ssh', 0700, true);

        $this->executor = new GitCommandExecutor($this->testDir);
        $this->service = new SshKeyService($this->executor);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    public function testHasSshKeyReturnsFalseInitially(): void
    {
        $this->assertFalse($this->service->hasSshKey());
    }

    public function testGetPublicKeyReturnsNullWhenNoKey(): void
    {
        $this->assertNull($this->service->getPublicKey());
    }

    public function testGetPublicKeyReturnsContentWhenExists(): void
    {
        $pubKeyPath = $this->executor->getSshPublicKeyPath();
        file_put_contents($pubKeyPath, 'ssh-ed25519 AAAA test@contao');

        $this->assertSame('ssh-ed25519 AAAA test@contao', $this->service->getPublicKey());
    }

    public function testGenerateSshKeyCreatesKeyFiles(): void
    {
        $result = $this->service->generateSshKey('test-key');

        // ssh-keygen might not be available in all test environments
        if (!$result->success && str_contains($result->output, 'ssh-keygen')) {
            $this->markTestSkipped('ssh-keygen not available');
        }

        if ($result->success) {
            $this->assertFileExists($this->executor->getSshKeyPath());
            $this->assertFileExists($this->executor->getSshPublicKeyPath());
        }
    }

    public function testDeleteSshKeyRemovesFiles(): void
    {
        $keyPath = $this->executor->getSshKeyPath();
        file_put_contents($keyPath, 'private-key');
        file_put_contents($keyPath . '.pub', 'public-key');

        $result = $this->service->deleteSshKey();

        $this->assertTrue($result->success);
        $this->assertFileDoesNotExist($keyPath);
        $this->assertFileDoesNotExist($keyPath . '.pub');
    }

    public function testDeleteSshKeyWhenNoKeysExist(): void
    {
        $result = $this->service->deleteSshKey();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Kein SSH Key', $result->message);
    }

    public function testTestSshConnectionWithoutKey(): void
    {
        $result = $this->service->testSshConnection('git@github.com:user/repo.git');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Kein SSH Key', $result->message);
    }

    public function testTestSshConnectionWithHttpsUrl(): void
    {
        file_put_contents($this->executor->getSshKeyPath(), 'key');
        file_put_contents($this->executor->getSshPublicKeyPath(), 'pub-key');

        $result = $this->service->testSshConnection('https://github.com/user/repo.git');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('HTTPS', $result->message);
    }

    #[DataProvider('deployKeyUrlProvider')]
    public function testGetDeployKeyUrl(string $remoteUrl, ?string $expectedUrl): void
    {
        $result = $this->service->getDeployKeyUrl($remoteUrl);

        if ($expectedUrl === null) {
            $this->assertNull($result);
        } else {
            $this->assertSame($expectedUrl, $result);
        }
    }

    public static function deployKeyUrlProvider(): iterable
    {
        yield 'GitHub SSH' => [
            'git@github.com:user/repo.git',
            'https://github.com/user/repo/settings/keys/new',
        ];
        yield 'GitHub HTTPS' => [
            'https://github.com/user/repo.git',
            'https://github.com/user/repo/settings/keys/new',
        ];
        yield 'GitLab SSH' => [
            'git@gitlab.com:group/project.git',
            'https://gitlab.com/group/project/-/settings/repository#js-deploy-keys-settings',
        ];
        yield 'GitLab HTTPS' => [
            'https://gitlab.com/group/project.git',
            'https://gitlab.com/group/project/-/settings/repository#js-deploy-keys-settings',
        ];
        yield 'Unknown host' => [
            'git@bitbucket.org:user/repo.git',
            null,
        ];
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
