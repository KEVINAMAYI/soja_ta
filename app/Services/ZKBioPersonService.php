<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZKBioPersonService
{
    protected ?string $baseUrl;
    protected ?string $accessToken;
    protected ?Organization $organization;

    public function __construct(?Organization $organization = null)
    {
        $this->organization = $organization;

        // Use org-specific credentials if available, fall back to config
        $this->baseUrl = $organization?->zkbio_base_url ?? config('zkbio.base_url');
        $this->accessToken = $organization?->zkbio_access_token ?? config('zkbio.access_token');
    }

    /**
     * Check if ZKBio is enabled for this organization
     */
    public function isEnabled(): bool
    {
        if ($this->organization) {
            return (bool)$this->organization->zkbio_enabled;
        }
        return false;
    }

    public function syncPerson(Employee $employee): bool
    {
        Log::info('ZKBio syncPerson called', [
            'employee' => $employee->name,
            'zkbio_pin' => $employee->zkbio_pin,
            'isEnabled' => $this->isEnabled(),
            'baseUrl' => $this->baseUrl,
            'hasToken' => !empty($this->accessToken),
        ]);

        if (!$this->isEnabled()) {
            Log::warning('ZKBio syncPerson: not enabled, returning false');
            return false;
        }

        if (!$employee->zkbio_pin) {
            Log::warning('ZKBio syncPerson: no zkbio_pin, returning false', [
                'employee' => $employee->name,
            ]);
            return false;
        }

        $nameParts = $this->splitName($employee->name);

        $response = Http::timeout(10)
            ->post("{$this->baseUrl}/api/person/add?access_token={$this->accessToken}", [
                'pin'         => (string) $employee->zkbio_pin,
                'name'        => $nameParts['first'],
                'lastName'    => $nameParts['last'],
                'mobilePhone' => $employee->is_student
                    ? '2547' . str_pad($employee->zkbio_pin, 8, '0', STR_PAD_LEFT)
                    : ($employee->phone ?? ''),
                'ssn'         => $employee->id_number ?? '',
                'cardNo'      => '',
            ]);

        $body = $response->json();

        Log::info('ZKBio API response', [
            'employee' => $employee->name,
            'pin' => $employee->zkbio_pin,
            'status' => $response->status(),
            'body' => $body,
        ]);

        if (($body['code'] ?? -1) === 0) {
            return true;
        }

        Log::error('ZKBio person sync failed', [
            'employee' => $employee->name,
            'response' => $body,
        ]);
        throw new \RuntimeException('ZKBio sync failed: ' . ($body['message'] ?? 'unknown error'));

    }

    public function deletePerson(string $pin): bool
    {
        if (!$this->isEnabled()) return false;

        $response = Http::timeout(10)
            ->post("{$this->baseUrl}/api/person/delete?access_token={$this->accessToken}", [
                'pins' => [$pin],
            ]);

        $body = $response->json();

        if (($body['code'] ?? -1) === 0) {
            Log::info("ZKBio person deleted", ['pin' => $pin]);
            return true;
        }

        Log::error("ZKBio person delete failed", ['pin' => $pin, 'response' => $body]);
        return false;
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [
            'first' => $parts[0] ?? $fullName,
            'last' => $parts[1] ?? '',
        ];
    }
}
