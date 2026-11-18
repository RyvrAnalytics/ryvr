<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('monthly_events')->nullable();
            $table->string('overage', 20)->default('bill');
            $table->unsignedInteger('burst_per_second')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['monthly_events', 'overage', 'burst_per_second']));
    }
};
