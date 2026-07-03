<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Approval Required — {{ $employeeName }}</title>
</head>
<body style="font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 0;">

{{-- Preheader: hidden preview text shown by inbox clients next to the subject line --}}
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;opacity:0;">
    {{ $employeeName }} requested {{ $totalDays }} day{{ $totalDays === 1 ? '' : 's' }} of {{ $leaveTypeName }} — Level {{ $level }}@if($totalLevels) of {{ $totalLevels }}@endif approval needed.
</div>

<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 32px 16px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0"
                   style="width: 600px; max-width: 100%; background: #ffffff; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.07); overflow: hidden;">

                {{-- Header --}}
                <tr>
                    <td style="background: {{ $brandColor }}; padding: 28px 32px; text-align: center;">
                        <p style="margin: 0 0 6px; color: #ffffff; font-size: 12px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; opacity: 0.75;">
                            {{ $orgName }}
                        </p>
                        <h1 style="margin: 0; font-size: 22px; color: #ffffff; font-weight: 700; letter-spacing: -0.3px;">
                            Leave Approval Required
                        </h1>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding: 32px;">

                        {{-- Requester summary --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
                            <tr>
                                <td width="48" valign="top">
                                    <table cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td width="44" height="44" align="center" valign="middle"
                                                style="width: 44px; height: 44px; border-radius: 50%; background: {{ $employeeAvatarColor }};
                                                       color: #ffffff; font-size: 15px; font-weight: 700; text-align: center;">
                                                {{ $employeeInitials }}
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                <td valign="top" style="padding-left: 14px;">
                                    <p style="margin: 0; color: #111827; font-size: 16px; line-height: 1.5;">
                                        <strong>{{ $employeeName }}</strong> requested
                                        <strong>{{ $totalDays }} day{{ $totalDays === 1 ? '' : 's' }}</strong> of
                                        <strong>{{ $leaveTypeName }}</strong>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- Level progress stepper --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; margin-bottom: 24px;">
                            <tr>
                                <td style="padding: 16px 20px;" align="center">
                                    <table cellpadding="0" cellspacing="0" align="center">
                                        <tr>
                                            @for ($i = 1; $i <= max((int) $totalLevels, 1); $i++)
                                                <td style="padding: 0 3px;">
                                                    <table cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td width="24" height="24" align="center" valign="middle"
                                                                style="width: 24px; height: 24px; border-radius: 50%; font-size: 11px; font-weight: 700;
                                                                       {{ $i < $level
                                                                            ? 'background:' . $brandColor . ';color:#ffffff;'
                                                                            : ($i == $level
                                                                                ? 'background:' . $brandColor . ';color:#ffffff;border:3px solid #bfdbfe;'
                                                                                : 'background:#ffffff;color:#94a3b8;border:1px solid #cbd5e1;') }}">
                                                                {!! $i < $level ? '&#10003;' : $i !!}
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                @if ($i < max((int) $totalLevels, 1))
                                                    <td style="padding: 0;">
                                                        <div style="width: 24px; height: 2px; background: {{ $i < $level ? $brandColor : '#cbd5e1' }};"></div>
                                                    </td>
                                                @endif
                                            @endfor
                                        </tr>
                                    </table>
                                    <p style="margin: 10px 0 0; font-size: 12px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase; color: #1e40af;">
                                        Level {{ $level }}@if($totalLevels) of {{ $totalLevels }}@endif &mdash; awaiting your decision
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- Details Card --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
                                      margin-bottom: 24px; overflow: hidden;">
                            <tr>
                                <td style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0;">
                                    <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                              letter-spacing: 0.8px; color: #6b7280;">Leave Type</p>
                                    <p style="margin: 4px 0 0; font-size: 16px; font-weight: 600; color: {{ $brandColor }};">
                                        {{ $leaveTypeIcon }} {{ $leaveTypeName }}
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td width="34%" style="padding: 14px 20px; border-right: 1px solid #e2e8f0;">
                                                <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                                          letter-spacing: 0.8px; color: #6b7280;">Start Date</p>
                                                <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                                          color: #111827;">{{ $startDate }}</p>
                                            </td>
                                            <td width="34%" style="padding: 14px 20px; border-right: 1px solid #e2e8f0;">
                                                <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                                          letter-spacing: 0.8px; color: #6b7280;">End Date</p>
                                                <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                                          color: #111827;">{{ $endDate }}</p>
                                            </td>
                                            <td width="32%" style="padding: 14px 20px;">
                                                <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                                          letter-spacing: 0.8px; color: #6b7280;">Duration</p>
                                                <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                                          color: #111827;">{{ $totalDays }} day{{ $totalDays === 1 ? '' : 's' }}</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>

                        @if($reason)
                            <table width="100%" cellpadding="0" cellspacing="0"
                                   style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
                                          margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 16px 20px;">
                                        <p style="margin: 0 0 6px; font-size: 11px; text-transform: uppercase;
                                                  letter-spacing: 0.8px; color: #92400e;">Reason</p>
                                        <p style="margin: 0; font-size: 14px; color: #78350f; line-height: 1.6;">
                                            {{ $reason }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        @endif

                        {{-- CTA --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 8px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ $reviewUrl }}"
                                       style="display: inline-block; background: {{ $brandColor }}; color: #ffffff;
                                              text-decoration: none; font-size: 14px; font-weight: 600;
                                              padding: 13px 36px; border-radius: 8px;">
                                        Review &amp; Approve
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin: 24px 0 0; font-size: 14px; color: #374151;">
                            Regards,<br>
                            <strong>{{ $orgName }}</strong>
                        </p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background: {{ $brandColor }}; height: 3px; line-height: 3px; font-size: 0;">&nbsp;</td>
                </tr>
                <tr>
                    <td style="background: #f9fafb; padding: 18px 32px; text-align: center;">
                        <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                            This is an automated notification from {{ $orgName }}.
                        </p>
                        <p style="margin: 4px 0 0; font-size: 12px; color: #d1d5db;">
                            &copy; {{ date('Y') }} {{ $orgName }}. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
