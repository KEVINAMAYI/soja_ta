<?php

namespace App\Http\Resources\SuperAdmin\Payload;

class LoginDTO
{
    public function __construct(
        public string $token,
        public string $tokenType,
        public int $expiresIn,
        public array $user
    ) {
    }
}