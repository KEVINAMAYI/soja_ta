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
        // Clean and format report type (remove underscores, capitalize words)
        $formattedReportType = ucwords(str_replace('_', ' ', $this->setting->report_type));

        // Try to fetch organization name (fallback to app name if missing)
        $organizationName = $this->setting->organization->name ?? config('app.name');

        return $this->subject("{$formattedReportType} Report - {$organizationName}")
            ->view('emails.reports.default')
            ->with([
                'reportType'  => $formattedReportType,
                'frequency'   => $this->setting->frequency,
                'organization'=> $organizationName,
            ])
            ->attach($this->filePath);
    }

}
