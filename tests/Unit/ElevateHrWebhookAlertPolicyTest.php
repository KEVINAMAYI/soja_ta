<?php

use App\Services\ElevateHrWebhookMonitor;

test('alerts only at configured daily thresholds', function (
    string $frequency, int $failed, int $notified, array $emails, bool $expected
) {
    $monitor = new ElevateHrWebhookMonitor;
    expect($monitor->shouldAlert([
        'threshold' => 3,
        'frequency' => $frequency,
        'emails' => $emails,
    ], $failed, $notified))->toBe($expected);
})->with([
    'disabled without recipients' => ['once_daily', 3, 0, [], false],
    'below threshold' => ['once_daily', 2, 0, ['hr@example.test'], false],
    'at threshold' => ['once_daily', 3, 0, ['hr@example.test'], true],
    'once daily even after more failures' => ['once_daily', 12, 3, ['hr@example.test'], false],
    'delayed initial alert' => ['once_daily', 8, 0, ['hr@example.test'], true],
    'repeat initial threshold' => ['every_threshold', 3, 0, ['hr@example.test'], true],
    'repeat below next threshold' => ['every_threshold', 5, 3, ['hr@example.test'], false],
    'repeat at next threshold' => ['every_threshold', 6, 3, ['hr@example.test'], true],
    'repeat duplicate job' => ['every_threshold', 6, 6, ['hr@example.test'], false],
    'repeat delayed worker next bucket' => ['every_threshold', 12, 8, ['hr@example.test'], true],
    'repeat delayed worker same bucket' => ['every_threshold', 8, 7, ['hr@example.test'], false],
]);
