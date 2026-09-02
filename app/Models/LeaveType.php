<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'icon',
        'annual_entitlement_days',
        'weekends_included',
        'holidays_included',
        'is_active',
    ];

    protected $casts = [
        'annual_entitlement_days' => 'decimal:1',
        'is_active' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function balances()
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function calculateNumberOfDaysFromLeaveStartAndEndDates(Carbon $startDate, Carbon $endDate)
    {
        $organization = $this->organization;

        // Calculate the total number of days between the start and end dates
        $totalDays = (int) floor($startDate->startOfDay()->diffInDays($endDate->copy()->startOfDay())) + 1; // +1 to include the start date

        // Initialize the count of weekends and holidays
        $weekendsCount = 0;
        $holidaysCount = 0;

        // Loop through each day in the range to count weekends and holidays
        for ($date = clone $startDate; $date <= $endDate; $date->addDay()) {
            // Check if the current date is a weekend
            if (!$this->weekends_included && ($date->isWeekend())) {
                $weekendsCount++;
            }

            // Check if the current date is a holiday
            if (!$this->holidays_included && Holiday::where('organization_id', $organization->id)
                ->where('day_of_month', $date->day)
                ->where('month', $date->month)
                ->exists()) {
                $holidaysCount++;
            }
        }

        // Calculate the effective leave days by subtracting weekends and holidays
        $effectiveLeaveDays = $totalDays - ($weekendsCount + $holidaysCount);

        return [
            'total_days' => $totalDays,
            'weekends_count' => $weekendsCount,
            'holidays_count' => $holidaysCount,
            'effective_leave_days' => max(0, $effectiveLeaveDays), // Ensure non-negative
        ];
    }

    public function calculateEndDateWithStartDateAndNumberOfDays(Carbon $startDate, int $numberOfDays)
    {
        $organization = $this->organization;

        $weekendsCount = 0;
        $holidaysCount = 0;

        // Initialize the count of effective leave days
        $effectiveLeaveDays = 0;
        $currentDate = clone $startDate;

        // Loop until we reach the desired number of effective leave days
        while ($effectiveLeaveDays < $numberOfDays) {
            // Check if the current date is a weekend
            if (!$this->weekends_included && $currentDate->isWeekend()) {
                // Skip weekends if they are not included
                $currentDate->addDay();

                $weekendsCount++;

                continue;
            }

            // Check if the current date is a holiday
            if (!$this->holidays_included && Holiday::where('organization_id', $organization->id)
                ->where('day_of_month', $currentDate->day)
                ->where('month', $currentDate->month)
                ->exists()) {

                $holidaysCount++;
                // Skip holidays if they are not included
                $currentDate->addDay();
                continue;
            }

            // Increment the effective leave days count
            $effectiveLeaveDays++;
            // Move to the next day
            $currentDate->addDay();
        }

        return [
            'weekends_count' => $weekendsCount,
            'holidays_count' => $holidaysCount,
            'end_date' => $currentDate->subDay(), // Subtract one day to get the last effective leave day
        ];
    }

    public function calculateReturnWithEndDate(Carbon $endDate, Shift $shift)
    {
        // shift pattern days format ["Mon","Tue","Wed","Thu","Fri"]
        $shiftPatternDays = $shift->pattern_days; // Example: ["Mon","Tue","Wed","Thu","Fri"]

        // if no pattern days return the next day after end date
        if (empty($shiftPatternDays)) {
            return $endDate->copy()->addDay();
        }

        // else check the shift pattern to calculate the return date if next day after end date is among pattern days
        // then return that date as the return date else get the next available date according to the shift pattern
        $nextDay = $endDate->copy()->addDay();
        while (!in_array($nextDay->format('D'), $shiftPatternDays)) {
            $nextDay->addDay();
        }
        return $nextDay;
        
    }
    
}
