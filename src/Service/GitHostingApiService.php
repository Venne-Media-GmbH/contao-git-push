<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Service;

use Psr\Log\LoggerInterface;
use VennMedia\VmGitPushBundle\Dto\GitResult;

class GitHostingApiService
{
    private const GITHUB_API = 'https://api.github.com';
    private const GITLAB_API = 'https://gitlab.com/api/v4';

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Erkennt den Hosting-Provider anhand der URL oder des Tokens.
     */
    public function detectProvider(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_contains($host, 'github')) {
            return 'github';
        }

        if (str_contains($host, 'gitlab')) {
            return 'gitlab';
        }

        return 'unknown';
    }

    // ── GitHub ────────────────────────────────────────────────────

    /**
     * Erstellt ein neues GitHub Repository.
     *
     * @return array{success: bool, ssh_url: string|null, html_url: string|null, message: string}
     */
    public function createGitHubRepo(string $token, string $repoName, bool $private = true): array
    {
        $repoName = $this->sanitizeRepoName($repoName);

        $response = $this->apiRequest('POST', self::GITHUB_API . '/user/repos', $token, [
            'name' => $repoName,
            'private' => $private,
            'auto_init' => false,
            'description' => 'Erstellt via GIT Connect (Contao)',
        ]);

        if ($response['httpCode'] === 201) {
            $data = $response['data'];
            $this->logger?->info('GitHub repo created', ['repo' => $data['full_name'] ?? $repoName]);

            return [
                'success' => true,
                'ssh_url' => $data['ssh_url'] ?? null,
                'html_url' => $data['html_url'] ?? null,
                'full_name' => $data['full_name'] ?? null,
                'message' => 'Repository "' . ($data['full_name'] ?? $repoName) . '" erfolgreich erstellt!',
            ];
        }

        $error = $response['data']['message'] ?? 'Unbekannter Fehler';
        if (str_contains($error, 'name already exists')) {
            $error = 'Ein Repository mit diesem Namen existiert bereits.';
        }

        $this->logger?->error('GitHub repo creation failed', ['error' => $error]);

        return [
            'success' => false,
            'ssh_url' => null,
            'html_url' => null,
            'full_name' => null,
            'message' => 'Fehler beim Erstellen: ' . $error,
        ];
    }

    /**
     * Fügt einen Deploy Key zum GitHub Repository hinzu.
     */
    public function addGitHubDeployKey(string $token, string $fullRepoName, string $publicKey, string $title = 'GIT Connect (Contao)'): array
    {
        $response = $this->apiRequest('POST', self::GITHUB_API . '/repos/' . $fullRepoName . '/keys', $token, [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => false,
        ]);

        if ($response['httpCode'] === 201) {
            $this->logger?->info('GitHub deploy key added', ['repo' => $fullRepoName]);

            return ['success' => true, 'message' => 'Deploy Key erfolgreich eingetragen.'];
        }

        $error = $response['data']['message'] ?? 'Unbekannter Fehler';
        if (str_contains($error, 'key is already in use')) {
            return ['success' => true, 'message' => 'Deploy Key war bereits eingetragen.'];
        }

        return ['success' => false, 'message' => 'Fehler beim Eintragen des Deploy Keys: ' . $error];
    }

    /**
     * Prüft ob der GitHub Token gültig ist und gibt den Usernamen zurück.
     */
    public function validateGitHubToken(string $token): array
    {
        $response = $this->apiRequest('GET', self::GITHUB_API . '/user', $token);

        if ($response['httpCode'] === 200) {
            return [
                'success' => true,
                'username' => $response['data']['login'] ?? '',
                'name' => $response['data']['name'] ?? '',
                'email' => $response['data']['email'] ?? '',
            ];
        }

        return ['success' => false, 'username' => '', 'name' => '', 'email' => ''];
    }

    // ── GitLab ────────────────────────────────────────────────────

    /**
     * Erstellt ein neues GitLab Repository.
     */
    public function createGitLabRepo(string $token, string $repoName, bool $private = true): array
    {
        $repoName = $this->sanitizeRepoName($repoName);

        $response = $this->apiRequest('POST', self::GITLAB_API . '/projects', $token, [
            'name' => $repoName,
            'visibility' => $private ? 'private' : 'public',
            'description' => 'Erstellt via GIT Connect (Contao)',
            'initialize_with_readme' => false,
        ], 'gitlab');

        if ($response['httpCode'] === 201) {
            $data = $response['data'];
            $this->logger?->info('GitLab repo created', ['repo' => $data['path_with_namespace'] ?? $repoName]);

            return [
                'success' => true,
                'ssh_url' => $data['ssh_url_to_repo'] ?? null,
                'html_url' => $data['web_url'] ?? null,
                'full_name' => $data['path_with_namespace'] ?? null,
                'project_id' => $data['id'] ?? null,
                'message' => 'Repository "' . ($data['path_with_namespace'] ?? $repoName) . '" erfolgreich erstellt!',
            ];
        }

        $error = $response['data']['message']['name'][0]
            ?? $response['data']['message']['path'][0]
            ?? $response['data']['message']
            ?? 'Unbekannter Fehler';

        if (is_array($error)) {
            $error = implode(', ', $error);
        }

        if (str_contains((string) $error, 'has already been taken')) {
            $error = 'Ein Repository mit diesem Namen existiert bereits.';
        }

        return [
            'success' => false,
            'ssh_url' => null,
            'html_url' => null,
            'full_name' => null,
            'project_id' => null,
            'message' => 'Fehler beim Erstellen: ' . $error,
        ];
    }

    /**
     * Fügt einen Deploy Key zum GitLab Repository hinzu.
     */
    public function addGitLabDeployKey(string $token, int $projectId, string $publicKey, string $title = 'GIT Connect (Contao)'): array
    {
        $response = $this->apiRequest('POST', self::GITLAB_API . '/projects/' . $projectId . '/deploy_keys', $token, [
            'title' => $title,
            'key' => $publicKey,
            'can_push' => true,
        ], 'gitlab');

        if ($response['httpCode'] === 201) {
            $this->logger?->info('GitLab deploy key added', ['project_id' => $projectId]);

            return ['success' => true, 'message' => 'Deploy Key erfolgreich eingetragen.'];
        }

        $error = $response['data']['message'] ?? 'Unbekannter Fehler';
        if (is_array($error)) {
            $error = implode(', ', $error);
        }

        if (str_contains((string) $error, 'has already been taken')) {
            return ['success' => true, 'message' => 'Deploy Key war bereits eingetragen.'];
        }

        return ['success' => false, 'message' => 'Fehler beim Eintragen des Deploy Keys: ' . $error];
    }

    /**
     * Prüft ob der GitLab Token gültig ist.
     */
    public function validateGitLabToken(string $token): array
    {
        $response = $this->apiRequest('GET', self::GITLAB_API . '/user', $token, provider: 'gitlab');

        if ($response['httpCode'] === 200) {
            return [
                'success' => true,
                'username' => $response['data']['username'] ?? '',
                'name' => $response['data']['name'] ?? '',
                'email' => $response['data']['email'] ?? '',
            ];
        }

        return ['success' => false, 'username' => '', 'name' => '', 'email' => ''];
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function sanitizeRepoName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^a-zA-Z0-9\-_.]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-.');

        return $name;
    }

    /**
     * @return array{httpCode: int, data: array}
     */
    private function apiRequest(string $method, string $url, string $token, ?array $body = null, string $provider = 'github'): array
    {
        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'User-Agent: GIT-Connect-Contao/2.0',
        ];

        if ($provider === 'github') {
            $headers[] = 'Authorization: Bearer ' . $token;
        } else {
            $headers[] = 'PRIVATE-TOKEN: ' . $token;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            $this->logger?->error('API request failed', ['url' => $url, 'error' => $curlError]);

            return ['httpCode' => 0, 'data' => ['message' => 'Verbindungsfehler: ' . $curlError]];
        }

        $data = json_decode($responseBody, true) ?? [];

        return ['httpCode' => $httpCode, 'data' => $data];
    }
}
