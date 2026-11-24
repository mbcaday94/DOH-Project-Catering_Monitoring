<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catering_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_file');
            $table->string('source_sheet', 31);
            $table->unsignedInteger('source_row');
            $table->unsignedInteger('order_row')->nullable();
            $table->string('kind', 12)->index();
            $table->string('period', 20)->nullable();
            $table->string('reference')->nullable()->index();
            $table->boolean('reference_is_date')->default(false);
            $table->string('caterer')->nullable();
            $table->text('activity')->nullable();
            $table->string('status')->nullable();
            $table->decimal('po_amount', 15, 2)->nullable();
            $table->decimal('partial_amount', 15, 2)->nullable();
            $table->decimal('reported_balance', 15, 2)->nullable();
            $table->decimal('score', 8, 3)->nullable();
            $table->json('details');
            $table->unique(['source_file', 'source_sheet', 'source_row'], 'catering_source_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catering_records');
    }
};
