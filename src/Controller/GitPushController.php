<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Controller;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use VennMedia\VmGitPushBundle\Service\GitService;

#[Route('/contao/git-push', name: 'vm_git_push_', defaults: ['_scope' => 'backend'])]
class GitPushController extends AbstractBackendController
{
    public function __construct(private readonly GitService $gitService)
    {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->initializeContaoFramework();

        $isGitRepo = $this->gitService->isGitRepository();
        $hasRemote = $this->gitService->hasRemote();

        $message = null;
        $messageType = 'info';
        $output = null;

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            switch ($action) {
                case 'init':
                    $result = $this->handleInit($request);
                    break;
                case 'add_remote':
                    $result = $this->handleAddRemote($request);
                    break;
                case 'commit_push':
                    $result = $this->handleCommitAndPush($request);
                    break;
                case 'pull':
                    $result = $this->handlePull();
                    break;
                case 'status':
                    $result = $this->handleStatus();
                    break;
                case 'config_user':
                    $result = $this->handleConfigUser($request);
                    break;
                case 'generate_ssh_key':
                    $result = $this->handleGenerateSshKey();
                    break;
                case 'delete_ssh_key':
                    $result = $this->handleDeleteSshKey();
                    break;
                case 'test_ssh':
                    $result = $this->handleTestSsh();
                    break;
                case 'checkout_commit':
                    $result = $this->handleCheckoutCommit($request);
                    break;
                case 'checkout_latest':
                    $result = $this->handleCheckoutLatest();
                    break;
                case 'clone':
                    $result = $this->handleClone($request);
                    break;
                case 'switch_branch':
                    $result = $this->handleSwitchBranch($request);
                    break;
                case 'create_branch':
                    $result = $this->handleCreateBranch($request);
                    break;
                case 'rename_branch':
                    $result = $this->handleRenameBranch($request);
                    break;
                case 'delete_branch':
                    $result = $this->handleDeleteBranch($request);
                    break;
                case 'change_remote_url':
                    $result = $this->handleChangeRemoteUrl($request);
                    break;
                default:
                    $result = ['message' => 'Unbekannte Aktion', 'type' => 'error'];
            }

            $message = $result['message'] ?? null;
            $messageType = $result['type'] ?? 'info';
            $output = $result['output'] ?? null;

            $isGitRepo = $this->gitService->isGitRepository();
            $hasRemote = $this->gitService->hasRemote();
        }

        $templateData = [
            'isGitRepo' => $isGitRepo,
            'hasRemote' => $hasRemote,
            'message' => $message,
            'messageType' => $messageType,
            'output' => $output,
            'projectRoot' => $this->gitService->getProjectRoot(),
            'hasSshKey' => $this->gitService->hasSshKey(),
            'sshPublicKey' => $this->gitService->getPublicKey(),
            'deployKeyUrl' => $this->gitService->getDeployKeyUrl(),
        ];

        if ($isGitRepo) {
            $userConfig = $this->gitService->getUserConfig();
            $templateData['gitUserName'] = $userConfig['name'];
            $templateData['gitUserEmail'] = $userConfig['email'];
            $templateData['needsUserConfig'] = !$this->gitService->hasUserConfig();
        }

        if ($isGitRepo && $hasRemote) {
            $templateData['branches'] = $this->gitService->getBranches();
            $templateData['remoteBranches'] = $this->gitService->getRemoteBranches();
            $templateData['currentBranch'] = $this->gitService->getCurrentBranch();
            $templateData['lastCommit'] = $this->gitService->getLastCommit();
            $templateData['status'] = $this->gitService->getStatus();
            $templateData['remoteUrl'] = $this->gitService->getRemoteUrl();
            $templateData['commitHistory'] = $this->gitService->getCommitHistory(15);
            $templateData['remoteStatus'] = $this->gitService->getRemoteStatus();
        }

