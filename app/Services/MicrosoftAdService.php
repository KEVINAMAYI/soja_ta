<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftAdService
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->tenantId     = config('services.azure.tenant_id');
        $this->clientId     = config('services.azure.client_id');
        $this->clientSecret = config('services.azure.client_secret');
    }

    public function getAccessToken(): string
    {
        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ]
        );

        if ($response->failed()) {
            throw new \Exception('Failed to get AD token: ' . $response->body());
        }

        return $response->json('access_token');
    }

    public function getAllUsers(): array
    {
        $token = $this->getAccessToken();
        $users = [];

        $fields = implode(',', [
            'id', 'displayName', 'givenName', 'surname',
            'jobTitle', 'mail', 'mobilePhone', 'businessPhones',
            'userPrincipalName', 'department', 'employeeId',
            'companyName', 'officeLocation', 'accountEnabled',
            'onPremisesSamAccountName', 'onPremisesDistinguishedName',
            'onPremisesExtensionAttributes',
        ]);

        $url = "https://graph.microsoft.com/v1.0/users?\$select={$fields}";

        while ($url) {
            $response = Http::withToken($token)->get($url);

            if ($response->failed()) {
                Log::error('AD fetch failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                break;
            }

            $data  = $response->json();
            $users = array_merge($users, $data['value'] ?? []);
            $url   = $data['@odata.nextLink'] ?? null;
        }

        return $users;
    }

    public function filterValidUsers(array $users): array
    {
        return array_values(array_filter($users, function ($user) {
            $upn  = strtolower($user['userPrincipalName'] ?? '');
            $name = trim($user['displayName'] ?? '');

            if (str_contains($upn, 'onmicrosoft.com') && !str_contains($upn, 'cosmos-pharm')) return false;
            if (in_array(strtolower($name), ['ad', 'cosmos automation', ''])) return false;
            if (empty($user['surname']) && empty($user['givenName'])) return false;

            return true;
        }));
    }

    /**
     * Parse section and division from onPremisesDistinguishedName
     * DN example: CN=Name,OU=CSV,OU=Quality Assurance,OU=Quality,OU=COSMOS,DC=cosmos,DC=local
     * OU order (left to right): section, department, division, company
     */
    public function parseDnOUs(string $dn): array
    {
        preg_match_all('/OU=([^,]+)/', $dn, $matches);
        $ous = $matches[1] ?? [];

        return [
            'section'  => $ous[0] ?? null, // e.g. CSV
            'division' => $ous[2] ?? null, // e.g. Quality
        ];
    }
}
