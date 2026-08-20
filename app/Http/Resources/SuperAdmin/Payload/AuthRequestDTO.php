<?php

namespace App\Http\Resources\SuperAdmin\Payload;

use Illuminate\Http\Request;

class AuthRequestDTO
{
    public function __construct(
        public ?string $username = null,
        public ?string $password = null,
        public ?string $email = null,
        public ?string $loginType = null
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            username: $request->input('username'),
            password: $request->input('password'),
            email: $request->input('email'),
            loginType: $request->input('loginType')
        );
    }

    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
            'email' => $this->email,
            'loginType' => $this->loginType,
        ];
    }

    public function toLogContext(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password !== null ? '***' : null,
            'email' => $this->email,
            'loginType' => $this->loginType,
        ];
    }
}