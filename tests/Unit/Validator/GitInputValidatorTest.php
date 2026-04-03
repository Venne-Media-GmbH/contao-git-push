<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VennMedia\VmGitPushBundle\Exception\ValidationException;
use VennMedia\VmGitPushBundle\Validator\GitInputValidator;

class GitInputValidatorTest extends TestCase
{
    private GitInputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new GitInputValidator();
    }

    // ── Remote URL Validation ─────────────────────────────────────

    #[DataProvider('validRemoteUrlProvider')]
    public function testValidRemoteUrls(string $url): void
    {
        $this->validator->validateRemoteUrl($url);
        $this->addToAssertionCount(1);
    }

    public static function validRemoteUrlProvider(): iterable
    {
        yield 'SSH GitHub' => ['git@github.com:user/repo.git'];
        yield 'SSH GitLab' => ['git@gitlab.com:group/project.git'];
        yield 'SSH custom host' => ['git@git.example.com:org/repo.git'];
        yield 'HTTPS GitHub' => ['https://github.com/user/repo.git'];
        yield 'HTTPS GitLab' => ['https://gitlab.com/group/sub-group/project.git'];
        yield 'HTTPS with port' => ['https://git.example.com:8443/user/repo.git'];
        yield 'SSH with dots in repo' => ['git@github.com:user/my-repo.name.git'];
        yield 'SSH with underscores' => ['git@github.com:user_name/repo_name.git'];
    }

    #[DataProvider('invalidRemoteUrlProvider')]
    public function testInvalidRemoteUrls(string $url): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateRemoteUrl($url);
    }

    public static function invalidRemoteUrlProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'random text' => ['not-a-url'];
        yield 'file protocol' => ['file:///etc/passwd'];
        yield 'no .git suffix SSH' => ['git@github.com:user/repo'];
        yield 'no .git suffix HTTPS' => ['https://github.com/user/repo'];
        yield 'path traversal' => ['git@github.com:user/../../../etc/passwd.git'];
        yield 'tilde in URL' => ['git@github.com:~user/repo.git'];
        yield 'javascript injection' => ['javascript://github.com/user/repo.git'];
        yield 'spaces in URL' => ['git@github.com:user/my repo.git'];
    }

    public function testEmptyRemoteUrl(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Remote URL darf nicht leer sein');
        $this->validator->validateRemoteUrl('');
    }

    // ── Branch Name Validation ────────────────────────────────────

    #[DataProvider('validBranchNameProvider')]
    public function testValidBranchNames(string $name): void
    {
        $this->validator->validateBranchName($name);
        $this->addToAssertionCount(1);
    }

    public static function validBranchNameProvider(): iterable
    {
        yield 'simple' => ['main'];
        yield 'with slash' => ['feature/login'];
        yield 'with dash' => ['fix-bug'];
        yield 'with underscore' => ['my_branch'];
        yield 'with numbers' => ['release1.0'];
        yield 'nested' => ['feature/auth/oauth'];
    }

    #[DataProvider('invalidBranchNameProvider')]
    public function testInvalidBranchNames(string $name): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateBranchName($name);
    }

    public static function invalidBranchNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with dash' => ['-branch'];
        yield 'starts with dot' => ['.hidden'];
        yield 'double dots' => ['branch..name'];
        yield 'tilde' => ['branch~1'];
        yield 'caret' => ['branch^1'];
        yield 'colon' => ['branch:name'];
        yield 'ends with .lock' => ['branch.lock'];
        yield 'ends with slash' => ['branch/'];
        yield 'spaces' => ['my branch'];
        yield 'special chars' => ['branch@{1}'];
        yield 'too long' => [str_repeat('a', 101)];
    }

    // ── Commit Hash Validation ────────────────────────────────────

    public function testValidCommitHash(): void
    {
        $this->validator->validateCommitHash('abc1234');
        $this->validator->validateCommitHash('abc1234567890def1234567890abc123456789de');
        $this->addToAssertionCount(2);
    }

    public function testInvalidCommitHashEmpty(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateCommitHash('');
    }

    public function testInvalidCommitHashTooShort(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateCommitHash('abc12');
    }

    public function testInvalidCommitHashNonHex(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateCommitHash('xyz1234');
    }

    // ── Commit Message Validation ─────────────────────────────────

    public function testValidCommitMessage(): void
    {
        $this->validator->validateCommitMessage('Fix login bug');
        $this->addToAssertionCount(1);
    }

    public function testEmptyCommitMessage(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateCommitMessage('');
    }

    public function testTooLongCommitMessage(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateCommitMessage(str_repeat('x', 1001));
    }

    // ── User Name & Email Validation ──────────────────────────────

    public function testValidUserName(): void
    {
        $this->validator->validateUserName('Max Mustermann');
        $this->addToAssertionCount(1);
    }

    public function testEmptyUserName(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateUserName('');
    }

    public function testValidUserEmail(): void
    {
        $this->validator->validateUserEmail('max@example.com');
        $this->addToAssertionCount(1);
    }

    public function testInvalidUserEmail(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateUserEmail('not-an-email');
    }

    // ── Protected Branch ──────────────────────────────────────────

    public function testProtectedBranches(): void
    {
        $this->assertTrue($this->validator->isProtectedBranch('main'));
        $this->assertTrue($this->validator->isProtectedBranch('master'));
        $this->assertTrue($this->validator->isProtectedBranch('production'));
        $this->assertTrue($this->validator->isProtectedBranch('prod'));
        $this->assertTrue($this->validator->isProtectedBranch('MAIN'));
        $this->assertFalse($this->validator->isProtectedBranch('feature/login'));
        $this->assertFalse($this->validator->isProtectedBranch('develop'));
    }

    // ── Branch Deletion Validation ────────────────────────────────

    public function testCannotDeleteActiveBranch(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('aktive Branch');
        $this->validator->validateBranchDeletion('main', 'main');
    }

    public function testCannotDeleteProtectedBranch(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('geschützt');
        $this->validator->validateBranchDeletion('main', 'feature/test');
    }

    public function testCanDeleteNonProtectedBranch(): void
    {
        $this->validator->validateBranchDeletion('feature/old', 'main');
        $this->addToAssertionCount(1);
    }
}
