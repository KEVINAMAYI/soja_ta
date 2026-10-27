<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->unique(['organization_id', 'normalized_name']);
            $table->timestamps();
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->decimal('num_of_days', 8, 2)->nullable();
            $table->json('leave_days')->nullable();
            $table->uuid('elevatehr_reference')->nullable();
            $table->uuid('elevatehr_event_id')->nullable();
            $table->unsignedBigInteger('elevatehr_sequence')->nullable();
            $table->unique(['organization_id', 'elevatehr_reference']);
        });

        DB::table('leaves')->orderBy('id')->chunkById(500, function ($leaves) {
            foreach ($leaves as $leave) {
                DB::table('leaves')->where('id', $leave->id)->update([
                    'num_of_days' => Carbon::parse($leave->start_date)->diffInDays(Carbon::parse($leave->end_date)) + 1,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'elevatehr_reference']);
            $table->dropColumn([
                'num_of_days', 'leave_days', 'elevatehr_reference',
                'elevatehr_event_id', 'elevatehr_sequence',
            ]);
        });

        Schema::dropIfExists('leave_types');
    }
};
