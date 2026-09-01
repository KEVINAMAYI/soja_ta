<?php

namespace App\Exceptions;

use Exception;

class ImpersonationException extends Exception
{
    public function __construct(string $message, private readonly int $apiCode = 1004, private readonly int $status = 404)
    {
        parent::__construct($message);
    }

    public function apiCode(): int
    {
        return $this->apiCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
