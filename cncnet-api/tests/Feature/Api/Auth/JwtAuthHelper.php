<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

trait JwtAuthHelper
{
    public function jwtAuth(User $user) {
        auth()->forgetGuards();
        $token = JWTAuth::fromUser($user);

        return $this
            ->actingAs($user, 'api')
            ->withHeaders(['Authorization' => 'Bearer '. $token]);
    }
}