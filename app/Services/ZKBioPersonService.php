<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\ZkbioArea;
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
        $this->baseUrl = $organization?->zkbio_base_url ?? config('zkbio.base_url');
        $this->accessToken = $organization?->zkbio_access_token ?? config('zkbio.access_token');
    }

    public function isEnabled(): bool
    {
        if ($this->organization) {
            return (bool)$this->organization->zkbio_enabled;
        }
        return false;
    }

    // ─── Person ───────────────────────────────────────────────────────────────

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
            Log::warning('ZKBio syncPerson: no zkbio_pin', ['employee' => $employee->name]);
            return false;
        }

        $nameParts = $this->splitName($employee->name);

        $response = Http::timeout(10)
            ->post("{$this->baseUrl}/api/person/add?access_token={$this->accessToken}", [
                'pin' => (string)$employee->zkbio_pin,
                'name' => $nameParts['first'],
                'lastName' => $nameParts['last'],
                'mobilePhone' => $employee->is_student
                    ? '2547' . str_pad($employee->zkbio_pin, 8, '0', STR_PAD_LEFT)
                    : ($employee->phone ?? ''),
                'ssn' => $employee->id_number ?? '',
                'cardNo' => '',
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

        Log::error('ZKBio person sync failed', ['employee' => $employee->name, 'response' => $body]);
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

    // ─── Areas ────────────────────────────────────────────────────────────────

    /**
     * Fetch all areas from ZKBio and cache them in zkbio_areas table
     */
    public function syncAreas(): array
    {
        if (!$this->isEnabled()) return [];

        $response = Http::timeout(10)
            ->get("{$this->baseUrl}/api/attAreaPerson/area/list", [
                'pageNo' => 1,
                'pageSize' => 200,
                'access_token' => $this->accessToken,
            ]);

        $body = $response->json();

        if (($body['code'] ?? -1) !== 0) {
            Log::error('ZKBio area list failed', ['response' => $body]);
            return [];
        }

        $areas = $body['data'] ?? [];

        foreach ($areas as $area) {
            ZkbioArea::updateOrCreate(
                [
                    'organization_id' => $this->organization->id,
                    'area_code' => $area['code'],
                ],
                [
                    'area_name' => $area['name'],
                    'zkbio_area_id' => $area['id'] ?? null,
                ]
            );
        }

        Log::info('ZKBio areas synced', ['count' => count($areas)]);

        return $areas;
    }

    /**
     * Get cached areas for this organization
     */
    public function getCachedAreas(int $minCode = 0): \Illuminate\Support\Collection
    {
        $query = ZkbioArea::where('organization_id', $this->organization->id)
            ->orderBy('area_code');

        if ($minCode > 0) {
            $query->where('area_code', '>', $minCode);
        }

        return $query->get();
    }

    public function assignPersonToAreas(Employee $employee, array $areaCodes): bool
    {
        if (!$this->isEnabled() || !$employee->zkbio_pin) return false;

        $allSuccess = true;

        foreach ($areaCodes as $areaCode) {
            $response = Http::timeout(10)
                ->post("{$this->baseUrl}/api/attAreaPerson/set?access_token={$this->accessToken}", [
                    'code' => (string)$areaCode,
                    'pins' => [(int)$employee->zkbio_pin],
                ]);

            $body = $response->json();

            if (($body['code'] ?? -1) === 0) {
                Log::info('ZKBio area assigned', [
                    'employee' => $employee->name,
                    'pin' => $employee->zkbio_pin,
                    'area' => $areaCode,
                ]);
            } else {
                Log::error('ZKBio area assignment failed', [
                    'employee' => $employee->name,
                    'area' => $areaCode,
                    'response' => $body,
                ]);
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    public function removePersonFromAreas(Employee $employee, array $areaCodes): bool
    {
        if (!$this->isEnabled() || !$employee->zkbio_pin) return false;

        $allSuccess = true;

        foreach ($areaCodes as $areaCode) {
            $response = Http::timeout(10)
                ->post("{$this->baseUrl}/api/attAreaPerson/delete?access_token={$this->accessToken}", [
                    'code' => (string)$areaCode,
                    'pins' => [(int)$employee->zkbio_pin],
                ]);

            $body = $response->json();

            if (($body['code'] ?? -1) === 0) {
                Log::info('ZKBio area removed', [
                    'employee' => $employee->name,
                    'pin' => $employee->zkbio_pin,
                    'area' => $areaCode,
                ]);
            } else {
                Log::error('ZKBio area removal failed', [
                    'employee' => $employee->name,
                    'area' => $areaCode,
                    'response' => $body,
                ]);
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    /**
     * Sync employee areas — update ZKBio and local DB to match given area codes
     */
    public function syncEmployeeAreas(Employee $employee, array $areaCodes): bool
    {
        if (!$this->isEnabled() || !$employee->zkbio_pin) return false;

        // Get current area codes from DB
        $currentCodes = $employee->zkbioAreas->pluck('area_code')->toArray();

        $toAdd = array_diff($areaCodes, $currentCodes);
        $toRemove = array_diff($currentCodes, $areaCodes);

        // Push additions to ZKBio
        if (!empty($toAdd)) {
            $this->assignPersonToAreas($employee, $toAdd);
        }

        // Push removals to ZKBio
        if (!empty($toRemove)) {
            $this->removePersonFromAreas($employee, $toRemove);
        }

        // Update local DB — sync pivot
        $areaIds = ZkbioArea::where('organization_id', $this->organization->id)
            ->whereIn('area_code', $areaCodes)
            ->pluck('id')
            ->toArray();

        $employee->zkbioAreas()->sync($areaIds);

        return true;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [
            'first' => $parts[0] ?? $fullName,
            'last' => $parts[1] ?? '',
        ];
    }
}
