<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Service;

use Psr\Log\LoggerInterface;
use VennMedia\VmGitPushBundle\Dto\GitResult;

class SshKeyService
{
    public function __construct(
        private readonly GitCommandExecutor $executor,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function hasSshKey(): bool
    {
        return $this->executor->hasSshKey();
    }

    public function getPublicKey(): ?string
    {
        $pubKeyPath = $this->executor->getSshPublicKeyPath();
        if (!file_exists($pubKeyPath)) {
            return null;
        }

        return trim(file_get_contents($pubKeyPath));
    }

    public function generateSshKey(string $comment = 'contao-git-push'): GitResult
    {
        $sshKeyDir = $this->executor->getSshKeyDir();

        if (!is_dir($sshKeyDir)) {
            if (!mkdir($sshKeyDir, 0700, true)) {
                return GitResult::failure('Konnte SSH Verzeichnis nicht erstellen: ' . $sshKeyDir);
            }
        }

        $keyPath = $this->executor->getSshKeyPath();

        if (file_exists($keyPath)) {
            unlink($keyPath);
        }
        if (file_exists($keyPath . '.pub')) {
            unlink($keyPath . '.pub');
        }

        $command = sprintf(
            'ssh-keygen -t ed25519 -C %s -f %s -N ""',
            escapeshellarg($comment),
            escapeshellarg($keyPath)
        );

        $result = $this->executor->executeRaw($command);

        if (!$result['success']) {
            $this->logger?->error('SSH key generation failed', ['output' => $result['output']]);

            return GitResult::failure('Fehler beim Generieren des SSH Keys', $result['output']);
        }

        chmod($keyPath, 0600);
        chmod($keyPath . '.pub', 0644);

        $knownHostsDir = $sshKeyDir;
        $knownHostsFile = $knownHostsDir . '/known_hosts';
        if (!file_exists($knownHostsFile)) {
            touch($knownHostsFile);
            chmod($knownHostsFile, 0600);
        }

        $this->logger?->info('SSH key generated successfully');

        return GitResult::success('SSH Key erfolgreich generiert', $this->getPublicKey() ?? '');
    }

    public function deleteSshKey(): GitResult
    {
        $keyPath = $this->executor->getSshKeyPath();
        $deleted = false;

        if (file_exists($keyPath)) {
            unlink($keyPath);
            $deleted = true;
        }
        if (file_exists($keyPath . '.pub')) {
            unlink($keyPath . '.pub');
            $deleted = true;
        }

        $this->logger?->info('SSH key deleted', ['existed' => $deleted]);

        return GitResult::success($deleted ? 'SSH Key gelöscht' : 'Kein SSH Key vorhanden');
    }

    public function testSshConnection(string $remoteUrl): GitResult
    {
        if (!$this->hasSshKey()) {
            return GitResult::failure('Kein SSH Key vorhanden. Bitte zuerst generieren.');
        }

        if (preg_match('/git@([^:]+):/', $remoteUrl, $matches)) {
            $host = $matches[1];
        } elseif (preg_match('/https?:\/\/([^\/]+)/', $remoteUrl)) {
            return GitResult::failure('HTTPS URL erkannt. SSH Key wird nur für SSH URLs benötigt.');
        } else {
            return GitResult::failure('Konnte Host nicht aus URL extrahieren.');
        }

        $sshKeyPath = $this->executor->getSshKeyPath();
        $sshKeyDir = $this->executor->getSshKeyDir();
        $command = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s -o ConnectTimeout=10 -T git@%s',
            escapeshellarg($sshKeyPath),
            escapeshellarg($sshKeyDir . '/known_hosts'),
            escapeshellarg($host)
        );

        $result = $this->executor->executeRaw($command);
        $outputStr = $result['output'];

        $isAuthenticated = str_contains($outputStr, 'successfully authenticated')
            || str_contains($outputStr, 'Welcome to GitLab')
            || str_contains($outputStr, 'You\'ve successfully authenticated');

        $this->logger?->info('SSH connection test', ['host' => $host, 'success' => $isAuthenticated]);

        return new GitResult(
            $isAuthenticated,
            $isAuthenticated
                ? 'SSH Verbindung erfolgreich!'
                : 'SSH Verbindung fehlgeschlagen. Bitte Public Key in GitHub/GitLab hinterlegen.',
            $outputStr
        );
    }

    public function getDeployKeyUrl(string $remoteUrl): ?string
    {
        $patterns = [
            '/git@github\.com:([^\/]+)\/(.+)$/' => 'https://github.com/%s/%s/settings/keys/new',
            '/https:\/\/github\.com\/([^\/]+)\/(.+)$/' => 'https://github.com/%s/%s/settings/keys/new',
            '/git@gitlab\.com:([^\/]+)\/(.+)$/' => 'https://gitlab.com/%s/%s/-/settings/repository#js-deploy-keys-settings',
            '/https:\/\/gitlab\.com\/([^\/]+)\/(.+)$/' => 'https://gitlab.com/%s/%s/-/settings/repository#js-deploy-keys-settings',
        ];

        foreach ($patterns as $pattern => $urlTemplate) {
            if (preg_match($pattern, $remoteUrl, $matches)) {
                $owner = $matches[1];
                $repo = preg_replace('/\.git$/', '', $matches[2]);

                return sprintf($urlTemplate, $owner, $repo);
            }
        }

        return null;
    }
}
