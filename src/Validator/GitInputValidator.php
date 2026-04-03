<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Validator;

use VennMedia\VmGitPushBundle\Exception\ValidationException;

class GitInputValidator
{
    private const BRANCH_NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9\-_\/\.]*$/';
    private const COMMIT_HASH_PATTERN = '/^[a-f0-9]{7,40}$/i';

    private const SSH_URL_PATTERN = '/^git@[a-zA-Z0-9.\-]+:[a-zA-Z0-9_.\-\/]+\.git$/';
    private const HTTPS_URL_PATTERN = '/^https:\/\/[a-zA-Z0-9.\-]+(:[0-9]+)?\/[a-zA-Z0-9_.\-\/]+\.git$/';
    private const LOCAL_PATH_PATTERN = '/^(\/|[A-Za-z]:[\\\\\/])/';

    private const PROTECTED_BRANCHES = ['main', 'master', 'production', 'prod'];

    public function validateRemoteUrl(string $url): void
    {
        $url = trim($url);

        if (empty($url)) {
            throw new ValidationException('Remote URL darf nicht leer sein.');
        }

        $isValidUrl = preg_match(self::SSH_URL_PATTERN, $url)
            || preg_match(self::HTTPS_URL_PATTERN, $url)
            || preg_match(self::LOCAL_PATH_PATTERN, $url);

        if (!$isValidUrl) {
            throw new ValidationException(
                'Ungueltige Remote URL. Erlaubte Formate: git@host:user/repo.git oder https://host/user/repo.git'
            );
        }

        if (str_contains($url, '..') || str_contains($url, '~')) {
            throw new ValidationException('Remote URL enthaelt unerlaubte Zeichen.');
        }
    }

    public function validateBranchName(string $name): void
    {
        $name = trim($name);

        if (empty($name)) {
            throw new ValidationException('Branch-Name darf nicht leer sein.');
        }

        if (strlen($name) > 100) {
            throw new ValidationException('Branch-Name darf maximal 100 Zeichen lang sein.');
        }

        if (!preg_match(self::BRANCH_NAME_PATTERN, $name)) {
            throw new ValidationException(
                'Ungueltiger Branch-Name. Erlaubt: Buchstaben, Zahlen, -, _, /, . (muss mit Buchstabe/Zahl beginnen)'
            );
        }

        if (str_contains($name, '..') || str_contains($name, '~') || str_contains($name, '^') || str_contains($name, ':')) {
            throw new ValidationException('Branch-Name enthaelt unerlaubte Zeichenkombinationen.');
        }

        if (str_ends_with($name, '.lock') || str_ends_with($name, '/')) {
            throw new ValidationException('Branch-Name darf nicht auf .lock oder / enden.');
        }
    }

    public function validateCommitHash(string $hash): void
    {
        $hash = trim($hash);

        if (empty($hash)) {
            throw new ValidationException('Commit Hash darf nicht leer sein.');
        }

        if (!preg_match(self::COMMIT_HASH_PATTERN, $hash)) {
            throw new ValidationException('Ungueltiger Commit Hash. Muss 7-40 Hex-Zeichen enthalten.');
        }
    }

    public function validateCommitMessage(string $message): void
    {
        $message = trim($message);

        if (empty($message)) {
            throw new ValidationException('Commit-Nachricht darf nicht leer sein.');
        }

        if (strlen($message) > 1000) {
            throw new ValidationException('Commit-Nachricht darf maximal 1000 Zeichen lang sein.');
        }
    }

    public function validateUserName(string $name): void
    {
        $name = trim($name);

        if (empty($name)) {
            throw new ValidationException('Git Benutzername darf nicht leer sein.');
        }

        if (strlen($name) > 100) {
            throw new ValidationException('Git Benutzername darf maximal 100 Zeichen lang sein.');
        }
    }

    public function validateUserEmail(string $email): void
    {
        $email = trim($email);

        if (empty($email)) {
            throw new ValidationException('Git E-Mail darf nicht leer sein.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Ungueltige E-Mail-Adresse.');
        }
    }

    public function isProtectedBranch(string $branchName): bool
    {
        return in_array(strtolower(trim($branchName)), self::PROTECTED_BRANCHES, true);
    }

    public function validateBranchDeletion(string $branchName, string $currentBranch): void
    {
        $this->validateBranchName($branchName);

        if (trim($branchName) === trim($currentBranch)) {
            throw new ValidationException('Der aktive Branch kann nicht geloescht werden.');
        }

        if ($this->isProtectedBranch($branchName)) {
            throw new ValidationException(
                'Der Branch "' . $branchName . '" ist geschuetzt und kann nicht geloescht werden.'
            );
        }
    }
}
