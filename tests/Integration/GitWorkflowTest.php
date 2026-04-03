<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use VennMedia\VmGitPushBundle\Exception\ValidationException;
use VennMedia\VmGitPushBundle\Service\GitCommandExecutor;
use VennMedia\VmGitPushBundle\Service\GitService;
use VennMedia\VmGitPushBundle\Service\SshKeyService;
use VennMedia\VmGitPushBundle\Validator\GitInputValidator;

/**
 * Integration test that creates real Git repos and tests the full workflow.
 */
class GitWorkflowTest extends TestCase
{
    private string $serverDir;
    private string $clientDir;
    private GitService $service;
    private GitCommandExecutor $executor;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/git_workflow_' . bin2hex(random_bytes(4));

        // Create "server" (bare repo simulating remote)
        $this->serverDir = $base . '/server.git';
        mkdir($this->serverDir, 0755, true);
        exec('git init --bare --initial-branch=main ' . escapeshellarg($this->serverDir) . ' 2>&1');

        // Create "client" (working directory simulating Contao installation)
        $this->clientDir = $base . '/client';
        mkdir($this->clientDir, 0755, true);
        mkdir($this->clientDir . '/var/ssh', 0700, true);

        $this->executor = new GitCommandExecutor($this->clientDir);
        $validator = new GitInputValidator();
        $sshService = new SshKeyService($this->executor);
        $this->service = new GitService($this->executor, $validator, $sshService);
    }

    protected function tearDown(): void
    {
        $base = dirname($this->serverDir);
        $this->removeDirectory($base);
    }

    /**
     * Test complete workflow: init -> commit -> push -> modify -> commit -> push -> pull
     * This simulates the exact scenario: developer pushes code, Contao user pushes content changes.
     */
    public function testFullPushPullWorkflow(): void
    {
        // 1. Initialize repo with remote
        $this->executor->execute('git init');
        $this->executor->execute('git config user.name "Contao Admin"');
        $this->executor->execute('git config user.email "admin@example.com"');
        $this->executor->execute('git remote add origin ' . escapeshellarg($this->serverDir));
        $this->executor->execute('git branch -M main');

        $this->assertTrue($this->service->isGitRepository());
        $this->assertTrue($this->service->hasRemote());

        // 2. Create initial content and push
        file_put_contents($this->clientDir . '/config.yaml', "database:\n  host: localhost\n");
        file_put_contents($this->clientDir . '/template.html', '<h1>Welcome</h1>');

        $result = $this->service->commitAndPush('Initial content setup', 'main');
        $this->assertTrue($result->success, 'Initial push failed: ' . $result->output);

        // 3. Verify status is clean
        $status = $this->service->getStatus();
        $this->assertFalse($status->hasChanges());

        // 4. Simulate Contao user making content changes
        file_put_contents($this->clientDir . '/template.html', '<h1>Welcome</h1><p>New content from CMS</p>');

        $status = $this->service->getStatus();
        $this->assertTrue($status->hasChanges());
        $this->assertContains('template.html', $status->modified);

        // 5. Push CMS changes
        $result = $this->service->commitAndPush('Content update from CMS', 'main');
        $this->assertTrue($result->success, 'CMS push failed: ' . $result->output);

        // 6. Verify commit history
        $lastCommit = $this->service->getLastCommit();
        $this->assertNotNull($lastCommit);
        $this->assertStringContainsString('Content update from CMS', $lastCommit->message);

        // 7. Verify remote status is synced
        $remoteStatus = $this->service->getRemoteStatus();
        $this->assertTrue($remoteStatus->isSynced());
    }

    /**
     * Test branch workflow: create branch -> switch -> commit -> switch back
     */
    public function testBranchWorkflow(): void
    {
        $this->initWithFirstCommit();

        // Create new branch
        $result = $this->service->createBranch('feature/new-layout', pushToRemote: false);
        $this->assertTrue($result->success);
        $this->assertSame('feature/new-layout', $this->service->getCurrentBranch());

        // Make changes on feature branch
        file_put_contents($this->clientDir . '/layout.css', 'body { color: red; }');
        $this->executor->execute('git add .');
        $this->executor->execute('git commit -m "New layout"');

        // Switch back to main
        $result = $this->service->switchBranch('main');
        $this->assertTrue($result->success);
        $this->assertSame('main', $this->service->getCurrentBranch());

        // layout.css should not exist on main
        $this->assertFileDoesNotExist($this->clientDir . '/layout.css');

        // Switch back to feature branch
        $result = $this->service->switchBranch('feature/new-layout');
        $this->assertTrue($result->success);
        $this->assertFileExists($this->clientDir . '/layout.css');
    }

    /**
     * Test that force push on protected branch is blocked.
     */
    public function testForcePushProtectedBranchBlocked(): void
    {
        $this->initWithFirstCommit();

        file_put_contents($this->clientDir . '/test.txt', 'changed');
        $result = $this->service->commitAndPush('Update', 'main', forcePush: true);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('geschuetzt', $result->message);
    }

    /**
     * Test pull from remote with new changes.
     */
    public function testPullFromRemote(): void
    {
        $this->initWithFirstCommit();

        // Simulate another developer pushing changes
        $devDir = $this->simulateDevPush('from-dev.txt', 'Developer changes', 'Dev changes');

        // Now pull in the "Contao" client
        $result = $this->service->pull('main');
        $this->assertTrue($result->success, 'Pull failed: ' . $result->output . ' ' . $result->error);

        // Verify the file from developer is present
        $this->assertFileExists($this->clientDir . '/from-dev.txt');
        $this->assertStringContainsString('Developer changes', file_get_contents($this->clientDir . '/from-dev.txt'));
    }

    /**
     * Test pull with local uncommitted changes (auto-stash).
     */
    public function testPullWithLocalChangesAutoStash(): void
    {
        $this->initWithFirstCommit();

        // Simulate developer pushing
        $this->simulateDevPush('dev-feature.txt', 'Feature from dev', 'Add dev feature');

        // Make local changes (different file to avoid conflicts)
        file_put_contents($this->clientDir . '/local-change.txt', 'Local CMS change');

        // Pull should auto-stash and restore
        $result = $this->service->pull('main');
        $this->assertTrue($result->success, 'Pull with stash failed: ' . $result->output);

        // Both files should exist
        $this->assertFileExists($this->clientDir . '/dev-feature.txt');
        $this->assertFileExists($this->clientDir . '/local-change.txt');
    }

    /**
     * Test checkout to a specific commit and back.
     */
    public function testCheckoutCommitAndRestore(): void
    {
        $this->initWithFirstCommit();

        // Make a second commit
        file_put_contents($this->clientDir . '/second.txt', 'second file');
        $this->executor->execute('git add .');
        $this->executor->execute('git commit -m "Second commit"');

        $lastCommit = $this->service->getLastCommit();
        $this->assertNotNull($lastCommit);

        // Get the first commit hash
        $logResult = $this->executor->execute('git log --format="%H" -n 2');
        $commits = array_filter(explode("\n", trim($logResult['output'])));
        $firstCommitHash = end($commits);

        // Checkout first commit
        $result = $this->service->checkoutCommit($firstCommitHash);
        $this->assertTrue($result->success);
        $this->assertFileDoesNotExist($this->clientDir . '/second.txt');

        // Restore to latest
        $result = $this->service->checkoutLatest();
        $this->assertTrue($result->success);
    }

    /**
     * Test validation is enforced on all operations.
     */
    public function testValidationEnforced(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->initRepository('invalid-url');
    }

    /**
     * Test clone workflow with local bare repo.
     */
    public function testCloneRepository(): void
    {
        // First push something to the "server"
        $tempSetup = dirname($this->serverDir) . '/setup';
        mkdir($tempSetup, 0755, true);
        $this->gitInDir($tempSetup, 'clone ' . escapeshellarg($this->serverDir) . ' .');
        $this->gitInDir($tempSetup, 'config user.name "Setup"');
        $this->gitInDir($tempSetup, 'config user.email "setup@test.com"');
        file_put_contents($tempSetup . '/README.md', '# Test Repo');
        $this->gitInDir($tempSetup, 'add .');
        $this->gitInDir($tempSetup, 'commit -m "Initial"');
        $this->gitInDir($tempSetup, 'push origin main');

        // Now clone into our client dir (which currently has no .git)
        $result = $this->service->cloneRepository(
            $this->serverDir,
            'main',
            'Contao Admin',
            'admin@example.com'
        );

        $this->assertTrue($result->success, 'Clone failed: ' . $result->output . ' ' . $result->error);
        $this->assertTrue($this->service->isGitRepository());
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Run a git command in a specific directory (cross-platform safe).
     */
    private function gitInDir(string $dir, string $gitArgs): string
    {
        $command = 'git -C ' . escapeshellarg($dir) . ' ' . $gitArgs . ' 2>&1';
        $output = [];
        exec($command, $output, $rc);

        return implode("\n", $output);
    }

    /**
     * Simulate a developer cloning, making changes, and pushing.
     */
    private function simulateDevPush(string $filename, string $content, string $commitMessage): string
    {
        $devDir = dirname($this->serverDir) . '/developer_' . bin2hex(random_bytes(4));
        mkdir($devDir, 0755, true);

        $this->gitInDir($devDir, 'clone ' . escapeshellarg($this->serverDir) . ' .');
        $this->gitInDir($devDir, 'config user.name "Dev"');
        $this->gitInDir($devDir, 'config user.email "dev@test.com"');
        file_put_contents($devDir . '/' . $filename, $content);
        $this->gitInDir($devDir, 'add .');
        $this->gitInDir($devDir, 'commit -m ' . escapeshellarg($commitMessage));
        $this->gitInDir($devDir, 'push origin main');

        return $devDir;
    }

    private function initWithFirstCommit(): void
    {
        $this->executor->execute('git init');
        $this->executor->execute('git config user.name "Contao Admin"');
        $this->executor->execute('git config user.email "admin@example.com"');
        $this->executor->execute('git remote add origin ' . escapeshellarg($this->serverDir));
        $this->executor->execute('git branch -M main');

        file_put_contents($this->clientDir . '/test.txt', 'initial');
        $this->executor->execute('git add .');
        $this->executor->execute('git commit -m "Initial commit"');
        $this->executor->execute('git push -u origin main');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // On Windows, use system commands for reliable removal of git repos
        if (PHP_OS_FAMILY === 'Windows') {
            exec('rmdir /s /q ' . escapeshellarg($dir) . ' 2>NUL');
        } else {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>/dev/null');
        }
    }
}
