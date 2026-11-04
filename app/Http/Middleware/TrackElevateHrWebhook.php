<?php

namespace App\Http\Middleware;

use App\Services\ElevateHrWebhookMonitor;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class TrackElevateHrWebhook
{
    public function __construct(private ElevateHrWebhookMonitor $monitor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = ltrim((string) $request->route('organization'), '0') ?: '0';
        $date = now()->toDateString();
        $this->monitor->received($organizationId, $date);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = match (true) {
                $exception instanceof ValidationException => $exception->status,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $this->monitor->failed(
                $organizationId, $date, $request->ip(), $status,
                $status >= 500 ? 'Unexpected webhook processing error ('.$exception::class.').'
                    : $request->attributes->get('elevate_failure_reason', $exception->getMessage()),
                $exception instanceof ValidationException ? $exception->errors() : null,
                $request->attributes->get('elevate_failure_metadata'),
            );

            throw $exception;
        }

        if ($response->getStatusCode() >= 400) {
            $data = json_decode($response->getContent(), true);
            $status = $response->getStatusCode();
            $reason = $request->attributes->get('elevate_failure_reason');
            $exception = $response->exception ?? null;
            $errors = $exception instanceof ValidationException ? $exception->errors() : ($data['errors'] ?? null);
            $this->monitor->failed(
                $organizationId, $date, $request->ip(), $status,
                $status >= 500 ? 'Unexpected webhook processing error.' : ($reason ?? ($data['message'] ?? Response::$statusTexts[$status] ?? 'Webhook failed.')),
                $status < 500 && is_array($errors) ? $errors : null,
                $request->attributes->get('elevate_failure_metadata'),
            );
        }

        return $response;
    }
}
