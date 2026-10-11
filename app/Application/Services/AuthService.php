<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthenticationException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Domain\Users\Events\UserEmailVerified;
use App\Domain\Users\Events\UserLoggedIn;
use App\Domain\Users\Events\UserLoggedOut;
use App\Domain\Users\Events\UserPasswordResetRequested;
use App\Domain\Users\Events\UserPasswordWasReset;
use App\Domain\Users\Events\UserRegistered;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Repositories\AuthTokenRepository;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\LoginThrottle;
use App\Infrastructure\Security\PasswordHasher;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Validation\Validator;

/**
 * Per Phase 0 §55/§58: Controllers call this, this calls Repositories.
 * No SQL here, no HTTP concerns here (no reading $_POST, no setting
 * cookies) — AuthController/AuthApiController own that boundary.
 */
final class AuthService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        EventBus $events,
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly MailerInterface $mailer,
        private readonly LoginThrottle $throttle,
        private readonly OrganizationService $organizations,
        private readonly RoleRepository $roles,
    ) {
        parent::__construct($logger, $events);
    }

    /**
     * Per the Phase 3 kickoff decision: every self-service registration
     * creates its OWN new organization, and the registering user
     * becomes that organization's Organization Admin — the standard
     * SaaS pattern (one signup = one new customer/tenant). Inviting a
     * teammate into an EXISTING organization is a different flow
     * (UserManagementService::create(), called by an org admin who is
     * already logged in), not this one.
     */
    public function register(array $input): array
    {
        $validator = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|min:' . (int) Config::get('auth.password_min_length', 8) . '|confirmed',
            'first_name' => 'required',
            'organization_name' => 'required|min:2',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $email = strtolower(trim((string) $input['email']));

        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException(['email' => ['An account with this email already exists.']]);
        }

        $requireVerification = (bool) Config::get('auth.require_email_verification', true);

        $organizationId = $this->organizations->create((string) $input['organization_name']);
        $orgAdminRoleId = $this->orgAdminRoleId();

        $userId = $this->users->create([
            'organization_id' => $organizationId,
            'role_id' => $orgAdminRoleId,
            'email' => $email,
            'password_hash' => PasswordHasher::hash((string) $input['password']),
            'first_name' => trim((string) $input['first_name']),
            'last_name' => isset($input['last_name']) ? trim((string) $input['last_name']) : null,
            'language' => $input['language'] ?? Config::get('app.default_language', 'fa'),
            'email_verified_at' => $requireVerification ? null : date('Y-m-d H:i:s'),
        ]);

        $this->events->dispatch(new UserRegistered($userId, $email));

        if ($requireVerification) {
            $this->sendEmailVerification($userId, $email);
        }

        return $this->users->findById($userId);
    }

    private function orgAdminRoleId(): int
    {
        foreach ($this->roles->findSystemTemplates() as $role) {
            if ($role['key'] === 'org_admin') {
                return (int) $role['id'];
            }
        }

        throw new \RuntimeException(
            'System role template "org_admin" is missing — has migration 0010_seed_system_roles run?'
        );
    }

    public function sendEmailVerification(int $userId, string $email): void
    {
        $this->tokens->invalidateAllOfType($userId, 'email_verification');

        $raw = bin2hex(random_bytes(32));
        $ttlMinutes = (int) Config::get('auth.email_verification_ttl_minutes', 60 * 24);
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');

        $this->tokens->create($userId, 'email_verification', hash('sha256', $raw), $expiresAt);

        $link = rtrim((string) Config::get('app.url', ''), '/') . '/email/verify/' . $raw;

        $this->mailer->send(
            $email,
            'Verify your email',
            "Confirm your email address by opening this link:\n\n{$link}\n\nThis link expires in " .
                round($ttlMinutes / 60) . " hour(s)."
        );
    }

    public function verifyEmail(string $rawToken): void
    {
        $token = $this->tokens->findValid(hash('sha256', $rawToken), 'email_verification');

        if ($token === null) {
            throw new HttpException(400, 'This verification link is invalid or has expired.', 'invalid_token');
        }

        $this->tokens->markUsed((int) $token['id']);
        $this->users->markEmailVerified((int) $token['user_id']);

        $this->events->dispatch(new UserEmailVerified((int) $token['user_id']));
    }

    public function login(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));

        if ($this->throttle->tooManyAttempts($email, $ip)) {
            throw new HttpException(429, 'Too many login attempts. Please try again later.', 'too_many_attempts');
        }

        $user = $this->users->findByEmail($email);

        // Same generic failure for "no such email" and "wrong password"
        // — distinguishing them lets an attacker enumerate registered
        // emails, so both paths below produce an identical response.
        if ($user === null || !PasswordHasher::verify($password, $user['password_hash'])) {
            $this->throttle->recordFailure($email, $ip);
            throw new AuthenticationException('Invalid email or password.', 'invalid_credentials');
        }

        if ($user['status'] !== 'active') {
            $this->throttle->recordFailure($email, $ip);
            throw new AuthenticationException('This account is inactive.', 'account_inactive');
        }

        if ((bool) Config::get('auth.require_email_verification', true) && $user['email_verified_at'] === null) {
            $this->throttle->recordFailure($email, $ip);
            throw new AuthenticationException('Please verify your email before logging in.', 'email_not_verified');
        }

        $this->throttle->recordSuccess($email, $ip);

        if (PasswordHasher::needsRehash($user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], PasswordHasher::hash($password));
        }

        $this->users->updateLastLogin((int) $user['id']);
        Session::login((int) $user['id']);

        $this->events->dispatch(new UserLoggedIn((int) $user['id']));

        return $this->users->findById((int) $user['id']);
    }

    public function logout(): void
    {
        $userId = Session::userId();
        Session::logout();

        if ($userId !== null) {
            $this->events->dispatch(new UserLoggedOut($userId));
        }
    }

    /**
     * Deliberately does not reveal whether $email exists — the caller
     * (controller) always shows the same "if that address is
     * registered, a link was sent" message either way.
     */
    public function requestPasswordReset(string $email): void
    {
        $email = strtolower(trim($email));
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            return;
        }

        $this->tokens->invalidateAllOfType((int) $user['id'], 'password_reset');

        $raw = bin2hex(random_bytes(32));
        $ttlMinutes = (int) Config::get('auth.password_reset_ttl_minutes', 60);
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');

        $this->tokens->create((int) $user['id'], 'password_reset', hash('sha256', $raw), $expiresAt);

        $link = rtrim((string) Config::get('app.url', ''), '/') . '/password/reset/' . $raw;

        $this->mailer->send(
            $email,
            'Reset your password',
            "Reset your password by opening this link:\n\n{$link}\n\nThis link expires in {$ttlMinutes} minute(s). " .
                "If you didn't request this, you can ignore this email."
        );

        $this->events->dispatch(new UserPasswordResetRequested((int) $user['id']));
    }

    public function resetPassword(string $rawToken, string $password, string $passwordConfirmation): void
    {
        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $passwordConfirmation],
            ['password' => 'required|min:' . (int) Config::get('auth.password_min_length', 8) . '|confirmed']
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $token = $this->tokens->findValid(hash('sha256', $rawToken), 'password_reset');

        if ($token === null) {
            throw new HttpException(400, 'This password reset link is invalid or has expired.', 'invalid_token');
        }

        $this->users->updatePasswordHash((int) $token['user_id'], PasswordHasher::hash($password));
        $this->tokens->markUsed((int) $token['id']);
        // Any other outstanding reset link for this user is now stale too.
        $this->tokens->invalidateAllOfType((int) $token['user_id'], 'password_reset');

        $this->events->dispatch(new UserPasswordWasReset((int) $token['user_id']));
    }

    public function updateProfile(int $userId, array $input): array
    {
        $validator = Validator::make($input, ['first_name' => 'required']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $allowedLanguages = ['en', 'fa', 'ar', 'tr', 'fr'];
        $language = in_array($input['language'] ?? null, $allowedLanguages, true)
            ? $input['language']
            : null;

        $fields = array_filter([
            'first_name' => trim((string) $input['first_name']),
            'last_name' => isset($input['last_name']) ? trim((string) $input['last_name']) : null,
            'mobile_phone' => isset($input['mobile_phone']) && trim((string) $input['mobile_phone']) !== '' ? trim((string) $input['mobile_phone']) : null,
            'national_id' => isset($input['national_id']) && trim((string) $input['national_id']) !== '' ? trim((string) $input['national_id']) : null,
            'language' => $language,
            'timezone' => isset($input['timezone']) && $input['timezone'] !== '' ? (string) $input['timezone'] : null,
        ], fn ($v) => $v !== null);

        $this->users->updateProfile($userId, $fields);

        return $this->users->findById($userId);
    }
}
