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
            $table->integer('appointment_type_id')->nullable()->after('assigned_phone_number');
            $table->string('whatsapp_reserved_template')->nullable()->after('whatsapp_webhook_url');
            $table->string('whatsapp_confirmed_template')->nullable()->after('whatsapp_reserved_template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('appointment_type_id');
            $table->dropColumn('whatsapp_reserved_template');
            $table->dropColumn('whatsapp_confirmed_template');
        });
    }
};
