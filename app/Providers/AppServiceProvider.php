<?php

namespace App\Providers;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\Paginator;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Paginator::useBootstrap();

        // AWS SES closes idle SMTP connections; force a fresh one per queued job
        // to avoid "451 Timeout waiting for data from client" on a stale, reused connection.
        Queue::before(function (): void {
            Mail::purge();
        });

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel !== 'mail') {
                return;
            }

        });

        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            if ($event->channel !== 'mail') {
                return;
            }

            Log::error('Mail notification failed', [
                'notification' => get_class($event->notification),
                'notifiable_type' => get_class($event->notifiable),
                'notifiable_id' => $event->notifiable->id ?? null,
                'data' => $event->data,
            ]);
        });

        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $message = $event->message;

            Log::warning('Mail message sent', [
                'subject' => $message->getSubject(),
                'to' => array_keys($message->getTo() ?? []),
            ]);
        });

        $messageFailedEvent = 'Illuminate\\Mail\\Events\\MessageFailed';
        if (class_exists($messageFailedEvent)) {
            Event::listen($messageFailedEvent, function (object $event): void {
                $message = $event->message ?? null;

                Log::error('Mail message failed', [
                    'subject' => $message?->getSubject(),
                    'to' => $message ? array_keys($message->getTo() ?? []) : [],
                    'error' => $event->exception?->getMessage() ?? null,
                ]);
            });
        }

    }
}
