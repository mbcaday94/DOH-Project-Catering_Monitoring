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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('designation')->nullable();
            $table->string('work_status')->nullable();
        });

        Schema::create('schedule_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('category', 8);
            $table->date('starts_on');
            $table->time('starts_at')->nullable();
            $table->index(['user_id', 'starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['designation', 'work_status']);
        });
    }
};
