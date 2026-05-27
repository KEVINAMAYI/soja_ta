<?php

namespace App\Mail;

use App\Models\ReportSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public ReportSetting $setting;
    public string $filePath;

    public function __construct(ReportSetting $setting, string $filePath)
    {
        $this->setting = $setting;
        $this->filePath = $filePath;
    }
    public function build()
    {
        $formattedReportType = ucwords(str_replace('_', ' ', $this->setting->report_type));
        $organizationName = $this->setting->organization->name ?? config('app.name');

        \Log::info('ReportMail build data', [
            'reportType'   => $formattedReportType,
            'frequency'    => $this->setting->frequency,
            'organization' => $organizationName,
            'filePath'     => $this->filePath,
        ]);

        return $this->subject("{$formattedReportType} Report - {$organizationName}")
            ->view('emails.reports.default')
            ->with([
                'reportType'   => $formattedReportType,
                'frequency'    => $this->setting->frequency,
                'organization' => $organizationName,
            ])
            ->attach($this->filePath);
    }


}
