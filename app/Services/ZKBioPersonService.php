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
        $this->baseUrl      = $organization?->zkbio_base_url     ?? config('zkbio.base_url');
        $this->accessToken  = $organization?->zkbio_access_token ?? config('zkbio.access_token');
    }

    /**
     * Check if ZKBio is enabled for this organization
     */
    public function isEnabled(): bool
    {
        if ($this->organization) {
            return (bool) $this->organization->zkbio_enabled;
        }
        return false;
    }

    public function syncPerson(Employee $employee): bool
    {
        if (!$this->isEnabled()) return false;
        if (!$employee->zkbio_pin) return false;

        $nameParts = $this->splitName($employee->name);

        $response = Http::timeout(10)
            ->post("{$this->baseUrl}/api/person/add?access_token={$this->accessToken}", [
                'pin'         => (string) $employee->zkbio_pin,
                'name'        => $nameParts['first'],
                'lastName'    => $nameParts['last'],
                'mobilePhone' => $employee->phone ?? '',
                'ssn'         => $employee->id_number ?? '',
            ]);

        $body = $response->json();

        if (($body['code'] ?? -1) === 0) {
            Log::info("ZKBio person synced", [
                'employee' => $employee->name,
                'pin'      => $employee->zkbio_pin,
                'org'      => $this->organization?->name,
            ]);
            return true;
        }

        Log::error("ZKBio person sync failed", [
            'employee' => $employee->name,
            'response' => $body,
        ]);
        return false;
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
            'last'  => $parts[1] ?? '',
        ];
    }
}
