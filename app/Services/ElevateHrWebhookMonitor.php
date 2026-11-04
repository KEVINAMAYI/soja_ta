<?php

namespace App\Services;

use App\Jobs\SendElevateWebhookFailureAlert;
use Illuminate\Support\Facades\DB;

class ElevateHrWebhookMonitor
{
    public const SETTINGS_KEY = 'elevatehr_webhook_failure_alerts';

    public function settings(string $organizationId): array
    {
        $value = DB::table('organization_settings')->where('organization_id', $organizationId)
            ->where('key', self::SETTINGS_KEY)->value('value');

        return $value === null
            ? ['threshold' => 10, 'emails' => [], 'frequency' => 'once_daily']
            : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function received(string $organizationId, string $date): void
    {
        DB::transaction(function () use ($organizationId, $date) {
            DB::table('elevate_webhook_daily_stats')->insertOrIgnore([
                'organization_id' => $organizationId,
                'date' => $date,
            ]);
            DB::table('elevate_webhook_daily_stats')
                ->where('organization_id', $organizationId)->where('date', $date)
                ->increment('received');
        }, 3);
    }

    public function failed(
        string $organizationId,
        string $date,
        ?string $ip,
        int $status,
        string $reason,
        ?array $validationErrors = null,
    ): void {
        DB::transaction(function () use ($organizationId, $date, $ip, $status, $reason, $validationErrors) {
            DB::table('failed_elevate_webhooks')->insert([
                'organization_id' => $organizationId,
                'source_ip' => $ip,
                'status_code' => $status,
                'reason' => mb_substr($reason, 0, 2000),
                'validation_errors' => $validationErrors === null ? null : json_encode($validationErrors, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            DB::table('elevate_webhook_daily_stats')
                ->where('organization_id', $organizationId)->where('date', $date)
                ->increment('failed');
        }, 3);

        $settings = $this->settings($organizationId);
        $stats = DB::table('elevate_webhook_daily_stats')
            ->where('organization_id', $organizationId)->where('date', $date)->first();

        if ($this->shouldAlert($settings, (int) $stats->failed, (int) $stats->last_notified_failed)) {
            try {
                SendElevateWebhookFailureAlert::dispatch($organizationId, $date);
            } catch (\Throwable $exception) {
                // Alert delivery must not change the result of a processed webhook.
                report($exception);
            }
        }

    }

    public function shouldAlert(array $settings, int $failed, int $lastNotifiedFailed): bool
    {
        if ($settings['emails'] === [] || $failed < $settings['threshold']) {
            return false;
        }

        return $settings['frequency'] === 'once_daily'
            ? $lastNotifiedFailed === 0
            : intdiv($failed, $settings['threshold']) > intdiv($lastNotifiedFailed, $settings['threshold']);
    }
}
