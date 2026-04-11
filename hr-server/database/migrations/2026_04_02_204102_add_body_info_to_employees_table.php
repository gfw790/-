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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('blood_type', 5)->nullable()->after('birth_date');
            $table->string('shoe_size', 10)->nullable()->after('blood_type');
            $table->string('top_size', 10)->nullable()->after('shoe_size');
            $table->string('bottom_size', 10)->nullable()->after('top_size');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['blood_type', 'shoe_size', 'top_size', 'bottom_size']);
        });
    }
};
