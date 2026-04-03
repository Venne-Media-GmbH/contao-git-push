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
        private readonly GitHostingApiService $hostingApi,
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

    public function getHostingApi(): GitHostingApiService
    {
        return $this->hostingApi;
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

        // 1. Git init + Konfiguration
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
                    'Fehler beim Ausführen: ' . $command,
                    $result['output'],
                    $result['error']
                );
            }
        }

        // 2. SSH Key automatisch generieren (falls SSH-URL und noch kein Key vorhanden)
        $sshKeyGenerated = false;
        if (str_contains($remoteUrl, 'git@') && !$this->sshKeyService->hasSshKey()) {
            $sshResult = $this->sshKeyService->generateSshKey();
            $sshKeyGenerated = $sshResult->success;
        }

        // 3. Ersten Commit erstellen
        $this->executor->execute('git add .');
        $this->executor->execute('git commit -m "Initiales Setup via GIT Connect"');

        $this->logger?->info('Repository initialized', ['remote' => $remoteUrl, 'branch' => $branch]);

        // 4. Push versuchen
        $pushResult = $this->executor->execute('git push -u origin ' . escapeshellarg($branch), useLock: true);

        if ($pushResult['success']) {
            return GitResult::success(
                'Repository erfolgreich eingerichtet und erster Push durchgeführt!',
                implode("\n", $allOutput)
            );
        }

        // Push fehlgeschlagen - wahrscheinlich muss noch der Deploy Key eingetragen werden
        if ($sshKeyGenerated) {
            $publicKey = $this->sshKeyService->getPublicKey();
            $deployUrl = $this->sshKeyService->getDeployKeyUrl($remoteUrl);

            $hint = "Repository eingerichtet! Der erste Push steht noch aus.\n\n";
            $hint .= "Nächster Schritt: Tragen Sie diesen SSH Key als Deploy Key (mit Schreibrechten) ein:\n\n";
            $hint .= $publicKey . "\n\n";
            if ($deployUrl) {
                $hint .= "Link: " . $deployUrl . "\n\n";
            }
            $hint .= "Klicken Sie danach auf \"Änderungen speichern & synchronisieren\".";

            return new GitResult(true, 'Repository eingerichtet! SSH Key muss noch hinterlegt werden.', $hint);
        }

        return GitResult::success(
            'Repository eingerichtet. Erster Push konnte nicht durchgeführt werden - bitte manuell pushen.',
            implode("\n", $allOutput) . "\n" . $pushResult['output']
        );
    }

    /**
     * Vollautomatisches Setup: Repo auf GitHub/GitLab erstellen, SSH Key generieren,
     * Deploy Key eintragen, git init, erster Commit, erster Push - alles in einem Schritt.
     */
    public function autoSetupRepository(
        string $provider,
        string $token,
        string $repoName,
        bool $private,
        string $branch,
        string $userName,
        string $userEmail,
    ): GitResult {
        $this->validator->validateBranchName($branch);
        $this->validator->validateUserName($userName);
        $this->validator->validateUserEmail($userEmail);

        if (empty(trim($token))) {
            return GitResult::failure('Bitte geben Sie einen API Token ein.');
        }

        if (empty(trim($repoName))) {
            return GitResult::failure('Bitte geben Sie einen Repository-Namen ein.');
        }

        $steps = [];

        // 1. Token validieren
        if ($provider === 'github') {
            $tokenCheck = $this->hostingApi->validateGitHubToken($token);
        } else {
            $tokenCheck = $this->hostingApi->validateGitLabToken($token);
        }

        if (!$tokenCheck['success']) {
            return GitResult::failure('API Token ist ungültig. Bitte prüfen Sie den Token und die Berechtigungen.');
        }
        $steps[] = 'Token geprüft (' . $tokenCheck['username'] . ')';

        // 2. Repository erstellen
        if ($provider === 'github') {
            $repoResult = $this->hostingApi->createGitHubRepo($token, $repoName, $private);
        } else {
            $repoResult = $this->hostingApi->createGitLabRepo($token, $repoName, $private);
        }

        if (!$repoResult['success']) {
            return GitResult::failure($repoResult['message']);
        }
        $steps[] = 'Repository erstellt: ' . ($repoResult['full_name'] ?? $repoName);

        $sshUrl = $repoResult['ssh_url'];
        if (!$sshUrl) {
            return GitResult::failure('Repository erstellt, aber keine SSH-URL erhalten.');
        }

        // 3. SSH Key generieren
        if (!$this->sshKeyService->hasSshKey()) {
            $sshResult = $this->sshKeyService->generateSshKey();
            if (!$sshResult->success) {
                return GitResult::failure('Repository erstellt, aber SSH Key konnte nicht generiert werden.');
            }
            $steps[] = 'SSH Key generiert';
        } else {
            $steps[] = 'SSH Key bereits vorhanden';
        }

        // 4. Deploy Key automatisch eintragen
        $publicKey = $this->sshKeyService->getPublicKey();
        if ($provider === 'github') {
            $deployResult = $this->hostingApi->addGitHubDeployKey($token, $repoResult['full_name'], $publicKey);
        } else {
            $deployResult = $this->hostingApi->addGitLabDeployKey($token, $repoResult['project_id'], $publicKey);
        }

        if (!$deployResult['success']) {
            return GitResult::failure('Repository erstellt, aber Deploy Key konnte nicht eingetragen werden: ' . $deployResult['message']);
        }
        $steps[] = 'Deploy Key eingetragen (Schreibrechte)';

        // 5. Git init + config + remote
        $this->createDefaultGitignore();
        $commands = [
            'git init',
            'git config user.name ' . escapeshellarg($userName),
            'git config user.email ' . escapeshellarg($userEmail),
            'git remote add origin ' . escapeshellarg($sshUrl),
            'git branch -M ' . escapeshellarg($branch),
        ];

        foreach ($commands as $command) {
            $result = $this->executor->execute($command);
            if (!$result['success']) {
                return GitResult::failure('Git-Konfiguration fehlgeschlagen: ' . $result['output']);
            }
        }
        $steps[] = 'Lokales Repository konfiguriert';

        // 6. Erster Commit + Push
        $this->executor->execute('git add .');
        $this->executor->execute('git commit -m "Initiales Setup via GIT Connect"');

        // Kurz warten damit GitHub/GitLab den Deploy Key aktiviert hat
        sleep(2);

        $pushResult = $this->executor->execute('git push -u origin ' . escapeshellarg($branch), useLock: true);

        if (!$pushResult['success']) {
            // Zweiter Versuch nach 3 Sekunden
            sleep(3);
            $pushResult = $this->executor->execute('git push -u origin ' . escapeshellarg($branch), useLock: true);
        }

        if ($pushResult['success']) {
            $steps[] = 'Erster Push erfolgreich';
        } else {
            $steps[] = 'Erster Push fehlgeschlagen (kann später manuell wiederholt werden)';
        }

        $this->logger?->info('Auto-setup completed', [
            'provider' => $provider,
            'repo' => $repoResult['full_name'] ?? $repoName,
            'push_success' => $pushResult['success'],
        ]);

        $summary = implode("\n", array_map(fn ($s, $i) => ($i + 1) . '. ' . $s, $steps, array_keys($steps)));

        if ($pushResult['success']) {
            return GitResult::success(
                'Repository vollständig eingerichtet! Alles ist bereit.',
                $summary . "\n\nRepository: " . ($repoResult['html_url'] ?? $sshUrl)
            );
        }

        return new GitResult(
            true,
            'Repository erstellt und konfiguriert. Der erste Push steht noch aus - versuchen Sie es gleich erneut.',
            $summary
        );
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
                    'Branch gewechselt, aber es gibt Konflikte mit den gestashten Änderungen. Bitte manuell auflösen.',
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
            return GitResult::failure('Der Branch "' . $oldName . '" ist geschützt und kann nicht umbenannt werden.');
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
            return GitResult::failure('Fehler beim Löschen des lokalen Branches', $result['output'], $result['error']);
        }

        if ($deleteRemote) {
            $remoteResult = $this->executor->execute(
                'git push origin --delete ' . escapeshellarg($branchName),
                useLock: true
            );

            if (!$remoteResult['success']) {
                return new GitResult(
                    true,
                    'Branch lokal gelöscht, aber Remote-Löschung fehlgeschlagen',
                    $result['output'] . "\n" . $remoteResult['output']
                );
            }
        }

        $this->logger?->info('Branch deleted', ['branch' => $branchName, 'remote' => $deleteRemote]);

        return GitResult::success('Branch "' . $branchName . '" erfolgreich gelöscht', $result['output']);
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
            $result['success'] ? 'Remote erfolgreich hinzugefügt' : 'Fehler beim Hinzufügen des Remote',
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
            $result['success'] ? 'Remote URL erfolgreich geändert' : 'Fehler beim Ändern der Remote URL',
            $result['output'],
            $result['error'] ?? ''
        );
    }

    public function fetch(): GitResult
    {
        $result = $this->executor->execute('git fetch origin');

        return new GitResult($result['success'], '', $result['output'], $result['error'] ?? '');
    }

    /**
     * Prüft ob der Branch noch nie zum Remote gepusht wurde (initialer Push ausständig).
     */
    public function hasNeverPushed(): bool
    {
        if (!$this->isGitRepository() || !$this->hasRemote()) {
            return false;
        }

        $branch = $this->getCurrentBranch();
        $result = $this->executor->execute('git rev-parse --verify origin/' . escapeshellarg($branch));

        return !$result['success'];
    }

    /**
     * Führt den initialen Push durch (nach dem der Deploy Key eingetragen wurde).
     */
    public function initialPush(): GitResult
    {
        $branch = $this->getCurrentBranch();

        $pushResult = $this->executor->execute(
            'git push -u origin ' . escapeshellarg($branch),
            useLock: true
        );

        if ($pushResult['success']) {
            $this->logger?->info('Initial push successful', ['branch' => $branch]);

            return GitResult::success('Erster Push erfolgreich! Das Repository ist jetzt vollständig eingerichtet.');
        }

        return GitResult::failure(
            'Push fehlgeschlagen. Bitte prüfen Sie, ob der SSH Key korrekt als Deploy Key (mit Schreibrechten!) eingetragen ist.',
            $pushResult['output'],
            $pushResult['error']
        );
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

    public function commitAndPush(string $message, string $branch): GitResult
    {
        $this->validator->validateCommitMessage($message);
        $this->validator->validateBranchName($branch);

        $this->cleanIgnoredFromIndex();

        // 1. Alle Änderungen stagen
        $addResult = $this->executor->execute('git add .', useLock: true);
        if (!$addResult['success']) {
            return GitResult::failure('Fehler bei git add', $addResult['output'], $addResult['error']);
        }

        // 2. Commit
        $commitResult = $this->executor->execute('git commit -m ' . escapeshellarg($message), useLock: true);
        if (!$commitResult['success'] && !str_contains($commitResult['output'], 'nothing to commit')) {
            return GitResult::failure('Fehler bei git commit', $commitResult['output'], $commitResult['error']);
        }

        // 3. Zuerst Remote-Änderungen holen und unseren Commit oben drauf setzen (Rebase)
        //    Das sorgt dafür, dass Entwickler-Änderungen und CMS-Änderungen sauber zusammengeführt werden.
        $this->executor->execute('git fetch origin', useLock: true);

        $remoteCheck = $this->executor->execute('git rev-list HEAD..origin/' . escapeshellarg($branch) . ' --count');
        $behindCount = $remoteCheck['success'] ? (int) trim($remoteCheck['output']) : 0;

        if ($behindCount > 0) {
            $this->logger?->info('Remote has newer changes, rebasing before push', [
                'branch' => $branch,
                'behind' => $behindCount,
            ]);

            $pullResult = $this->executor->execute(
                'git pull --rebase origin ' . escapeshellarg($branch),
                useLock: true
            );

            if (!$pullResult['success']) {
                // Rebase-Konflikt: automatisch abbrechen, damit nichts kaputt geht
                $this->executor->execute('git rebase --abort');
                $this->logger?->warning('Rebase conflict detected', ['branch' => $branch]);

                return GitResult::failure(
                    'Es gibt Konflikte zwischen Ihren Änderungen und denen des Entwicklers. '
                    . 'Die gleiche Datei wurde an der gleichen Stelle geändert. '
                    . 'Bitte kontaktieren Sie den Entwickler, um dies aufzulösen.',
                    $commitResult['output'] . "\n" . $pullResult['output'],
                    $pullResult['error']
                );
            }
        }

        // 4. Push
        $pushResult = $this->executor->execute(
            'git push origin ' . escapeshellarg($branch),
            useLock: true
        );

        // Falls Branch noch nicht auf Remote existiert
        if (!$pushResult['success']) {
            $pushResult = $this->executor->execute(
                'git push --set-upstream origin ' . escapeshellarg($branch),
                useLock: true
            );
        }

        if (!$pushResult['success']) {
            $this->logger?->error('Push failed', [
                'branch' => $branch,
                'output' => mb_substr($pushResult['output'], 0, 500),
            ]);

            return GitResult::failure(
                'Push fehlgeschlagen. Bitte versuchen Sie es erneut oder kontaktieren Sie den Entwickler.',
                $commitResult['output'] . "\n" . $pushResult['output'],
                $pushResult['error']
            );
        }

        $this->logger?->info('Commit and push successful', ['branch' => $branch]);

        $syncMsg = $behindCount > 0
            ? 'Änderungen erfolgreich gespeichert! (' . $behindCount . ' Änderung(en) vom Entwickler wurden automatisch zusammengeführt)'
            : 'Änderungen erfolgreich gespeichert und synchronisiert!';

        return GitResult::success($syncMsg, $commitResult['output'] . "\n" . $pushResult['output']);
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
                . 'Bitte kontaktieren Sie den Entwickler, um die Konflikte aufzulösen.',
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
                    'Pull erfolgreich, aber es gibt Konflikte mit lokalen Änderungen. Bitte manuell prüfen.',
                    $result['output'] . "\n" . $stashResult['output']
                );
            }
        }

        $this->logger?->info('Pull successful', ['branch' => $branch]);

        return GitResult::success('Änderungen vom Server erfolgreich geholt!', $result['output']);
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
                ? 'Zurück zum aktuellen Stand (' . $branch . ')'
                : 'Fehler beim Zurücksetzen',
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
