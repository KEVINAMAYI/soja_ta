<?php

namespace App\Http\Payload\SuperAdmin;

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