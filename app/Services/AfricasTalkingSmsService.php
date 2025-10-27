<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;

class AfricasTalkingSmsService
{
    protected string $username;
    protected string $apiKey;
    protected string $baseUrl = 'https://api.africastalking.com/version1/messaging';
    protected ?string $defaultSender;

    public function __construct()
    {
        $this->username = config('services.africastalking.username');
        $this->apiKey   = config('services.africastalking.key');
        $this->defaultSender = config('services.africastalking.from'); // 👈 default sender
    }

    /**
     * Send an SMS using Africa's Talking API
     */
    public function sendSms(string $to, string $message, ?string $from = null): array
    {
        if (empty($to) || empty($message)) {
            throw new Exception('Recipient and message are required');
        }

        $payload = [
            'username' => $this->username,
            'to'       => $to,
            'message'  => $message,
        ];

        // 👇 Prefer explicit "from", otherwise fallback to default from env
        $sender = $from ?: $this->defaultSender;

        if ($sender) {
            $payload['from'] = $sender;
            $payload['bulkSMSMode'] = 1;
        }

        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
            'Accept' => 'application/json',
        ])->asForm()->post($this->baseUrl, $payload);

        if ($response->successful()) {
            return $response->json()['SMSMessageData']['Recipients'] ?? [];
        }

        throw new Exception('Failed to send SMS: ' . $response->body());
    }
}
