<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('timezone')
                ->default('Atlantic/Canary')
                ->after('odoo_password');

            $table->string('whatsapp_webhook_url')
                ->nullable()
                ->after('timezone');

            $table->string('assigned_phone_number')
                ->nullable()
                ->after('whatsapp_webhook_url');

            $table->string('appointment_status')
                ->default('request')
                ->after('assigned_phone_number');

            $table->string('slug')
                ->unique()
                ->nullable()
                ->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'slug',
                'appointment_status',
                'assigned_phone_number',
                'whatsapp_webhook_url',
                'timezone',
            ]);
        });
    }
};
