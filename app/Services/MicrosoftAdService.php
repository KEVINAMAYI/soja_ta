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
        //TO DO(UNASSIGNED): Implement actual token retrieval from Microsoft AD relevant to each client
        // $response = Http::asForm()->post(
        //     "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
        //     [
        //         'grant_type'    => 'client_credentials',
        //         'client_id'     => $this->clientId,
        //         'client_secret' => $this->clientSecret,
        //         'scope'         => 'https://graph.microsoft.com/.default',
        //     ]
        // );

        // if ($response->failed()) {
        //     throw new \Exception('Failed to get AD token: ' . $response->body());
        // }

        // return $response->json('access_token');

        return "";
    }

    /**
     * Fetches ALL users with pagination handled automatically
     */
    public function getAllUsers(): array
    {
        $token = $this->getAccessToken();
        $users = [];

        // TO DO(UNASSIGNED): Use actual url for every client request to Microsoft Graph API
        // for now we leave it blank
        $url = '';
        // $url   = 'https://graph.microsoft.com/v1.0/users?$select=id,displayName,givenName,surname,jobTitle,mail,mobilePhone,businessPhones,userPrincipalName';

        while ($url) {
            $response = Http::withToken($token)->get($url);

            if ($response->failed()) {
                Log::error('AD fetch failed', ['status' => $response->status(), 'body' => $response->body()]);
                break;
            }

            $data   = $response->json();
            $users  = array_merge($users, $data['value'] ?? []);
            $url    = $data['@odata.nextLink'] ?? null; // auto-paginate
        }

        return $users;
    }

    /**
     * Filter out system/automation accounts with no real name or job title
     */
    public function filterValidUsers(array $users): array
    {
        return array_values(array_filter($users, function ($user) {
            $upn  = strtolower($user['userPrincipalName'] ?? '');
            $name = trim($user['displayName'] ?? '');

            // Skip system accounts and onmicrosoft ghost accounts
            if (str_contains($upn, 'onmicrosoft.com') && !str_contains($upn, 'cosmos-pharm')) return false;
            if (in_array(strtolower($name), ['ad', 'cosmos automation', ''])) return false;
            if (empty($user['surname']) && empty($user['givenName'])) return false;

            return true;
        }));
    }
}
