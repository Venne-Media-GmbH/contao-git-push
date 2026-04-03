<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use VennMedia\VmGitPushBundle\Dto\CommitInfo;
use VennMedia\VmGitPushBundle\Dto\GitStatus;
use VennMedia\VmGitPushBundle\Dto\RemoteStatus;
use VennMedia\VmGitPushBundle\Exception\ValidationException;
use VennMedia\VmGitPushBundle\Service\GitCommandExecutor;
use VennMedia\VmGitPushBundle\Service\GitService;
use VennMedia\VmGitPushBundle\Service\SshKeyService;
use VennMedia\VmGitPushBundle\Validator\GitInputValidator;

class GitServiceTest extends TestCase
{
    private GitService $service;
    private GitCommandExecutor $executor;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/git_service_test_' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
        mkdir($this->testDir . '/var/ssh', 0700, true);

        $this->executor = new GitCommandExecutor($this->testDir);
        $validator = new GitInputValidator();
        $sshService = new SshKeyService($this->executor);
        $hostingApi = new \VennMedia\VmGitPushBundle\Service\GitHostingApiService();
        $this->service = new GitService($this->executor, $validator, $sshService, $hostingApi);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    // ── Repository State ──────────────────────────────────────────

    public function testIsGitRepositoryReturnsFalseInitially(): void
    {
        $this->assertFalse($this->service->isGitRepository());
    }

    public function testIsGitRepositoryReturnsTrueAfterInit(): void
    {
        $this->executor->execute('git init');
        $this->assertTrue($this->service->isGitRepository());
    }

    public function testHasRemoteReturnsFalseWithoutRemote(): void
    {
        $this->executor->execute('git init');
        $this->assertFalse($this->service->hasRemote());
    }

    public function testGetRemoteUrlReturnsNullWithoutRemote(): void
    {
        $this->executor->execute('git init');
        $this->assertNull($this->service->getRemoteUrl());
    }

    // ── User Config ───────────────────────────────────────────────

    public function testSetAndGetUserConfig(): void
    {
        $this->executor->execute('git init');

        $result = $this->service->setUserConfig('Test User', 'test@example.com');
        $this->assertTrue($result->success);

        $config = $this->service->getUserConfig();
        $this->assertSame('Test User', $config['name']);
        $this->assertSame('test@example.com', $config['email']);
        $this->assertTrue($this->service->hasUserConfig());
    }

    public function testSetUserConfigValidatesEmail(): void
    {
        $this->executor->execute('git init');

        $this->expectException(ValidationException::class);
        $this->service->setUserConfig('Test User', 'not-an-email');
    }

    public function testSetUserConfigValidatesEmptyName(): void
    {
        $this->executor->execute('git init');

        $this->expectException(ValidationException::class);
        $this->service->setUserConfig('', 'test@example.com');
    }

    // ── Init Repository ───────────────────────────────────────────

    public function testInitRepositoryCreatesRepo(): void
    {
        $result = $this->service->initRepository(
            'git@github.com:test/repo.git',
            'main',
            'Test User',
            'test@example.com'
        );

        $this->assertTrue($result->success);
        $this->assertTrue($this->service->isGitRepository());
        $this->assertTrue($this->service->hasRemote());
    }

    public function testInitRepositoryValidatesUrl(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->initRepository('not-a-url', 'main');
    }

    public function testInitRepositoryValidatesBranch(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->initRepository('git@github.com:test/repo.git', '');
    }

    // ── Branch Operations ─────────────────────────────────────────

    public function testGetBranchesReturnsMainByDefault(): void
    {
        $branches = $this->service->getBranches();
        $this->assertSame(['main'], $branches);
    }

    public function testGetCurrentBranch(): void
    {
        $this->initRepoWithCommit();

        $branch = $this->service->getCurrentBranch();
        $this->assertNotEmpty($branch);
    }

    public function testCreateBranchValidatesName(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createBranch('');
    }

    public function testCreateBranchLocally(): void
    {
        $this->initRepoWithCommit();

        $result = $this->service->createBranch('feature/test', pushToRemote: false);
        $this->assertTrue($result->success);

        $branches = $this->service->getBranches();
        $this->assertContains('feature/test', $branches);
    }

    public function testDeleteBranchValidatesProtected(): void
    {
        $this->initRepoWithCommit();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('geschützt');
        $this->service->deleteBranch('main', deleteRemote: false);
    }

