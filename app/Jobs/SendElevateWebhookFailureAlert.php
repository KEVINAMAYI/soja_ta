<?php

namespace App\Jobs;

use App\Notifications\ElevateWebhookFailureAlert;
use App\Services\ElevateHrWebhookMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SendElevateWebhookFailureAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $organizationId, public string $organizationName, public string $date) {}

    public function handle(ElevateHrWebhookMonitor $monitor): void
    {
        DB::transaction(function () use ($monitor) {
            $stats = DB::table('elevate_webhook_daily_stats')
                ->where('organization_id', $this->organizationId)->where('date', $this->date)
                ->lockForUpdate()->first();
            $settings = $monitor->settings($this->organizationId);

            if (! $stats || ! $monitor->shouldAlert($settings, (int) $stats->failed, (int) $stats->last_notified_failed)) {
                return;
            }

            $failures = DB::table('failed_elevate_webhooks')
                ->where('organization_id', $this->organizationId)->latest('id')->limit(5)
                ->get()->map(function ($failure) {
                    $errors = $failure->validation_errors === null
                        ? [] : json_decode($failure->validation_errors, true, 512, JSON_THROW_ON_ERROR);

                    return $failure->reason.' '.implode(' ', array_slice(collect($errors)->flatten()->all(), 0, 3));
                })->all();

            Notification::route('mail', $settings['emails'])->notify(new ElevateWebhookFailureAlert(
                $this->organizationName, $this->date, (int) $stats->received, (int) $stats->failed, $failures,
            ));

            DB::table('elevate_webhook_daily_stats')->where('id', $stats->id)
                ->update(['last_notified_failed' => $stats->failed]);
        });
    }
}
