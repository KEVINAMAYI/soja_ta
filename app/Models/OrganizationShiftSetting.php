<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationShiftSetting extends Model
{
    protected $table = 'organization_shift_settings';

    protected $fillable = [
        'organization_id',
        'allow_multi_shift_assignment',
        'auto_detect_shift_on_checkin',
        'require_shift_selection_on_checkin',
        'shift_change_cooldown_minutes',
        'notify_on_shift_mismatch',
        'log_shift_detection_attempts',
    ];

    protected $casts = [
        'allow_multi_shift_assignment' => 'boolean',
        'auto_detect_shift_on_checkin' => 'boolean',
        'require_shift_selection_on_checkin' => 'boolean',
        'notify_on_shift_mismatch' => 'boolean',
        'log_shift_detection_attempts' => 'boolean',
        'shift_change_cooldown_minutes' => 'integer',
    ];

    // ========================================
    // RELATIONSHIPS
    // ========================================
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ========================================
    // STATIC METHODS
    // ========================================

    /**
     * Get shift settings for an organization (with defaults)
     */
    public static function getForOrganization(int $organizationId): self
    {
        $settings = self::where('organization_id', $organizationId)->first();

        if ($settings) {
            return $settings;
        }

        // Return default settings if none exist
        return self::createDefaultForOrganization($organizationId);
    }

    /**
     * Create default shift settings for an organization
     */
    public static function createDefaultForOrganization(int $organizationId): self
    {
        return self::create([
            'organization_id' => $organizationId,
            'allow_multi_shift_assignment' => false,
            'auto_detect_shift_on_checkin' => true,
            'require_shift_selection_on_checkin' => false,
            'shift_change_cooldown_minutes' => 240, // 4 hours
            'notify_on_shift_mismatch' => true,
            'log_shift_detection_attempts' => true,
        ]);
    }

    /**
     * Get or create settings for an organization
     */
    public static function getOrCreateForOrganization(int $organizationId): self
    {
        return self::firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'allow_multi_shift_assignment' => false,
                'auto_detect_shift_on_checkin' => true,
                'require_shift_selection_on_checkin' => false,
                'shift_change_cooldown_minutes' => 240,
                'notify_on_shift_mismatch' => true,
                'log_shift_detection_attempts' => true,
            ]
        );
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Check if multi-shift assignment is enabled
     */
    public function isMultiShiftEnabled(): bool
    {
        return $this->allow_multi_shift_assignment === true;
    }

    /**
     * Check if auto-detection is enabled
     */
    public function isAutoDetectionEnabled(): bool
    {
        return $this->auto_detect_shift_on_checkin === true;
    }

    /**
     * Check if shift selection is required
     */
    public function isShiftSelectionRequired(): bool
    {
        return $this->require_shift_selection_on_checkin === true;
    }

    /**
     * Get cooldown minutes
     */
    public function getCooldownMinutes(): int
    {
        return $this->shift_change_cooldown_minutes ?? 240;
    }

    /**
     * Check if shift mismatch notifications are enabled
     */
    public function shouldNotifyOnMismatch(): bool
    {
        return $this->notify_on_shift_mismatch === true;
    }

    /**
     * Check if detection logging is enabled
     */
    public function shouldLogDetectionAttempts(): bool
    {
        return $this->log_shift_detection_attempts === true;
    }

    // ========================================
    // CONFIGURATION METHODS
    // ========================================

    /**
     * Enable multi-shift assignment
     */
    public function enableMultiShift(): self
    {
        $this->update(['allow_multi_shift_assignment' => true]);
        return $this;
    }

    /**
     * Disable multi-shift assignment
     */
    public function disableMultiShift(): self
    {
        $this->update(['allow_multi_shift_assignment' => false]);
        return $this;
    }

    /**
     * Enable auto shift detection
     */
    public function enableAutoDetection(): self
    {
        $this->update(['auto_detect_shift_on_checkin' => true]);
        return $this;
    }

    /**
     * Disable auto shift detection
     */
    public function disableAutoDetection(): self
    {
        $this->update(['auto_detect_shift_on_checkin' => false]);
        return $this;
    }

    /**
     * Set cooldown duration
     */
    public function setCooldown(int $minutes): self
    {
        $this->update(['shift_change_cooldown_minutes' => $minutes]);
        return $this;
    }
}
