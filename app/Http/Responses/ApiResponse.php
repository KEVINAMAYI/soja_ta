<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiResponse
{
    public function __construct(
        public readonly int $code,
        public readonly bool $success,
        public readonly string $message,
        public readonly mixed $data = null,
    ) {
    }

    public static function success(
        mixed $data,
        int $code = 1000,
        string $message = 'Success',
        int $httpStatusCode = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json(
            new self(
                code: $code,
                success: true,
                message: $message,
                data: $data,
            ),
            $httpStatusCode
        );
    }

    public static function userFailure(
        int $code,
        string $message = "Oh no it's not me, it's you",
        mixed $data = null,
        int $httpStatusCode = 400,
    ): JsonResponse {
        return response()->json(
            new self(
                code: $code,
                success: false,
                message: $message,
                data: $data,
            ),
            $httpStatusCode
        );
    }


    public static function serverFailure(
        int $code,
        string $message = "Oh no it's not you, it's me",
        mixed $data = null,
        int $httpStatusCode = 500,
    ): JsonResponse {
        return response()->json(
            new self(
                code: $code,
                success: false,
                message: $message,
                data: $data,
            ),
            $httpStatusCode
        );
    }
}