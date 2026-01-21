<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Git Service fuer Contao 5.3
 * By venne-media.de
 */
class GitService
{
    private string $projectRoot;
    private string $sshKeyDir;
    private bool $safeDirectoryAdded = false;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->projectRoot = $projectDir;
        $this->sshKeyDir = $projectDir . '/var/ssh';
    }

    // ========== SSH Key Management ==========

    public function getSshKeyPath(): string
    {
        return $this->sshKeyDir . '/git_deploy_key';
    }

    public function getSshPublicKeyPath(): string
    {
        return $this->getSshKeyPath() . '.pub';
    }

    public function hasSshKey(): bool
    {
        return file_exists($this->getSshKeyPath()) && file_exists($this->getSshPublicKeyPath());
    }

    public function generateSshKey(string $comment = 'contao-git-push'): array
    {
        // Verzeichnis erstellen falls nicht vorhanden
        if (!is_dir($this->sshKeyDir)) {
            if (!mkdir($this->sshKeyDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => 'Konnte SSH Verzeichnis nicht erstellen: ' . $this->sshKeyDir,
                ];
            }
        }

        $keyPath = $this->getSshKeyPath();

        // Alte Keys loeschen falls vorhanden
        if (file_exists($keyPath)) {
            unlink($keyPath);
        }
        if (file_exists($keyPath . '.pub')) {
            unlink($keyPath . '.pub');
        }

        // SSH Key generieren
        $command = sprintf(
            'ssh-keygen -t ed25519 -C %s -f %s -N "" 2>&1',
            escapeshellarg($comment),
            escapeshellarg($keyPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => 'Fehler beim Generieren des SSH Keys',
                'output' => implode("\n", $output),
            ];
        }

        // Berechtigungen setzen
        chmod($keyPath, 0600);
        chmod($keyPath . '.pub', 0644);

        return [
            'success' => true,
            'message' => 'SSH Key erfolgreich generiert',
            'publicKey' => $this->getPublicKey(),
            'keyPath' => $keyPath,
        ];
    }

    public function getPublicKey(): ?string
    {
        $pubKeyPath = $this->getSshPublicKeyPath();
        if (!file_exists($pubKeyPath)) {
            return null;
        }
        return trim(file_get_contents($pubKeyPath));
    }

    public function getDeployKeyUrl(): ?string
    {
        $remoteUrl = $this->getRemoteUrl();
        if (!$remoteUrl) {
            return null;
        }

        // GitHub SSH: git@github.com:user/repo.git
        if (preg_match('/git@github\.com:([^\/]+)\/(.+)$/', $remoteUrl, $matches)) {
            $owner = $matches[1];
            $repo = preg_replace('/\.git$/', '', $matches[2]);
            return "https://github.com/{$owner}/{$repo}/settings/keys/new";
        }

        // GitHub HTTPS: https://github.com/user/repo.git
        if (preg_match('/https:\/\/github\.com\/([^\/]+)\/(.+)$/', $remoteUrl, $matches)) {
            $owner = $matches[1];
            $repo = preg_replace('/\.git$/', '', $matches[2]);
            return "https://github.com/{$owner}/{$repo}/settings/keys/new";
        }

        // GitLab SSH: git@gitlab.com:user/repo.git
        if (preg_match('/git@gitlab\.com:([^\/]+)\/(.+)$/', $remoteUrl, $matches)) {
            $owner = $matches[1];
            $repo = preg_replace('/\.git$/', '', $matches[2]);
            return "https://gitlab.com/{$owner}/{$repo}/-/settings/repository#js-deploy-keys-settings";
        }

        // GitLab HTTPS
        if (preg_match('/https:\/\/gitlab\.com\/([^\/]+)\/(.+)$/', $remoteUrl, $matches)) {
            $owner = $matches[1];
            $repo = preg_replace('/\.git$/', '', $matches[2]);
            return "https://gitlab.com/{$owner}/{$repo}/-/settings/repository#js-deploy-keys-settings";
        }

        return null;
    }

    public function deleteSshKey(): array
    {
        $keyPath = $this->getSshKeyPath();
        $deleted = false;

        if (file_exists($keyPath)) {
            unlink($keyPath);
            $deleted = true;
        }
        if (file_exists($keyPath . '.pub')) {
            unlink($keyPath . '.pub');
            $deleted = true;
        }

        return [
            'success' => true,
            'message' => $deleted ? 'SSH Key geloescht' : 'Kein SSH Key vorhanden',
        ];
    }

    public function testSshConnection(): array
    {
        if (!$this->hasSshKey()) {
            return [
                'success' => false,
                'message' => 'Kein SSH Key vorhanden. Bitte zuerst generieren.',
            ];
        }

        $remoteUrl = $this->getRemoteUrl();
        if (!$remoteUrl) {
            return [
                'success' => false,
                'message' => 'Keine Remote URL konfiguriert.',
            ];
        }

        // Host aus URL extrahieren
        if (preg_match('/git@([^:]+):/', $remoteUrl, $matches)) {
            $host = $matches[1];
        } elseif (preg_match('/https?:\/\/([^\/]+)/', $remoteUrl, $matches)) {
            return [
                'success' => false,
                'message' => 'HTTPS URL erkannt. SSH Key wird nur fuer SSH URLs benoetigt.',
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Konnte Host nicht aus URL extrahieren: ' . $remoteUrl,
            ];
        }

        $sshCommand = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -T git@%s 2>&1',
            escapeshellarg($this->getSshKeyPath()),
            escapeshellarg($host)
        );

        $output = [];
        $returnCode = 0;
        exec($sshCommand, $output, $returnCode);

        $outputStr = implode("\n", $output);

        // GitHub/GitLab geben Return Code 1 zurueck, aber "successfully authenticated" im Output
        $isAuthenticated = strpos($outputStr, 'successfully authenticated') !== false
            || strpos($outputStr, 'Welcome to GitLab') !== false
            || strpos($outputStr, 'You\'ve successfully authenticated') !== false;

        return [
            'success' => $isAuthenticated,
            'message' => $isAuthenticated
                ? 'SSH Verbindung erfolgreich!'
                : 'SSH Verbindung fehlgeschlagen. Bitte Public Key in GitHub/GitLab hinterlegen.',
            'output' => $outputStr,
        ];
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function isGitRepository(): bool
    {
        return is_dir($this->projectRoot . '/.git');
    }

    public function hasRemote(): bool
    {
        if (!$this->isGitRepository()) {
            return false;
        }

        $result = $this->executeGitCommand('git remote -v');
        return !empty(trim($result['output']));
    }

    public function getRemoteUrl(): ?string
    {
        if (!$this->hasRemote()) {
            return null;
        }

        $result = $this->executeGitCommand('git remote get-url origin');
        return $result['success'] ? trim($result['output']) : null;
    }

    public function hasUserConfig(): bool
    {
        $nameResult = $this->executeGitCommand('git config user.name');
        $emailResult = $this->executeGitCommand('git config user.email');

        return !empty(trim($nameResult['output'])) && !empty(trim($emailResult['output']));
    }

    public function getUserConfig(): array
    {
        $nameResult = $this->executeGitCommand('git config user.name');
        $emailResult = $this->executeGitCommand('git config user.email');

        return [
            'name' => trim($nameResult['output']),
            'email' => trim($emailResult['output']),
        ];
    }

    public function setUserConfig(string $name, string $email): array
    {
        $nameResult = $this->executeGitCommand('git config user.name ' . escapeshellarg($name));
        if (!$nameResult['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Setzen des Benutzernamens',
                'output' => $nameResult['output'],
                'error' => $nameResult['error'],
            ];
        }

        $emailResult = $this->executeGitCommand('git config user.email ' . escapeshellarg($email));
        if (!$emailResult['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Setzen der E-Mail',
                'output' => $emailResult['output'],
                'error' => $emailResult['error'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Git Benutzer erfolgreich konfiguriert',
            'output' => '',
        ];
    }

    public function initRepository(string $remoteUrl, string $branch = 'main', ?string $sshKeyPath = null, ?string $userName = null, ?string $userEmail = null): array
    {
        $commands = [
            'git init',
        ];

        // User Config setzen wenn angegeben
        if ($userName && $userEmail) {
            $commands[] = 'git config user.name ' . escapeshellarg($userName);
            $commands[] = 'git config user.email ' . escapeshellarg($userEmail);
        }

        $commands[] = 'git remote add origin ' . escapeshellarg($remoteUrl);
        $commands[] = 'git branch -M ' . escapeshellarg($branch);

        $results = [];
        foreach ($commands as $command) {
            $result = $this->executeGitCommand($command, $sshKeyPath);
            $results[] = $result;
            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => 'Fehler beim Ausfuehren: ' . $command,
                    'output' => $result['output'],
                    'error' => $result['error'],
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Repository erfolgreich initialisiert',
            'output' => implode("\n", array_column($results, 'output')),
        ];
    }

    /**
     * Klont ein bestehendes Repository (Pull statt Push)
     */
    public function cloneRepository(string $remoteUrl, string $branch = 'main', ?string $userName = null, ?string $userEmail = null): array
    {
        // Zuerst: Temporaer in Unterordner klonen, dann Inhalte verschieben
        $tempDir = $this->projectRoot . '/temp_git_clone_' . uniqid();

        // Clone in temporaeres Verzeichnis
        $cloneResult = $this->executeGitCommand(
            'git clone --branch ' . escapeshellarg($branch) . ' ' . escapeshellarg($remoteUrl) . ' ' . escapeshellarg($tempDir)
        );

        if (!$cloneResult['success']) {
            // Versuche ohne branch (wenn branch nicht existiert)
            $cloneResult = $this->executeGitCommand(
                'git clone ' . escapeshellarg($remoteUrl) . ' ' . escapeshellarg($tempDir)
            );
        }

        if (!$cloneResult['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Klonen des Repositories',
                'output' => $cloneResult['output'],
                'error' => $cloneResult['error'],
            ];
        }

        // .git Ordner verschieben
        $gitDir = $tempDir . '/.git';
        $targetGitDir = $this->projectRoot . '/.git';

        if (is_dir($targetGitDir)) {
            // Altes .git loeschen
            $this->deleteDirectory($targetGitDir);
        }

        // Verschieben
        if (!rename($gitDir, $targetGitDir)) {
            $this->deleteDirectory($tempDir);
            return [
                'success' => false,
                'message' => 'Fehler beim Verschieben des .git Ordners',
            ];
        }

        // Temp Ordner loeschen
        $this->deleteDirectory($tempDir);

        // User Config setzen
        if ($userName && $userEmail) {
            $this->executeGitCommand('git config user.name ' . escapeshellarg($userName));
            $this->executeGitCommand('git config user.email ' . escapeshellarg($userEmail));
        }

        // Reset zum Remote-Stand (holt die Dateien)
        $resetResult = $this->executeGitCommand('git reset --hard origin/' . escapeshellarg($branch));

        return [
            'success' => true,
            'message' => 'Repository erfolgreich geklont. Die Server-Version ist jetzt aktiv.',
            'output' => $cloneResult['output'] . "\n" . ($resetResult['output'] ?? ''),
        ];
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

    /**
     * Holt alle Remote-Branches
     */
    public function getRemoteBranches(): array
    {
        $this->fetch();

        $result = $this->executeGitCommand('git branch -r');

        if (!$result['success']) {
            return [];
        }

        $branches = [];
        $lines = explode("\n", trim($result['output']));

        foreach ($lines as $line) {
            $branch = trim($line);
            // Entferne "origin/" Prefix und ignoriere HEAD
            if (strpos($branch, 'origin/') === 0 && strpos($branch, 'HEAD') === false) {
                $branches[] = substr($branch, 7); // Entferne "origin/"
            }
        }

        return array_unique($branches);
    }

    /**
     * Wechselt zu einem anderen Branch
     */
    public function switchBranch(string $branch): array
    {
        // Zuerst fetch um sicher zu sein dass wir alle Branches haben
        $this->fetch();

        // Pruefen ob lokaler Branch existiert
        $localBranches = $this->getBranches();

        if (in_array($branch, $localBranches)) {
            // Lokaler Branch existiert - einfach wechseln
            $result = $this->executeGitCommand('git checkout ' . escapeshellarg($branch));
        } else {
            // Lokaler Branch existiert nicht - vom Remote erstellen
            $result = $this->executeGitCommand(
                'git checkout -b ' . escapeshellarg($branch) . ' origin/' . escapeshellarg($branch)
            );
        }

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Wechsel zu Branch: ' . $branch,
                'output' => $result['output'],
                'error' => $result['error'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Erfolgreich zu Branch "' . $branch . '" gewechselt',
            'output' => $result['output'],
        ];
    }

    /**
     * Erstellt einen neuen Branch
     */
    public function createBranch(string $branchName, bool $pushToRemote = true): array
    {
        // Validierung: Branch-Name pruefen
        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $branchName)) {
            return [
                'success' => false,
                'message' => 'Ungueltiger Branch-Name. Erlaubt: Buchstaben, Zahlen, -, _, /',
            ];
        }

        // Branch erstellen und wechseln
        $result = $this->executeGitCommand('git checkout -b ' . escapeshellarg($branchName));

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Erstellen des Branches',
                'output' => $result['output'],
                'error' => $result['error'],
            ];
        }

        // Optional: Zum Remote pushen
        if ($pushToRemote) {
            $pushResult = $this->executeGitCommand(
                'git push -u origin ' . escapeshellarg($branchName)
            );

            if (!$pushResult['success']) {
                return [
                    'success' => true,
                    'message' => 'Branch lokal erstellt, aber Push zum Remote fehlgeschlagen',
                    'output' => $result['output'] . "\n" . $pushResult['output'],
                    'error' => $pushResult['error'],
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Branch "' . $branchName . '" erfolgreich erstellt',
            'output' => $result['output'],
        ];
    }

    /**
     * Prueft ob es Remote-Aenderungen gibt (hinter Remote)
     */
    public function hasRemoteChanges(): bool
    {
        $this->fetch();

        $branch = $this->getCurrentBranch();
        $result = $this->executeGitCommand(
            'git rev-list HEAD..origin/' . escapeshellarg($branch) . ' --count'
        );

        if (!$result['success']) {
            return false;
        }

        return (int)trim($result['output']) > 0;
    }

    /**
     * Holt die Anzahl der Commits hinter/vor dem Remote
     */
    public function getRemoteStatus(): array
    {
        $this->fetch();

        $branch = $this->getCurrentBranch();

        // Commits hinter Remote (neue auf Remote)
        $behindResult = $this->executeGitCommand(
            'git rev-list HEAD..origin/' . escapeshellarg($branch) . ' --count'
        );
        $behind = $behindResult['success'] ? (int)trim($behindResult['output']) : 0;

        // Commits vor Remote (neue lokal)
        $aheadResult = $this->executeGitCommand(
            'git rev-list origin/' . escapeshellarg($branch) . '..HEAD --count'
        );
        $ahead = $aheadResult['success'] ? (int)trim($aheadResult['output']) : 0;

        return [
            'behind' => $behind,
            'ahead' => $ahead,
            'synced' => ($behind === 0 && $ahead === 0),
        ];
    }

    public function addRemote(string $remoteUrl): array
    {
        $result = $this->executeGitCommand('git remote add origin ' . escapeshellarg($remoteUrl));

        if (!$result['success']) {
            $result = $this->executeGitCommand('git remote set-url origin ' . escapeshellarg($remoteUrl));
        }

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Remote erfolgreich hinzugefuegt' : 'Fehler beim Hinzufuegen des Remote',
            'output' => $result['output'],
            'error' => $result['error'] ?? '',
        ];
    }

    /**
     * Aendert die Remote-URL
     */
    public function setRemoteUrl(string $remoteUrl): array
    {
        $result = $this->executeGitCommand('git remote set-url origin ' . escapeshellarg($remoteUrl));

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Remote URL erfolgreich geaendert' : 'Fehler beim Aendern der Remote URL',
            'output' => $result['output'],
            'error' => $result['error'] ?? '',
        ];
    }

    /**
     * Benennt einen Branch um
     */
    public function renameBranch(string $oldName, string $newName): array
    {
        // Validierung
        if (!preg_match('/^[a-zA-Z0-9\-_\/]+$/', $newName)) {
            return [
                'success' => false,
                'message' => 'Ungueltiger Branch-Name. Erlaubt: Buchstaben, Zahlen, -, _, /',
            ];
        }

        // Lokalen Branch umbenennen
        $result = $this->executeGitCommand(
            'git branch -m ' . escapeshellarg($oldName) . ' ' . escapeshellarg($newName)
        );

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Umbenennen des Branches',
                'output' => $result['output'],
                'error' => $result['error'],
            ];
        }

        // Remote: Alten Branch loeschen und neuen pushen
        $this->executeGitCommand('git push origin --delete ' . escapeshellarg($oldName));
        $pushResult = $this->executeGitCommand('git push -u origin ' . escapeshellarg($newName));

        return [
            'success' => true,
            'message' => 'Branch von "' . $oldName . '" zu "' . $newName . '" umbenannt',
            'output' => $result['output'] . "\n" . ($pushResult['output'] ?? ''),
        ];
    }

    /**
     * Loescht einen Branch (lokal und remote)
     */
    public function deleteBranch(string $branchName, bool $deleteRemote = true): array
    {
        $currentBranch = $this->getCurrentBranch();

        if ($branchName === $currentBranch) {
            return [
                'success' => false,
                'message' => 'Kann den aktiven Branch nicht loeschen. Bitte zuerst zu einem anderen Branch wechseln.',
            ];
        }

        // Lokalen Branch loeschen
        $result = $this->executeGitCommand('git branch -D ' . escapeshellarg($branchName));

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Loeschen des lokalen Branches',
                'output' => $result['output'],
                'error' => $result['error'],
            ];
        }

        // Remote Branch loeschen wenn gewuenscht
        if ($deleteRemote) {
            $remoteResult = $this->executeGitCommand(
                'git push origin --delete ' . escapeshellarg($branchName)
            );

            if (!$remoteResult['success']) {
                return [
                    'success' => true,
                    'message' => 'Branch lokal geloescht, aber Remote-Loeschung fehlgeschlagen',
                    'output' => $result['output'] . "\n" . $remoteResult['output'],
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Branch "' . $branchName . '" erfolgreich geloescht',
            'output' => $result['output'],
        ];
    }

    public function fetch(?string $sshKeyPath = null): array
    {
        return $this->executeGitCommand('git fetch origin', $sshKeyPath);
    }

    public function pull(string $branch, ?string $sshKeyPath = null): array
    {
        $result = $this->executeGitCommand('git pull origin ' . escapeshellarg($branch), $sshKeyPath);

        if (strpos($result['output'], 'CONFLICT') !== false || strpos($result['error'], 'CONFLICT') !== false) {
            return [
                'success' => false,
                'message' => 'Konflikte beim Pull erkannt! Bitte manuell aufloesen.',
                'output' => $result['output'],
                'error' => $result['error'],
                'hasConflicts' => true,
            ];
        }

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Pull erfolgreich' : 'Fehler beim Pull',
            'output' => $result['output'],
            'error' => $result['error'],
            'hasConflicts' => false,
        ];
    }

    public function getStatus(): array
    {
        $result = $this->executeGitCommand('git status --porcelain');

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Abrufen des Status',
                'output' => $result['output'],
                'error' => $result['error'],
            ];
        }

        $lines = array_filter(explode("\n", trim($result['output'])));
        $changes = [
            'modified' => [],
            'added' => [],
            'deleted' => [],
            'untracked' => [],
        ];

        foreach ($lines as $line) {
            $status = substr($line, 0, 2);
            $file = trim(substr($line, 3));

            if (strpos($status, 'M') !== false) {
                $changes['modified'][] = $file;
            } elseif (strpos($status, 'A') !== false) {
                $changes['added'][] = $file;
            } elseif (strpos($status, 'D') !== false) {
                $changes['deleted'][] = $file;
            } elseif (strpos($status, '?') !== false) {
                $changes['untracked'][] = $file;
            }
        }

        return [
            'success' => true,
            'changes' => $changes,
            'hasChanges' => !empty($lines),
            'output' => $result['output'],
        ];
    }

    public function getBranches(): array
    {
        $result = $this->executeGitCommand('git branch');

        if (!$result['success']) {
            return ['main'];
        }

        $branches = [];
        $lines = explode("\n", trim($result['output']));

        foreach ($lines as $line) {
            $branch = trim(str_replace('*', '', $line));
            if (!empty($branch)) {
                $branches[] = $branch;
            }
        }

        return $branches ?: ['main'];
    }

    public function getCurrentBranch(): string
    {
        $result = $this->executeGitCommand('git branch --show-current');
        return $result['success'] ? trim($result['output']) : 'main';
    }

    public function commitAndPush(string $message, string $branch, ?string $sshKeyPath = null, bool $forcePush = false): array
    {
        // Zuerst ignorierte Dateien aus dem Index entfernen (falls vorher getrackt)
        $this->cleanIgnoredFromIndex();

        $addResult = $this->executeGitCommand('git add .');
        if (!$addResult['success']) {
            return [
                'success' => false,
                'message' => 'Fehler bei git add',
                'output' => $addResult['output'],
                'error' => $addResult['error'],
            ];
        }

        $commitResult = $this->executeGitCommand('git commit -m ' . escapeshellarg($message));
        if (!$commitResult['success'] && strpos($commitResult['output'], 'nothing to commit') === false) {
            return [
                'success' => false,
                'message' => 'Fehler bei git commit',
                'output' => $commitResult['output'],
                'error' => $commitResult['error'],
            ];
        }

        // Force Push wenn aktiviert - Server-Stand hat immer Vorrang
        $forceFlag = $forcePush ? ' --force' : '';
        $pushResult = $this->executeGitCommand(
            'git push' . $forceFlag . ' origin ' . escapeshellarg($branch),
            $sshKeyPath
        );

        if (!$pushResult['success'] && !$forcePush) {
            // Versuche mit --set-upstream
            $pushResult = $this->executeGitCommand(
                'git push --set-upstream origin ' . escapeshellarg($branch),
                $sshKeyPath
            );
        }

        if (!$pushResult['success'] && !$forcePush) {
            // Letzter Versuch: Force Push
            $pushResult = $this->executeGitCommand(
                'git push --force origin ' . escapeshellarg($branch),
                $sshKeyPath
            );
        }

        return [
            'success' => $pushResult['success'],
            'message' => $pushResult['success'] ? 'Commit und Push erfolgreich' : 'Fehler beim Push',
            'output' => $commitResult['output'] . "\n" . $pushResult['output'],
            'error' => $pushResult['error'],
        ];
    }

    public function getLastCommit(): array
    {
        $result = $this->executeGitCommand('git log -1 --format="%H|%s|%ci"');

        if (!$result['success'] || empty(trim($result['output']))) {
            return [
                'success' => false,
                'message' => 'Keine Commits vorhanden',
            ];
        }

        $parts = explode('|', trim($result['output']));

        return [
            'success' => true,
            'hash' => $parts[0] ?? '',
            'shortHash' => substr($parts[0] ?? '', 0, 7),
            'message' => $parts[1] ?? '',
            'date' => $parts[2] ?? '',
        ];
    }

    /**
     * Holt die Commit-Historie (letzte X Commits)
     */
    public function getCommitHistory(int $limit = 20): array
    {
        $result = $this->executeGitCommand(
            'git log --format="%H|%s|%an|%ci" -n ' . (int)$limit
        );

        if (!$result['success'] || empty(trim($result['output']))) {
            return [];
        }

        $commits = [];
        $lines = explode("\n", trim($result['output']));

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = explode('|', $line, 4);
            $commits[] = [
                'hash' => $parts[0] ?? '',
                'shortHash' => substr($parts[0] ?? '', 0, 7),
                'message' => $parts[1] ?? '',
                'author' => $parts[2] ?? '',
                'date' => $parts[3] ?? '',
            ];
        }

        return $commits;
    }

    /**
     * Wechselt zu einem bestimmten Commit (HARD Reset - Achtung!)
     */
    public function checkoutCommit(string $commitHash): array
    {
        // Validierung: Nur gueltige Hashes erlauben
        if (!preg_match('/^[a-f0-9]{7,40}$/i', $commitHash)) {
            return [
                'success' => false,
                'message' => 'Ungueltiger Commit Hash',
            ];
        }

        // Zuerst: Alle lokalen Aenderungen sichern falls vorhanden
        $stashResult = $this->executeGitCommand('git stash');

        // Hard Reset zum gewaehlten Commit
        $result = $this->executeGitCommand('git reset --hard ' . escapeshellarg($commitHash));

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'Fehler beim Wechsel zum Commit',
                'output' => $result['output'],
                'error' => $result['error'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Erfolgreich zu Commit ' . substr($commitHash, 0, 7) . ' gewechselt',
            'output' => $result['output'],
        ];
    }

    /**
     * Stellt den neuesten Stand wieder her (HEAD des Branches)
     */
    public function checkoutLatest(): array
    {
        $branch = $this->getCurrentBranch();

        $result = $this->executeGitCommand('git checkout ' . escapeshellarg($branch));

        if (!$result['success']) {
            // Versuche mit reset
            $result = $this->executeGitCommand('git reset --hard origin/' . escapeshellarg($branch));
        }

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Zurueck zum aktuellen Stand (' . $branch . ')'
                : 'Fehler beim Zuruecksetzen',
            'output' => $result['output'],
            'error' => $result['error'] ?? '',
        ];
    }

    public function getStatusText(): string
    {
        $result = $this->executeGitCommand('git status');
        return $result['output'];
    }

    private function ensureSafeDirectory(): void
    {
        if ($this->safeDirectoryAdded) {
            return;
        }

        exec('git config --global --add safe.directory ' . escapeshellarg($this->projectRoot) . ' 2>&1');
        $this->safeDirectoryAdded = true;
    }

    /**
     * Entfernt ignorierte Dateien aus dem Git-Index (ohne sie zu loeschen).
     * Wichtig wenn .gitignore nachtraeglich hinzugefuegt wurde.
     */
    private function cleanIgnoredFromIndex(): void
    {
        // Dateien/Ordner die NIEMALS ins Git sollen (Contao Best Practice)
        $alwaysIgnore = [
            'vendor/',
            'var/',
            '.env.local',
            'contao-manager/',
            'files/',
            'assets/',
            'node_modules/',
            'public/bundles/',
            'public/assets/',
            'public/share/',
            'public/system/',
            'system/tmp/',
            'system/config/localconfig.php',
        ];

        foreach ($alwaysIgnore as $path) {
            // git rm -r --cached entfernt aus Index, laesst Dateien auf Disk
            $this->executeGitCommand('git rm -r --cached --ignore-unmatch ' . escapeshellarg($path));
        }
    }

    private function executeGitCommand(string $command, ?string $sshKeyPath = null): array
    {
        $this->ensureSafeDirectory();

        $env = '';

        // SSH-Kommandos (push, pull, fetch, clone) brauchen StrictHostKeyChecking=no
        $sshCommands = ['push', 'pull', 'fetch', 'clone', 'ls-remote'];
        $needsSshConfig = false;
        foreach ($sshCommands as $sshCmd) {
            if (strpos($command, 'git ' . $sshCmd) !== false) {
                $needsSshConfig = true;
                break;
            }
        }

        if ($needsSshConfig) {
            // Wenn kein Key-Pfad angegeben, automatisch den generierten Key verwenden
            if (!$sshKeyPath && $this->hasSshKey()) {
                $sshKeyPath = $this->getSshKeyPath();
            }

            if ($sshKeyPath && file_exists($sshKeyPath)) {
                $sshCommand = 'ssh -i ' . escapeshellarg($sshKeyPath) . ' -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
            } else {
                $sshCommand = 'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
            }
            $env = 'GIT_SSH_COMMAND=' . escapeshellarg($sshCommand) . ' ';
        }

        $fullCommand = 'cd ' . escapeshellarg($this->projectRoot) . ' && ' . $env . $command . ' 2>&1';

        $output = [];
        $returnCode = 0;

        exec($fullCommand, $output, $returnCode);

        $outputStr = implode("\n", $output);

        return [
            'success' => $returnCode === 0,
            'output' => $outputStr,
            'error' => $returnCode !== 0 ? $outputStr : '',
            'returnCode' => $returnCode,
        ];
    }
}