    public function testDeleteBranchValidatesActiveBranch(): void
    {
        $this->initRepoWithCommit();
        $this->service->createBranch('feature/test', pushToRemote: false);

        // switch to the new branch, then try to delete it
        $this->executor->execute('git checkout feature/test');

        $this->expectException(ValidationException::class);
        $this->service->deleteBranch('feature/test', deleteRemote: false);
    }

    // ── Status ────────────────────────────────────────────────────

    public function testGetStatusWithNoChanges(): void
    {
        $this->initRepoWithCommit();

        $status = $this->service->getStatus();
        $this->assertInstanceOf(GitStatus::class, $status);
        $this->assertFalse($status->hasChanges());
    }

    public function testGetStatusWithModifiedFile(): void
    {
        $this->initRepoWithCommit();
        file_put_contents($this->testDir . '/test.txt', 'modified content');

        $status = $this->service->getStatus();
        $this->assertTrue($status->hasChanges());
        $this->assertContains('test.txt', $status->modified);
    }

    public function testGetStatusWithNewFile(): void
    {
        $this->initRepoWithCommit();
        file_put_contents($this->testDir . '/new-file.txt', 'new content');

        $status = $this->service->getStatus();
        $this->assertTrue($status->hasChanges());
        $this->assertContains('new-file.txt', $status->untracked);
    }

    public function testGetStatusToArray(): void
    {
        $status = new GitStatus(['a.txt'], ['b.txt'], ['c.txt'], ['d.txt']);
        $array = $status->toArray();

        $this->assertTrue($array['success']);
        $this->assertTrue($array['hasChanges']);
        $this->assertSame(['a.txt'], $array['changes']['modified']);
    }

    // ── Remote Status ─────────────────────────────────────────────

    public function testRemoteStatusDto(): void
    {
        $synced = new RemoteStatus(0, 0);
        $this->assertTrue($synced->isSynced());

        $behind = new RemoteStatus(0, 3);
        $this->assertFalse($behind->isSynced());
        $this->assertSame(3, $behind->behind);

        $ahead = new RemoteStatus(2, 0);
        $this->assertFalse($ahead->isSynced());
        $this->assertSame(2, $ahead->ahead);
    }

    // ── Commit Info ───────────────────────────────────────────────

    public function testCommitInfoDto(): void
    {
        $commit = new CommitInfo(
            hash: 'abc1234567890',
            shortHash: 'abc1234',
            message: 'test commit',
            author: 'Test User',
            date: '2024-01-01 00:00:00',
        );

        $this->assertSame('abc1234567890', $commit->hash);
        $this->assertSame('abc1234', $commit->shortHash);
        $this->assertSame('test commit', $commit->message);

        $array = $commit->toArray();
        $this->assertSame('abc1234567890', $array['hash']);
    }

    // ── Commit and Push ───────────────────────────────────────────

    public function testCommitAndPushValidatesMessage(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->commitAndPush('', 'main');
    }

    public function testCommitAndPushValidatesBranch(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->commitAndPush('test', '');
    }

    public function testCommitAndPushLocally(): void
    {
        $this->initRepoWithCommit();
        file_put_contents($this->testDir . '/change.txt', 'new content');

        // This will fail on push (no remote), but should succeed on commit
        $result = $this->service->commitAndPush('Add change', 'main');
        // Without remote, push fails - that's expected
        $this->assertFalse($result->success);
    }

    // ── Checkout Commit ───────────────────────────────────────────

    public function testCheckoutCommitValidatesHash(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->checkoutCommit('not-a-hash');
    }

    // ── Last Commit ───────────────────────────────────────────────

    public function testGetLastCommitReturnsNullWithNoCommits(): void
    {
        $this->executor->execute('git init');
        $this->assertNull($this->service->getLastCommit());
    }

    public function testGetLastCommitReturnsCommitInfo(): void
    {
        $this->initRepoWithCommit();

        $commit = $this->service->getLastCommit();
        $this->assertInstanceOf(CommitInfo::class, $commit);
        $this->assertNotEmpty($commit->hash);
        $this->assertSame(7, strlen($commit->shortHash));
        $this->assertStringContainsString('Initial', $commit->message);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function initRepoWithCommit(): void
    {
        $this->executor->execute('git init');
        $this->executor->execute('git config user.name "Test"');
        $this->executor->execute('git config user.email "test@test.com"');
        file_put_contents($this->testDir . '/test.txt', 'initial content');
        $this->executor->execute('git add .');
        $this->executor->execute('git commit -m "Initial commit"');
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
