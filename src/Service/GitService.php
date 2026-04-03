<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Service;

use Psr\Log\LoggerInterface;
use VennMedia\VmGitPushBundle\Dto\CommitInfo;
use VennMedia\VmGitPushBundle\Dto\GitResult;
use VennMedia\VmGitPushBundle\Dto\GitStatus;
use VennMedia\VmGitPushBundle\Dto\RemoteStatus;
use VennMedia\VmGitPushBundle\Exception\GitConflictException;
use VennMedia\VmGitPushBundle\Exception\GitException;
use VennMedia\VmGitPushBundle\Validator\GitInputValidator;

class GitService
{
    public function __construct(
        private readonly GitCommandExecutor $executor,
        private readonly GitInputValidator $validator,
        private readonly SshKeyService $sshKeyService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getProjectRoot(): string
    {
        return $this->executor->getProjectRoot();
    }

    public function getSshKeyService(): SshKeyService
    {
        return $this->sshKeyService;
    }

    // ── Repository State ──────────────────────────────────────────

    public function isGitRepository(): bool
    {
        return is_dir($this->executor->getProjectRoot() . '/.git');
    }

    public function hasRemote(): bool
    {
        if (!$this->isGitRepository()) {
            return false;
        }

        $result = $this->executor->execute('git remote -v');

        return !empty(trim($result['output']));
    }

    public function getRemoteUrl(): ?string
    {
        if (!$this->hasRemote()) {
            return null;
        }

        $result = $this->executor->execute('git remote get-url origin');

        return $result['success'] ? trim($result['output']) : null;
    }

    // ── User Config ───────────────────────────────────────────────

    public function hasUserConfig(): bool
    {
        $nameResult = $this->executor->execute('git config user.name');
        $emailResult = $this->executor->execute('git config user.email');

        return !empty(trim($nameResult['output'])) && !empty(trim($emailResult['output']));
    }

    public function getUserConfig(): array
    {
        $nameResult = $this->executor->execute('git config user.name');
        $emailResult = $this->executor->execute('git config user.email');

        return [
            'name' => trim($nameResult['output']),
            'email' => trim($emailResult['output']),
        ];
    }

    public function setUserConfig(string $name, string $email): GitResult
    {
        $this->validator->validateUserName($name);
        $this->validator->validateUserEmail($email);

        $nameResult = $this->executor->execute('git config user.name ' . escapeshellarg($name));
        if (!$nameResult['success']) {
            return GitResult::failure('Fehler beim Setzen des Benutzernamens', $nameResult['output'], $nameResult['error']);
        }

        $emailResult = $this->executor->execute('git config user.email ' . escapeshellarg($email));
        if (!$emailResult['success']) {
            return GitResult::failure('Fehler beim Setzen der E-Mail', $emailResult['output'], $emailResult['error']);
        }

        $this->logger?->info('Git user config set', ['name' => $name, 'email' => $email]);

        return GitResult::success('Git Benutzer erfolgreich konfiguriert');
    }

    // ── Repository Init/Clone ─────────────────────────────────────

    public function initRepository(string $remoteUrl, string $branch = 'main', ?string $userName = null, ?string $userEmail = null): GitResult
    {
        $this->validator->validateRemoteUrl($remoteUrl);
        $this->validator->validateBranchName($branch);

        if ($userName && $userEmail) {
            $this->validator->validateUserName($userName);
            $this->validator->validateUserEmail($userEmail);
        }

        $this->createDefaultGitignore();

        $commands = ['git init'];

        if ($userName && $userEmail) {
            $commands[] = 'git config user.name ' . escapeshellarg($userName);
            $commands[] = 'git config user.email ' . escapeshellarg($userEmail);
        }

        $commands[] = 'git remote add origin ' . escapeshellarg($remoteUrl);
        $commands[] = 'git branch -M ' . escapeshellarg($branch);

        $allOutput = [];
        foreach ($commands as $command) {
            $result = $this->executor->execute($command);
            $allOutput[] = $result['output'];
            if (!$result['success']) {
                return GitResult::failure(
                    'Fehler beim Ausfuehren: ' . $command,
                    $result['output'],
                    $result['error']
                );
            }
        }

        $this->logger?->info('Repository initialized', ['remote' => $remoteUrl, 'branch' => $branch]);

        return GitResult::success('Repository erfolgreich initialisiert', implode("\n", $allOutput));
    }

    public function cloneRepository(string $remoteUrl, string $branch = 'main', ?string $userName = null, ?string $userEmail = null): GitResult
    {
        $this->validator->validateRemoteUrl($remoteUrl);
        $this->validator->validateBranchName($branch);

        if ($userName && $userEmail) {
            $this->validator->validateUserName($userName);
            $this->validator->validateUserEmail($userEmail);
        }

        $tempDir = $this->executor->getProjectRoot() . '/temp_git_clone_' . bin2hex(random_bytes(8));

        $cloneResult = $this->executor->execute(
            'git clone --branch ' . escapeshellarg($branch) . ' ' . escapeshellarg($remoteUrl) . ' ' . escapeshellarg($tempDir),
            useLock: true
        );

        if (!$cloneResult['success']) {
            $cloneResult = $this->executor->execute(
                'git clone ' . escapeshellarg($remoteUrl) . ' ' . escapeshellarg($tempDir),
                useLock: true
            );
        }

        if (!$cloneResult['success']) {
            $this->deleteDirectory($tempDir);

            return GitResult::failure('Fehler beim Klonen des Repositories', $cloneResult['output'], $cloneResult['error']);
        }

        $gitDir = $tempDir . '/.git';
        $targetGitDir = $this->executor->getProjectRoot() . '/.git';

        if (is_dir($targetGitDir)) {
            $this->deleteDirectory($targetGitDir);
        }

        if (!rename($gitDir, $targetGitDir)) {
            $this->deleteDirectory($tempDir);

            return GitResult::failure('Fehler beim Verschieben des .git Ordners');
        }

        $this->deleteDirectory($tempDir);

        if ($userName && $userEmail) {
            $this->executor->execute('git config user.name ' . escapeshellarg($userName));
            $this->executor->execute('git config user.email ' . escapeshellarg($userEmail));
        }

        $this->createDefaultGitignore();

        $resetResult = $this->executor->execute('git reset --hard origin/' . escapeshellarg($branch));

        $this->logger?->info('Repository cloned', ['remote' => $remoteUrl, 'branch' => $branch]);

        return GitResult::success(
            'Repository erfolgreich geklont. Die Server-Version ist jetzt aktiv.',
            $cloneResult['output'] . "\n" . ($resetResult['output'] ?? '')
        );
    }

    // ── Branch Operations ─────────────────────────────────────────

    public function getBranches(): array
    {
        $result = $this->executor->execute('git branch');

        if (!$result['success']) {
            return ['main'];
        }

        $branches = [];
        foreach (explode("\n", trim($result['output'])) as $line) {
            $branch = trim(str_replace('*', '', $line));
            if (!empty($branch)) {
                $branches[] = $branch;
            }
        }

        return $branches ?: ['main'];
    }

    public function getRemoteBranches(): array
    {
        $this->fetch();
        $result = $this->executor->execute('git branch -r');

        if (!$result['success']) {
            return [];
        }

        $branches = [];
        foreach (explode("\n", trim($result['output'])) as $line) {
            $branch = trim($line);
            if (str_starts_with($branch, 'origin/') && !str_contains($branch, 'HEAD')) {
                $branches[] = substr($branch, 7);
            }
        }

        return array_unique($branches);
    }

    public function getCurrentBranch(): string
    {
        $result = $this->executor->execute('git branch --show-current');

        return $result['success'] && !empty(trim($result['output'])) ? trim($result['output']) : 'main';
    }

    public function switchBranch(string $branch): GitResult
    {
        $this->validator->validateBranchName($branch);

        $statusCheck = $this->getStatus();
        if ($statusCheck->hasChanges()) {
            $this->executor->execute('git stash push -m "auto-stash before branch switch"');
            $this->logger?->info('Auto-stashed changes before branch switch', ['branch' => $branch]);
        }

        $this->fetch();
        $localBranches = $this->getBranches();

        if (in_array($branch, $localBranches, true)) {
            $result = $this->executor->execute('git checkout ' . escapeshellarg($branch));
        } else {
            $result = $this->executor->execute(
                'git checkout -b ' . escapeshellarg($branch) . ' origin/' . escapeshellarg($branch)
            );
        }

        if (!$result['success']) {
            if ($statusCheck->hasChanges()) {
                $this->executor->execute('git stash pop');
            }

            return GitResult::failure('Fehler beim Wechsel zu Branch: ' . $branch, $result['output'], $result['error']);
        }

        if ($statusCheck->hasChanges()) {
            $stashResult = $this->executor->execute('git stash pop');
            if (!$stashResult['success'] && str_contains($stashResult['output'], 'CONFLICT')) {
                $this->logger?->warning('Stash pop conflict after branch switch', ['branch' => $branch]);

                return new GitResult(
                    true,
                    'Branch gewechselt, aber es gibt Konflikte mit den gestashten Aenderungen. Bitte manuell aufloesen.',
                    $result['output'] . "\n" . $stashResult['output']
                );
            }
        }

        $this->logger?->info('Switched branch', ['branch' => $branch]);

        return GitResult::success('Erfolgreich zu Branch "' . $branch . '" gewechselt', $result['output']);
    }

    public function createBranch(string $branchName, bool $pushToRemote = true): GitResult
    {
        $this->validator->validateBranchName($branchName);

        $result = $this->executor->execute('git checkout -b ' . escapeshellarg($branchName));

        if (!$result['success']) {
            return GitResult::failure('Fehler beim Erstellen des Branches', $result['output'], $result['error']);
        }

        if ($pushToRemote) {
            $pushResult = $this->executor->execute('git push -u origin ' . escapeshellarg($branchName), useLock: true);

            if (!$pushResult['success']) {
                return new GitResult(
                    true,
                    'Branch lokal erstellt, aber Push zum Remote fehlgeschlagen',
                    $result['output'] . "\n" . $pushResult['output'],
                    $pushResult['error']
                );
            }
        }

        $this->logger?->info('Branch created', ['branch' => $branchName, 'pushed' => $pushToRemote]);

        return GitResult::success('Branch "' . $branchName . '" erfolgreich erstellt', $result['output']);
    }

    public function renameBranch(string $oldName, string $newName): GitResult
    {
        $this->validator->validateBranchName($newName);

        if ($this->validator->isProtectedBranch($oldName)) {
            return GitResult::failure('Der Branch "' . $oldName . '" ist geschuetzt und kann nicht umbenannt werden.');
        }

        $result = $this->executor->execute(
            'git branch -m ' . escapeshellarg($oldName) . ' ' . escapeshellarg($newName)
        );

        if (!$result['success']) {
            return GitResult::failure('Fehler beim Umbenennen des Branches', $result['output'], $result['error']);
        }

        $this->executor->execute('git push origin --delete ' . escapeshellarg($oldName), useLock: true);
        $pushResult = $this->executor->execute('git push -u origin ' . escapeshellarg($newName), useLock: true);

        $this->logger?->info('Branch renamed', ['old' => $oldName, 'new' => $newName]);

        return GitResult::success(
            'Branch von "' . $oldName . '" zu "' . $newName . '" umbenannt',
            $result['output'] . "\n" . ($pushResult['output'] ?? '')
        );
    }

    public function deleteBranch(string $branchName, bool $deleteRemote = true): GitResult
    {
        $currentBranch = $this->getCurrentBranch();
        $this->validator->validateBranchDeletion($branchName, $currentBranch);

        $result = $this->executor->execute('git branch -D ' . escapeshellarg($branchName));

        if (!$result['success']) {
            return GitResult::failure('Fehler beim Loeschen des lokalen Branches', $result['output'], $result['error']);
        }

        if ($deleteRemote) {
            $remoteResult = $this->executor->execute(
                'git push origin --delete ' . escapeshellarg($branchName),
                useLock: true
            );

            if (!$remoteResult['success']) {
                return new GitResult(
                    true,
                    'Branch lokal geloescht, aber Remote-Loeschung fehlgeschlagen',
                    $result['output'] . "\n" . $remoteResult['output']
                );
            }
        }

        $this->logger?->info('Branch deleted', ['branch' => $branchName, 'remote' => $deleteRemote]);

        return GitResult::success('Branch "' . $branchName . '" erfolgreich geloescht', $result['output']);
    }

    // ── Remote Operations ─────────────────────────────────────────

    public function addRemote(string $remoteUrl): GitResult
    {
        $this->validator->validateRemoteUrl($remoteUrl);

        $result = $this->executor->execute('git remote add origin ' . escapeshellarg($remoteUrl));

        if (!$result['success']) {
            $result = $this->executor->execute('git remote set-url origin ' . escapeshellarg($remoteUrl));
        }

        $this->logger?->info('Remote added/updated', ['url' => $remoteUrl]);

        return new GitResult(
            $result['success'],
            $result['success'] ? 'Remote erfolgreich hinzugefuegt' : 'Fehler beim Hinzufuegen des Remote',
            $result['output'],
            $result['error'] ?? ''
        );
    }

    public function setRemoteUrl(string $remoteUrl): GitResult
    {
        $this->validator->validateRemoteUrl($remoteUrl);

        $result = $this->executor->execute('git remote set-url origin ' . escapeshellarg($remoteUrl));

        $this->logger?->info('Remote URL changed', ['url' => $remoteUrl]);

        return new GitResult(
            $result['success'],
            $result['success'] ? 'Remote URL erfolgreich geaendert' : 'Fehler beim Aendern der Remote URL',
            $result['output'],
            $result['error'] ?? ''
        );
    }

    public function fetch(): GitResult
    {
        $result = $this->executor->execute('git fetch origin');

        return new GitResult($result['success'], '', $result['output'], $result['error'] ?? '');
    }

    // ── Status & Info ─────────────────────────────────────────────

    public function getStatus(): GitStatus
    {
        $result = $this->executor->execute('git status --porcelain');

        if (!$result['success']) {
            return new GitStatus();
        }

        $modified = [];
        $added = [];
        $deleted = [];
        $untracked = [];

        foreach (array_filter(explode("\n", rtrim($result['output']))) as $line) {
            $status = substr($line, 0, 2);
            $file = trim(substr($line, 3));

            if (str_contains($status, 'M')) {
                $modified[] = $file;
            } elseif (str_contains($status, 'A')) {
                $added[] = $file;
            } elseif (str_contains($status, 'D')) {
                $deleted[] = $file;
            } elseif (str_contains($status, '?')) {
                $untracked[] = $file;
            }
        }

        return new GitStatus($modified, $added, $deleted, $untracked);
    }

    public function getRemoteStatus(): RemoteStatus
    {
        $this->fetch();
        $branch = $this->getCurrentBranch();

        $behindResult = $this->executor->execute('git rev-list HEAD..origin/' . escapeshellarg($branch) . ' --count');
        $behind = $behindResult['success'] ? (int) trim($behindResult['output']) : 0;

        $aheadResult = $this->executor->execute('git rev-list origin/' . escapeshellarg($branch) . '..HEAD --count');
        $ahead = $aheadResult['success'] ? (int) trim($aheadResult['output']) : 0;

        return new RemoteStatus($ahead, $behind);
    }

    public function getLastCommit(): ?CommitInfo
    {
        $result = $this->executor->execute('git log -1 --format="%H|%s|%ci"');

        if (!$result['success'] || empty(trim($result['output']))) {
            return null;
        }

        $parts = explode('|', trim($result['output']), 3);

        return new CommitInfo(
            hash: $parts[0] ?? '',
            shortHash: substr($parts[0] ?? '', 0, 7),
            message: $parts[1] ?? '',
            date: $parts[2] ?? '',
        );
    }

    /**
     * @return CommitInfo[]
     */
    public function getCommitHistory(int $limit = 20): array
    {
        $this->fetch();
        $branch = $this->getCurrentBranch();

        $result = $this->executor->execute(
            'git log origin/' . escapeshellarg($branch) . ' --format="%H|%s|%an|%ci" -n ' . max(1, min($limit, 100))
        );

        if (!$result['success'] || empty(trim($result['output']))) {
            return [];
        }

        $commits = [];
        foreach (explode("\n", trim($result['output'])) as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = explode('|', $line, 4);
            $commits[] = new CommitInfo(
                hash: $parts[0] ?? '',
                shortHash: substr($parts[0] ?? '', 0, 7),
                message: $parts[1] ?? '',
                author: $parts[2] ?? '',
                date: $parts[3] ?? '',
            );
        }

        return $commits;
    }

    public function getStatusText(): string
    {
        $result = $this->executor->execute('git status');

        return $result['output'];
    }

    // ── Commit & Push ─────────────────────────────────────────────

    public function commitAndPush(string $message, string $branch, bool $forcePush = false): GitResult
    {
        $this->validator->validateCommitMessage($message);
        $this->validator->validateBranchName($branch);

        if ($forcePush && $this->validator->isProtectedBranch($branch)) {
            return GitResult::failure(
                'Force Push auf geschuetzten Branch "' . $branch . '" ist nicht erlaubt. '
                . 'Bitte verwenden Sie einen normalen Push oder erstellen Sie einen Pull Request.'
            );
        }

        $this->cleanIgnoredFromIndex();

        $addResult = $this->executor->execute('git add .', useLock: true);
        if (!$addResult['success']) {
            return GitResult::failure('Fehler bei git add', $addResult['output'], $addResult['error']);
        }

        $commitResult = $this->executor->execute('git commit -m ' . escapeshellarg($message), useLock: true);
        if (!$commitResult['success'] && !str_contains($commitResult['output'], 'nothing to commit')) {
            return GitResult::failure('Fehler bei git commit', $commitResult['output'], $commitResult['error']);
        }

        $forceFlag = $forcePush ? ' --force-with-lease' : '';
        $pushResult = $this->executor->execute(
            'git push' . $forceFlag . ' origin ' . escapeshellarg($branch),
            useLock: true
        );

        if (!$pushResult['success'] && !$forcePush) {
            $pushResult = $this->executor->execute(
                'git push --set-upstream origin ' . escapeshellarg($branch),
                useLock: true
            );
        }

        if (!$pushResult['success']) {
            $this->logger?->error('Push failed', [
                'branch' => $branch,
                'forcePush' => $forcePush,
                'output' => mb_substr($pushResult['output'], 0, 500),
            ]);

            return GitResult::failure(
                'Push fehlgeschlagen. Moegliche Ursache: Remote hat neuere Aenderungen. Bitte zuerst Pull ausfuehren.',
                $commitResult['output'] . "\n" . $pushResult['output'],
                $pushResult['error']
            );
        }

        $this->logger?->info('Commit and push successful', ['branch' => $branch, 'force' => $forcePush]);

        return GitResult::success(
            'Commit und Push erfolgreich',
            $commitResult['output'] . "\n" . $pushResult['output']
        );
    }

    // ── Pull ──────────────────────────────────────────────────────

    public function pull(string $branch): GitResult
    {
        $this->validator->validateBranchName($branch);

        $status = $this->getStatus();
        $hadChanges = $status->hasChanges();

        if ($hadChanges) {
            $this->executor->execute('git stash push -m "auto-stash before pull"', useLock: true);
            $this->logger?->info('Auto-stashed changes before pull');
        }

        $result = $this->executor->execute('git pull origin ' . escapeshellarg($branch), useLock: true);

        if (str_contains($result['output'], 'CONFLICT') || str_contains($result['error'], 'CONFLICT')) {
            $this->executor->execute('git merge --abort');
            if ($hadChanges) {
                $this->executor->execute('git stash pop');
            }

            $this->logger?->warning('Pull conflict detected, merge aborted', ['branch' => $branch]);

            throw new GitConflictException(
                'Konflikte beim Pull erkannt! Der Merge wurde automatisch abgebrochen. '
                . 'Bitte koordinieren Sie mit dem Entwickler, um die Konflikte aufzuloesen.',
                $result['output']
            );
        }

        if (!$result['success']) {
            if ($hadChanges) {
                $this->executor->execute('git stash pop');
            }

            return GitResult::failure('Fehler beim Pull', $result['output'], $result['error']);
        }

        if ($hadChanges) {
            $stashResult = $this->executor->execute('git stash pop');
            if (!$stashResult['success'] && str_contains($stashResult['output'], 'CONFLICT')) {
                $this->logger?->warning('Stash pop conflict after pull');

                return new GitResult(
                    true,
                    'Pull erfolgreich, aber es gibt Konflikte mit lokalen Aenderungen. Bitte manuell pruefen.',
                    $result['output'] . "\n" . $stashResult['output']
                );
            }
        }

        $this->logger?->info('Pull successful', ['branch' => $branch]);

        return GitResult::success('Pull erfolgreich', $result['output']);
    }

    // ── Checkout / Restore ────────────────────────────────────────

    public function checkoutCommit(string $commitHash): GitResult
    {
        $this->validator->validateCommitHash($commitHash);

        $this->executor->execute('git stash push -m "auto-stash before checkout"');
        $result = $this->executor->execute('git reset --hard ' . escapeshellarg($commitHash));

        if (!$result['success']) {
            return GitResult::failure('Fehler beim Wechsel zum Commit', $result['output'], $result['error']);
        }

        $this->logger?->info('Checked out commit', ['hash' => substr($commitHash, 0, 7)]);

        return GitResult::success(
            'Erfolgreich zu Commit ' . substr($commitHash, 0, 7) . ' gewechselt',
            $result['output']
        );
    }

    public function checkoutLatest(): GitResult
    {
        $branch = $this->getCurrentBranch();
        $result = $this->executor->execute('git checkout ' . escapeshellarg($branch));

        if (!$result['success']) {
            $result = $this->executor->execute('git reset --hard origin/' . escapeshellarg($branch));
        }

        $this->logger?->info('Checked out latest', ['branch' => $branch]);

        return new GitResult(
            $result['success'],
            $result['success']
                ? 'Zurueck zum aktuellen Stand (' . $branch . ')'
                : 'Fehler beim Zuruecksetzen',
            $result['output'],
            $result['error'] ?? ''
        );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function cleanIgnoredFromIndex(): void
    {
        $paths = [
            'vendor/', 'var/', '.env.local', 'contao-manager/',
            'files/', 'assets/', 'node_modules/',
            'public/bundles/', 'public/assets/', 'public/share/', 'public/system/',
            'system/tmp/', 'system/config/localconfig.php',
        ];

        foreach ($paths as $path) {
            $this->executor->execute('git rm -r --cached --ignore-unmatch ' . escapeshellarg($path));
        }
    }

    private function createDefaultGitignore(): void
    {
        $gitignorePath = $this->executor->getProjectRoot() . '/.gitignore';
        if (file_exists($gitignorePath)) {
            return;
        }

        $content = <<<'GITIGNORE'
# Contao
/var/
/vendor/
/assets/
/system/tmp/
/system/config/localconfig.php
/files/
/web/bundles/
/web/assets/
/web/share/
/web/system/
/public/bundles/
/public/assets/
/public/share/
/public/system/
/contao-manager/

# Environment
.env.local
.env.*.local

# Node
/node_modules/

# System
.DS_Store
Thumbs.db
*.log
*.swp
*.swo
GITIGNORE;

        file_put_contents($gitignorePath, $content);
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }
}
