<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Active Directory (Microsoft Entra ID) sync, org-scoped override of services.azure.* config
            $table->boolean('ad_sync_enabled')->default(false)->after('zkbio_pin_start');
            $table->string('ad_tenant_id')->nullable()->after('ad_sync_enabled');
            $table->string('ad_client_id')->nullable()->after('ad_tenant_id');
            $table->text('ad_client_secret')->nullable()->after('ad_client_id');

            // Client portal API documentation link
            $table->boolean('api_docs_enabled')->default(false)->after('ad_client_secret');
            $table->string('api_docs_url')->nullable()->after('api_docs_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'ad_sync_enabled',
                'ad_tenant_id',
                'ad_client_id',
                'ad_client_secret',
                'api_docs_enabled',
                'api_docs_url',
            ]);
        });
    }
};
