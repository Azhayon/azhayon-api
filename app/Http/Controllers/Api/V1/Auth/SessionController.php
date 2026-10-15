<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SessionController extends Controller
{
    /**
     * Called server-to-server by Next.js. Swaps a one-time code plus the
     * secret verifier for a Sanctum token.
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/'],
            'verifier' => ['required', 'string', 'min:32', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
        ]);

        $key = 'auth_code:'.hash('sha256', $data['code']);

        // The lock makes "read and delete" atomic: a code can be redeemed once,
        // even with concurrent requests. A wrong verifier still burns the code.
        $payload = Cache::lock($key.':lock', 5)->get(fn () => Cache::pull($key));

        if (
            ! is_array($payload)
            || ! hash_equals($payload['challenge'], hash('sha256', $data['verifier']))
        ) {
            return $this->invalidGrant();
        }

        $user = User::find($payload['user_id']);

        if (! $user || $user->isBanned()) {
            return $this->invalidGrant();
        }

        $expiresAt = now()->addDays(config('azhayon.token_ttl_days'));
        $token = $user->createToken('web', ['*'], $expiresAt);

        return response()
            ->json([
                'token' => $token->plainTextToken,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => new UserResource($user),
            ])
            ->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function invalidGrant(): JsonResponse
    {
        return response()->json(['message' => 'Invalid or expired code.'], 400);
    }
}