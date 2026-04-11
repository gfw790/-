<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 30)->unique();
            $table->string('name', 100);
            $table->date('birth_date')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 100)->nullable()->unique();
            $table->string('address', 255)->nullable();
            $table->date('hire_date');
            $table->date('resign_date')->nullable();
            $table->string('status', 20)->default('재직');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('job_title', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
