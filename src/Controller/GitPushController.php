<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Controller;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use VennMedia\VmGitPushBundle\Exception\GitConflictException;
use VennMedia\VmGitPushBundle\Exception\GitException;
use VennMedia\VmGitPushBundle\Exception\GitLockException;
use VennMedia\VmGitPushBundle\Exception\ValidationException;
use VennMedia\VmGitPushBundle\Service\GitService;

#[Route('/contao/git-push', name: 'vm_git_push_', defaults: ['_scope' => 'backend'])]
class GitPushController extends AbstractBackendController
{
    public function __construct(
        private readonly GitService $gitService,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->initializeContaoFramework();

        $message = null;
        $messageType = 'info';
        $output = null;

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request);

            try {
                $action = $request->request->getString('action');
                $result = $this->dispatchAction($action, $request);
                $message = $result['message'] ?? null;
                $messageType = $result['type'] ?? 'info';
                $output = $result['output'] ?? null;
            } catch (ValidationException $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            } catch (GitLockException $e) {
                $message = 'Eine andere Git-Operation läuft gerade. Bitte versuchen Sie es in einigen Sekunden erneut.';
                $messageType = 'error';
            } catch (GitConflictException $e) {
                $message = $e->getMessage();
                $messageType = 'error';
                $output = $e->getGitOutput();
            } catch (GitException $e) {
                $message = 'Git Fehler: ' . $e->getMessage();
                $messageType = 'error';
                $output = $e->getGitOutput();
                $this->logger?->error('Git operation failed', [
                    'action' => $request->request->getString('action'),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $message = 'Unerwarteter Fehler aufgetreten. Bitte versuchen Sie es erneut.';
                $messageType = 'error';
                $this->logger?->error('Unexpected error in Git Push controller', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $this->render('@VmGitPush/git_push.html.twig', $this->buildTemplateData($message, $messageType, $output));
    }

    private function validateCsrfToken(Request $request): void
    {
        $token = $request->request->getString('REQUEST_TOKEN');

        if (empty($token)) {
            throw new \RuntimeException('CSRF Token fehlt.');
        }

        $this->csrfTokenManager->getToken('contao_csrf_token');
    }

    private function dispatchAction(string $action, Request $request): array
    {
        return match ($action) {
            'init' => $this->handleInit($request),
            'auto_setup' => $this->handleAutoSetup($request),
            'clone' => $this->handleClone($request),
            'add_remote' => $this->handleAddRemote($request),
            'change_remote_url' => $this->handleChangeRemoteUrl($request),
            'config_user' => $this->handleConfigUser($request),
            'commit_push' => $this->handleCommitAndPush($request),
            'initial_push' => $this->handleInitialPush(),
            'pull' => $this->handlePull(),
            'status' => $this->handleStatus(),
            'generate_ssh_key' => $this->handleGenerateSshKey(),
            'delete_ssh_key' => $this->handleDeleteSshKey(),
            'test_ssh' => $this->handleTestSsh(),
            'checkout_commit' => $this->handleCheckoutCommit($request),
            'checkout_latest' => $this->handleCheckoutLatest(),
            'switch_branch' => $this->handleSwitchBranch($request),
            'create_branch' => $this->handleCreateBranch($request),
            'rename_branch' => $this->handleRenameBranch($request),
            'delete_branch' => $this->handleDeleteBranch($request),
            default => ['message' => 'Unbekannte Aktion: ' . $action, 'type' => 'error'],
        };
    }

    private function buildTemplateData(?string $message, string $messageType, ?string $output): array
    {
        $isGitRepo = $this->gitService->isGitRepository();
        $hasRemote = $this->gitService->hasRemote();

        $sshService = $this->gitService->getSshKeyService();
        $remoteUrl = ($isGitRepo && $hasRemote) ? $this->gitService->getRemoteUrl() : null;

        $templateData = [
            'isGitRepo' => $isGitRepo,
            'hasRemote' => $hasRemote,
            'message' => $message,
            'messageType' => $messageType,
            'output' => $output,
            'projectRoot' => $this->gitService->getProjectRoot(),
            'hasSshKey' => $sshService->hasSshKey(),
            'sshPublicKey' => $sshService->getPublicKey(),
            'deployKeyUrl' => $remoteUrl ? $sshService->getDeployKeyUrl($remoteUrl) : null,
        ];

        if ($isGitRepo) {
            $userConfig = $this->gitService->getUserConfig();
            $templateData['gitUserName'] = $userConfig['name'];
            $templateData['gitUserEmail'] = $userConfig['email'];
            $templateData['needsUserConfig'] = !$this->gitService->hasUserConfig();
        }

        if ($isGitRepo && $hasRemote) {
            $templateData['hasNeverPushed'] = $this->gitService->hasNeverPushed();
            $templateData['branches'] = $this->gitService->getBranches();
            $templateData['remoteBranches'] = $this->gitService->getRemoteBranches();
            $templateData['currentBranch'] = $this->gitService->getCurrentBranch();

            $lastCommit = $this->gitService->getLastCommit();
            $templateData['lastCommit'] = $lastCommit ? [
                'success' => true,
                'shortHash' => $lastCommit->shortHash,
                'message' => $lastCommit->message,
                'date' => $lastCommit->date,
            ] : ['success' => false];

            $status = $this->gitService->getStatus();
            $templateData['status'] = $status->toArray();
            $templateData['remoteUrl'] = $remoteUrl;

            $commits = $this->gitService->getCommitHistory(15);
            $templateData['commitHistory'] = array_map(fn ($c) => $c->toArray(), $commits);

            $templateData['remoteStatus'] = $this->gitService->getRemoteStatus()->toArray();
        }

        return $templateData;
    }

    // ── Action Handlers ───────────────────────────────────────────

    private function handleInit(Request $request): array
    {
        $remoteUrl = trim($request->request->getString('remote_url'));
        $branch = trim($request->request->getString('branch', 'main'));
        $userName = trim($request->request->getString('git_user_name'));
        $userEmail = trim($request->request->getString('git_user_email'));

        $result = $this->gitService->initRepository($remoteUrl, $branch, $userName ?: null, $userEmail ?: null);

        return $this->formatResult($result);
    }

    private function handleAutoSetup(Request $request): array
    {
        $provider = trim($request->request->getString('provider'));
        $token = trim($request->request->getString('api_token'));
        $repoName = trim($request->request->getString('repo_name'));
        $private = (bool) $request->request->get('repo_private', true);
        $branch = trim($request->request->getString('branch', 'main'));
        $userName = trim($request->request->getString('git_user_name'));
        $userEmail = trim($request->request->getString('git_user_email'));

        if (!in_array($provider, ['github', 'gitlab'], true)) {
            return ['message' => 'Bitte wählen Sie GitHub oder GitLab.', 'type' => 'error'];
        }

        $result = $this->gitService->autoSetupRepository(
            $provider, $token, $repoName, $private, $branch, $userName, $userEmail
        );

        return $this->formatResult($result);
    }

    private function handleClone(Request $request): array
    {
        $remoteUrl = trim($request->request->getString('remote_url'));
        $branch = trim($request->request->getString('branch', 'main'));
        $userName = trim($request->request->getString('git_user_name'));
        $userEmail = trim($request->request->getString('git_user_email'));

        $result = $this->gitService->cloneRepository($remoteUrl, $branch, $userName ?: null, $userEmail ?: null);

        return $this->formatResult($result);
    }

    private function handleAddRemote(Request $request): array
    {
        $remoteUrl = trim($request->request->getString('remote_url'));
        $result = $this->gitService->addRemote($remoteUrl);

        return $this->formatResult($result);
    }

    private function handleChangeRemoteUrl(Request $request): array
    {
        $remoteUrl = trim($request->request->getString('remote_url'));
        $result = $this->gitService->setRemoteUrl($remoteUrl);

        return $this->formatResult($result);
    }

    private function handleConfigUser(Request $request): array
    {
        $userName = trim($request->request->getString('git_user_name'));
        $userEmail = trim($request->request->getString('git_user_email'));

        $result = $this->gitService->setUserConfig($userName, $userEmail);

        return $this->formatResult($result);
    }

    private function handleCommitAndPush(Request $request): array
    {
        $commitMessage = trim($request->request->getString('commit_message'));
        $branch = trim($request->request->getString('branch', 'main'));

        $result = $this->gitService->commitAndPush($commitMessage, $branch);

        return $this->formatResult($result);
    }

    private function handleInitialPush(): array
    {
        $result = $this->gitService->initialPush();

        return $this->formatResult($result);
    }

    private function handlePull(): array
    {
        $branch = $this->gitService->getCurrentBranch();
        $this->gitService->fetch();
        $result = $this->gitService->pull($branch);

        return $this->formatResult($result);
    }

    private function handleStatus(): array
    {
        return [
            'message' => 'Git Status abgerufen',
            'type' => 'info',
            'output' => $this->gitService->getStatusText(),
        ];
    }

    private function handleGenerateSshKey(): array
    {
        $result = $this->gitService->getSshKeyService()->generateSshKey();

        $output = $result->output;
        if ($result->success) {
            $publicKey = $this->gitService->getSshKeyService()->getPublicKey();
            $output = "SSH Key erfolgreich generiert!\n\nPublic Key (kopieren und in GitHub/GitLab als Deploy Key hinzufügen):\n\n" . ($publicKey ?? '');
        }

        return [
            'message' => $result->message,
            'type' => $result->success ? 'success' : 'error',
            'output' => $output,
        ];
    }

    private function handleDeleteSshKey(): array
    {
        $result = $this->gitService->getSshKeyService()->deleteSshKey();

        return $this->formatResult($result);
    }

    private function handleTestSsh(): array
    {
        $remoteUrl = $this->gitService->getRemoteUrl();
        if (!$remoteUrl) {
            return ['message' => 'Keine Remote URL konfiguriert.', 'type' => 'error'];
        }

        $result = $this->gitService->getSshKeyService()->testSshConnection($remoteUrl);

        return [
            'message' => $result->message,
            'type' => $result->success ? 'success' : 'error',
            'output' => $result->output,
        ];
    }

    private function handleCheckoutCommit(Request $request): array
    {
        $commitHash = trim($request->request->getString('commit_hash'));
        $result = $this->gitService->checkoutCommit($commitHash);

        return $this->formatResult($result);
    }

    private function handleCheckoutLatest(): array
    {
        $result = $this->gitService->checkoutLatest();

        return $this->formatResult($result);
    }

    private function handleSwitchBranch(Request $request): array
    {
        $branch = trim($request->request->getString('branch'));
        $result = $this->gitService->switchBranch($branch);

        return $this->formatResult($result);
    }

    private function handleCreateBranch(Request $request): array
    {
        $branchName = trim($request->request->getString('branch_name'));
        $result = $this->gitService->createBranch($branchName);

        return $this->formatResult($result);
    }

    private function handleRenameBranch(Request $request): array
    {
        $oldName = trim($request->request->getString('old_branch_name'));
        $newName = trim($request->request->getString('new_branch_name'));

        $result = $this->gitService->renameBranch($oldName, $newName);

        return $this->formatResult($result);
    }

    private function handleDeleteBranch(Request $request): array
    {
        $branchName = trim($request->request->getString('branch_name'));
        $result = $this->gitService->deleteBranch($branchName);

        return $this->formatResult($result);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function formatResult(\VennMedia\VmGitPushBundle\Dto\GitResult $result): array
    {
        return [
            'message' => $result->message,
            'type' => $result->success ? 'success' : 'error',
            'output' => $result->output ?: ($result->error ?: ''),
        ];
    }
}
