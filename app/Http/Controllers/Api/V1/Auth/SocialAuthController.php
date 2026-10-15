<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialAuthController extends Controller
{
    private const STATE_TTL_SECONDS = 600;

    private const CODE_TTL_SECONDS = 60;

    /** Step 1: send the visitor to the provider. */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $challenge = (string) $request->query('challenge', '');

        // The challenge is the hex SHA-256 of a secret held by the Next.js server.
        if (! preg_match('/^[a-f0-9]{64}$/', $challenge)) {
            return $this->toFrontend(['error' => 'invalid_request']);
        }

        $state = Crypt::encryptString(json_encode([
            'p' => $provider,
            'c' => $challenge,
            'e' => now()->addSeconds(self::STATE_TTL_SECONDS)->timestamp,
        ]));

        return Socialite::driver($provider)
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    /** Step 2: the provider sends the visitor back here. */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $state = $this->readState((string) $request->query('state', ''), $provider);

        if ($state === null) {
            return $this->toFrontend(['error' => 'invalid_state']);
        }

        if ($request->filled('error')) {
            return $this->toFrontend(['error' => 'access_denied']);
        }

        try {
            $social = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning('OAuth callback failed', [
                'provider' => $provider,
                'exception' => get_class($e),
            ]);

            return $this->toFrontend(['error' => 'oauth_failed']);
        }

        $user = $this->resolveUser($provider, $social);

        if ($user->trashed()) {
            return $this->toFrontend(['error' => 'account_disabled']);
        }

        if ($user->isBanned()) {
            return $this->toFrontend(['error' => 'banned']);
        }

        // Single-use, short-lived. Only a hash of the code is stored.
        $code = Str::random(64);

        Cache::put(
            'auth_code:'.hash('sha256', $code),
            ['user_id' => $user->id, 'challenge' => $state['c']],
            now()->addSeconds(self::CODE_TTL_SECONDS),
        );

        return $this->toFrontend(['code' => $code]);
    }

    /** Decrypts and validates the state we issued in redirect(). */
    private function readState(string $state, string $provider): ?array
    {
        if ($state === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (
            ! is_array($data)
            || ($data['p'] ?? null) !== $provider
            || ! is_string($data['c'] ?? null)
            || (int) ($data['e'] ?? 0) < now()->timestamp
        ) {
            return null;
        }

        return $data;
    }

    private function resolveUser(string $provider, SocialUser $social): User
    {
        $providerId = (string) $social->getId();

        $user = $this->findByProvider($provider, $providerId);

        if ($user) {
            // Keep the profile fresh, but never touch trashed or banned accounts.
            if (! $user->trashed() && ! $user->isBanned()) {
                $user->fill([
                    'name' => $this->displayName($social),
                    'avatar_url' => $this->avatarUrl($social),
                ])->save();
            }

            return $user;
        }

        $email = $this->verifiedEmail($provider, $social);

        // Never link to an existing account by email: take the account over risk.
        if ($email !== null && User::withTrashed()->where('email', $email)->exists()) {
            $email = null;
        }

        try {
            return $this->createUser($provider, $providerId, $social, $email);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first login (or an email race): use what exists.
            return $this->findByProvider($provider, $providerId)
                ?? $this->createUser($provider, $providerId, $social, null);
        }
    }

    private function findByProvider(string $provider, string $providerId): ?User
    {
        return User::withTrashed()
            ->where('provider_name', $provider)
            ->where('provider_id', $providerId)
            ->first();
    }

    private function createUser(string $provider, string $providerId, SocialUser $social, ?string $email): User
    {
        $user = new User;

        $user->fill([
            'name' => $this->displayName($social),
            'email' => $email,
            'avatar_url' => $this->avatarUrl($social),
            'provider_name' => $provider,
            'provider_id' => $providerId,
        ]);

        // role defaults to "user"; email_verified_at is not mass-assignable.
        $user->forceFill(['email_verified_at' => $email !== null ? now() : null]);
        $user->save();

        return $user;
    }

    private function displayName(SocialUser $social): string
    {
        $name = trim(strip_tags((string) ($social->getName() ?: $social->getNickname())));

        return Str::limit($name !== '' ? $name : 'Reader', 100, '');
    }

    private function avatarUrl(SocialUser $social): ?string
    {
        $avatar = $social->getAvatar();

        if (is_string($avatar) && str_starts_with($avatar, 'https://') && strlen($avatar) <= 2048) {
            return $avatar;
        }

        return null;
    }

    /** Returns the email only if the provider says it is verified. */
    private function verifiedEmail(string $provider, SocialUser $social): ?string
    {
        $email = $social->getEmail();

        if (! is_string($email) || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $raw = (array) ($social->user ?? []);
        $flag = $provider === 'google'
            ? ($raw['email_verified'] ?? false)
            : ($raw['verified'] ?? false);

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN) ? Str::lower($email) : null;
    }

    /** Always redirects to the fixed frontend URL, never to a caller-supplied one. */
    private function toFrontend(array $params): RedirectResponse
    {
        $base = rtrim((string) config('azhayon.frontend_url'), '/');

        return redirect()
            ->away($base.'/auth/callback?'.http_build_query($params))
            ->withHeaders([
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer', // keeps the code out of Referer headers
            ]);
    }
}