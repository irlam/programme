<?php
declare(strict_types=1);
namespace App\Lib;

use RuntimeException;
use Throwable;

/** Server-side session component; HTTP middleware/cookie configuration is required separately. */
final class SuiteSession
{
    private readonly string $audience;

    public function __construct(
        private readonly SuiteGateway $gateway,
        private readonly SuiteUserMap $users,
        array $binding
    ) {
        foreach (['instance_id','organization_id','project_id','local_project_id'] as $field) {
            if (!is_int($binding[$field] ?? null) || $binding[$field] < 1) {
                throw new RuntimeException('Invalid session binding.');
            }
        }
        if (!is_string($binding['origin'] ?? null)) throw new RuntimeException('Invalid session origin.');
        $this->audience = hash('sha256', json_encode([
            $binding['instance_id'], $binding['organization_id'], $binding['project_id'],
            $binding['local_project_id'], $binding['origin']
        ], JSON_THROW_ON_ERROR));
    }

    /** Call with the PHP server session after verifying the one-use browser-state cookie. */
    public function accept(array &$session, string $code, string $state, string $cookieState): array
    {
        // A failed callback must not leave an earlier local or Suite login active.
        $session = [];
        try {
            $identity = $this->gateway->redeem($code, $state, $cookieState);
            $user = $this->users->resolve($identity);
            $session = ['suite_auth'=>[
                'token'=>$identity['session_token'], 'audience'=>$this->audience,
                'suite_user_id'=>$identity['user_id']
            ], 'user'=>$user, 'csrf'=>bin2hex(random_bytes(32))];
            return $user;
        } catch (Throwable $e) {
            throw new RuntimeException('Suite sign-in could not be verified.');
        }
    }

    /** No cached role/identity fallback: call before every protected request. */
    public function current(array &$session): array
    {
        try {
            $auth = $session['suite_auth'] ?? null;
            if (!is_array($auth) || ($auth['audience'] ?? null) !== $this->audience
                || !is_string($auth['token'] ?? null) || !is_int($auth['suite_user_id'] ?? null)) {
                throw new RuntimeException('Missing instance session.');
            }
            $identity = $this->gateway->validate($auth['token']);
            if ($identity['user_id'] !== $auth['suite_user_id']) {
                throw new RuntimeException('Session identity changed.');
            }
            $user = $this->users->resolve($identity);
            $session['user'] = $user;
            return $user;
        } catch (Throwable $e) {
            $session = [];
            throw new RuntimeException('Suite access could not be verified.');
        }
    }

    /** Call only after HTTP method and CSRF checks; clear local state even during an outage. */
    public function logout(array &$session): void
    {
        $auth = $session['suite_auth'] ?? null;
        $session = [];
        if (is_array($auth) && ($auth['audience'] ?? null) === $this->audience
            && is_string($auth['token'] ?? null)) {
            $this->gateway->revoke($auth['token']);
        }
    }
}
