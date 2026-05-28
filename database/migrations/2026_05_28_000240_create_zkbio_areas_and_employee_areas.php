<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cache ZKBio areas per org
        Schema::create('zkbio_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('area_code');
            $table->string('area_name');
            $table->string('zkbio_area_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'area_code']);
        });

        // Employee <-> Area mapping
        Schema::create('employee_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zkbio_area_id')->constrained('zkbio_areas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'zkbio_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_areas');
        Schema::dropIfExists('zkbio_areas');
    }
};
