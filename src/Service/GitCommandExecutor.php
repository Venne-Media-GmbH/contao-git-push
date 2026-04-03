<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VennMedia\VmGitPushBundle\Exception\GitException;
use VennMedia\VmGitPushBundle\Exception\GitLockException;

class GitCommandExecutor
{
    private string $projectRoot;
    private string $sshKeyDir;
    private string $lockFile;
    private bool $safeDirectoryAdded = false;

    private const SSH_COMMANDS = ['push', 'pull', 'fetch', 'clone', 'ls-remote'];
    private const ALLOWED_GIT_COMMANDS = [
        'init', 'clone', 'add', 'commit', 'push', 'pull', 'fetch',
        'status', 'branch', 'checkout', 'log', 'remote', 'config',
        'stash', 'reset', 'rm', 'rev-list', 'diff', 'rebase', 'merge',
    ];
    private const COMMAND_TIMEOUT = 120;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->projectRoot = $projectDir;
        $this->sshKeyDir = $projectDir . '/var/ssh';
        $this->lockFile = $projectDir . '/var/git.lock';
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function getSshKeyDir(): string
    {
        return $this->sshKeyDir;
    }

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

    /**
     * Execute a Git command with proper environment, locking, and logging.
     *
     * @return array{success: bool, output: string, error: string, returnCode: int}
     */
    public function execute(string $command, bool $useLock = false): array
    {
        $this->validateCommand($command);
        $this->ensureSafeDirectory();

        if ($useLock) {
            return $this->executeWithLock($command);
        }

        return $this->doExecute($command);
    }

    /**
     * Execute a raw shell command (for ssh-keygen etc.) - NOT a git command.
     *
     * @return array{success: bool, output: string, returnCode: int}
     */
    public function executeRaw(string $command): array
    {
        $this->logger?->debug('Executing raw command', ['command' => $this->sanitizeForLog($command)]);

        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'returnCode' => $returnCode,
        ];
    }

    private function validateCommand(string $command): void
    {
        if (!str_starts_with($command, 'git ')) {
            throw new GitException('Only git commands are allowed.');
        }

        $parts = explode(' ', $command);
        $subCommand = $parts[1] ?? '';

        if (!in_array($subCommand, self::ALLOWED_GIT_COMMANDS, true)) {
            throw new GitException('Git subcommand not allowed: ' . $subCommand);
        }
    }

    private function executeWithLock(string $command): array
    {
        $lockDir = dirname($this->lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }

        $lockHandle = fopen($this->lockFile, 'w');
        if (!$lockHandle) {
            throw new GitException('Cannot create lock file.');
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            throw new GitLockException();
        }

        try {
            $result = $this->doExecute($command);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return $result;
    }

    private function doExecute(string $command): array
    {
        $sshCommand = $this->buildSshCommand($command);

        $this->logger?->info('Git command', [
            'command' => $this->sanitizeForLog($command),
        ]);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Pass SSH config via environment variables (cross-platform)
        $envVars = null;
        if ($sshCommand !== null) {
            $envVars = array_merge($_ENV, getenv() ?: [], ['GIT_SSH_COMMAND' => $sshCommand]);
        }

        $process = proc_open($command, $descriptorSpec, $pipes, $this->projectRoot, $envVars);

        if (!is_resource($process)) {
            throw new GitException('Failed to start git process for: ' . $this->sanitizeForLog($command));
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $returnCode = $status['exitcode'];
                break;
            }

            if ((time() - $startTime) > self::COMMAND_TIMEOUT) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new GitException(
                    'Git command timed out after ' . self::COMMAND_TIMEOUT . ' seconds.',
                    $stdout . "\n" . $stderr,
                    124
                );
            }

            usleep(50000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Use rtrim to preserve leading whitespace (important for git status --porcelain)
        $combined = $stdout ?: $stderr;
        $outputStr = rtrim($combined, "\r\n\t ");

        if ($returnCode !== 0) {
            $this->logger?->warning('Git command failed', [
                'command' => $this->sanitizeForLog($command),
                'returnCode' => $returnCode,
                'output' => mb_substr($outputStr, 0, 500),
            ]);
        }

        return [
            'success' => $returnCode === 0,
            'output' => $outputStr,
            'error' => $returnCode !== 0 ? $outputStr : '',
            'returnCode' => $returnCode,
        ];
    }

    private function buildSshCommand(string $command): ?string
    {
        $needsSsh = false;
        foreach (self::SSH_COMMANDS as $sshCmd) {
            if (str_contains($command, 'git ' . $sshCmd)) {
                $needsSsh = true;
                break;
            }
        }

        if (!$needsSsh) {
            return null;
        }

        // Check if the remote is SSH-based (skip for local/file paths)
        if (!$this->isRemoteSsh()) {
            return null;
        }

        $sshKeyPath = $this->getSshKeyPath();
        $knownHostsFile = $this->sshKeyDir . '/known_hosts';
        $sshOptions = '-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg($knownHostsFile);

        if ($this->hasSshKey()) {
            return 'ssh -i ' . escapeshellarg($sshKeyPath) . ' ' . $sshOptions;
        }

        return 'ssh ' . $sshOptions;
    }

    private function isRemoteSsh(): bool
    {
        $result = [];
        $rc = 0;
        exec('cd ' . escapeshellarg($this->projectRoot) . ' && git remote get-url origin 2>&1', $result, $rc);

        if ($rc !== 0 || empty($result)) {
            return false;
        }

        $url = trim($result[0]);

        // Local paths and file:// URLs don't need SSH
        if (str_starts_with($url, '/') || str_starts_with($url, 'file://') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $url)) {
            return false;
        }

        return str_contains($url, 'git@') || str_starts_with($url, 'ssh://');
    }

    private function ensureSafeDirectory(): void
    {
        if ($this->safeDirectoryAdded) {
            return;
        }

        exec('git config --global --add safe.directory ' . escapeshellarg($this->projectRoot) . ' 2>&1');
        $this->safeDirectoryAdded = true;
    }

    private function sanitizeForLog(string $command): string
    {
        return preg_replace('/(-i\s+)\S+/', '$1[SSH_KEY]', $command);
    }
}
