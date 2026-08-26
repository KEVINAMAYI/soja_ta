<?php

namespace App\Http\Payload\SuperAdmin;

use App\Http\Requests\superadmin\LoginRequest;

class LoginRequestDTO
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $loginType = null
    ) {
    }

    public static function fromRequest(LoginRequest $request): self
    {
            return new self(
            email: $request->validated('email'),
            password: $request->validated('password'),
            loginType: $request->validated('loginType')
        );
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
            'loginType' => $this->loginType,
        ];
    }

    public function toLogContext(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password !== null ? '*****' : null,
            'loginType' => $this->loginType ?? null,
        ];
    }
}