        return $this->render('@VmGitPush/git_push.html.twig', $templateData);
    }

    private function handleInit(Request $request): array
    {
        $remoteUrl = trim($request->request->get('remote_url', ''));
        $branch = trim($request->request->get('branch', 'main'));
        $sshKeyPath = trim($request->request->get('ssh_key_path', ''));
        $userName = trim($request->request->get('git_user_name', ''));
        $userEmail = trim($request->request->get('git_user_email', ''));

        if (empty($remoteUrl)) {
            return [
                'message' => 'Bitte geben Sie eine Remote URL ein.',
                'type' => 'error',
            ];
        }

        if (empty($userName) || empty($userEmail)) {
            return [
                'message' => 'Bitte geben Sie Git Benutzer Name und E-Mail ein.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->initRepository(
            $remoteUrl,
            $branch,
            $sshKeyPath ?: null,
            $userName,
            $userEmail
        );

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleConfigUser(Request $request): array
    {
        $userName = trim($request->request->get('git_user_name', ''));
        $userEmail = trim($request->request->get('git_user_email', ''));

        if (empty($userName) || empty($userEmail)) {
            return [
                'message' => 'Bitte geben Sie Name und E-Mail ein.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->setUserConfig($userName, $userEmail);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleAddRemote(Request $request): array
    {
        $remoteUrl = trim($request->request->get('remote_url', ''));

        if (empty($remoteUrl)) {
            return [
                'message' => 'Bitte geben Sie eine Remote URL ein.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->addRemote($remoteUrl);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleCommitAndPush(Request $request): array
    {
        $commitMessage = trim($request->request->get('commit_message', ''));
        $branch = trim($request->request->get('branch', 'main'));
        $forcePush = (bool) $request->request->get('force_push', false);

        if (empty($commitMessage)) {
            return [
                'message' => 'Bitte geben Sie eine Commit-Nachricht ein.',
                'type' => 'error',
            ];
        }

        // Server-Stand hat Vorrang - kein Pull, direkt Force Push
        $result = $this->gitService->commitAndPush($commitMessage, $branch, null, $forcePush);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handlePull(): array
    {
        $branch = $this->gitService->getCurrentBranch();

        $this->gitService->fetch();
        $result = $this->gitService->pull($branch);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleStatus(): array
    {
        $statusText = $this->gitService->getStatusText();

        return [
            'message' => 'Git Status abgerufen',
            'type' => 'info',
            'output' => $statusText,
        ];
    }

    private function handleGenerateSshKey(): array
    {
        $result = $this->gitService->generateSshKey();

        $output = $result['output'] ?? '';
        if ($result['success'] && isset($result['publicKey'])) {
            $output = "SSH Key erfolgreich generiert!\n\nPublic Key (kopieren und in GitHub/GitLab als Deploy Key hinzufügen):\n\n" . $result['publicKey'];
        }

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $output,
        ];
    }

    private function handleDeleteSshKey(): array
    {
        $result = $this->gitService->deleteSshKey();

        return [
            'message' => $result['message'],
            'type' => 'success',
            'output' => '',
        ];
    }

    private function handleTestSsh(): array
    {
        $result = $this->gitService->testSshConnection();

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? '',
        ];
    }

    private function handleCheckoutCommit(Request $request): array
    {
        $commitHash = trim($request->request->get('commit_hash', ''));

        if (empty($commitHash)) {
            return [
                'message' => 'Kein Commit ausgewählt.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->checkoutCommit($commitHash);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleCheckoutLatest(): array
    {
        $result = $this->gitService->checkoutLatest();

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleClone(Request $request): array
    {
        $remoteUrl = trim($request->request->get('remote_url', ''));
        $branch = trim($request->request->get('branch', 'main'));
        $userName = trim($request->request->get('git_user_name', ''));
        $userEmail = trim($request->request->get('git_user_email', ''));

        if (empty($remoteUrl)) {
            return [
                'message' => 'Bitte geben Sie eine Remote URL ein.',
                'type' => 'error',
            ];
        }

        if (empty($userName) || empty($userEmail)) {
            return [
                'message' => 'Bitte geben Sie Git Benutzer Name und E-Mail ein.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->cloneRepository($remoteUrl, $branch, $userName, $userEmail);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleSwitchBranch(Request $request): array
    {
        $branch = trim($request->request->get('branch', ''));

        if (empty($branch)) {
            return [
                'message' => 'Kein Branch ausgewählt.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->switchBranch($branch);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleCreateBranch(Request $request): array
    {
        $branchName = trim($request->request->get('branch_name', ''));

        if (empty($branchName)) {
            return [
                'message' => 'Bitte geben Sie einen Branch-Namen ein.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->createBranch($branchName);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleRenameBranch(Request $request): array
    {
        $oldName = trim($request->request->get('old_branch_name', ''));
        $newName = trim($request->request->get('new_branch_name', ''));

        if (empty($oldName) || empty($newName)) {
            return [
                'message' => 'Bitte geben Sie den alten und neuen Branch-Namen ein.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->renameBranch($oldName, $newName);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleDeleteBranch(Request $request): array
    {
        $branchName = trim($request->request->get('branch_name', ''));

        if (empty($branchName)) {
            return [
                'message' => 'Kein Branch ausgewählt.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->deleteBranch($branchName);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }

    private function handleChangeRemoteUrl(Request $request): array
    {
        $remoteUrl = trim($request->request->get('remote_url', ''));

        if (empty($remoteUrl)) {
            return [
                'message' => 'Bitte geben Sie eine Remote URL ein.',
                'type' => 'error',
            ];
        }

        $result = $this->gitService->setRemoteUrl($remoteUrl);

        return [
            'message' => $result['message'],
            'type' => $result['success'] ? 'success' : 'error',
            'output' => $result['output'] ?? ($result['error'] ?? ''),
        ];
    }
}
