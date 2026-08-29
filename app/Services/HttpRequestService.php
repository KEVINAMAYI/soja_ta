<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Generic HTTP client wrapper supporting any request method.
 */
class HttpRequestService
{
    /**
     * Send an HTTP request to the given URL.
     *
     * @param  string  $method  get|post|put|patch|delete|head|options
     * @param  string  $url
     * @param  array<string, mixed>  $data  query params for GET/HEAD, body otherwise
     * @param  array<string, string>  $headers
     * @param  int  $timeout  seconds
     */
    public function request(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = 15,
    ): Response {
        $request = $this->buildRequest($headers, $timeout);

        return $request->send(strtoupper($method), $url, $this->resolveOptionsKey($method, $data));
    }

    public function get(string $url, array $query = [], array $headers = [], int $timeout = 15): Response
    {
        return $this->buildRequest($headers, $timeout)->get($url, $query);
    }

    public function post(string $url, array $data = [], array $headers = [], int $timeout = 15): Response
    {
        return $this->buildRequest($headers, $timeout)->post($url, $data);
    }

    public function put(string $url, array $data = [], array $headers = [], int $timeout = 15): Response
    {
        return $this->buildRequest($headers, $timeout)->put($url, $data);
    }

    public function patch(string $url, array $data = [], array $headers = [], int $timeout = 15): Response
    {
        return $this->buildRequest($headers, $timeout)->patch($url, $data);
    }

    public function delete(string $url, array $data = [], array $headers = [], int $timeout = 15): Response
    {
        return $this->buildRequest($headers, $timeout)->delete($url, $data);
    }

    protected function buildRequest(array $headers = [], int $timeout = 15): PendingRequest
    {
        return Http::withHeaders($headers)
            ->acceptJson()
            ->timeout($timeout);
    }

    /**
     * Http::send() expects "query" for GET/HEAD and "json"/"form_params"-like array otherwise.
     */
    protected function resolveOptionsKey(string $method, array $data): array
    {
        return in_array(strtoupper($method), ['GET', 'HEAD'], true)
            ? ['query' => $data]
            : ['json' => $data];
    }
}
