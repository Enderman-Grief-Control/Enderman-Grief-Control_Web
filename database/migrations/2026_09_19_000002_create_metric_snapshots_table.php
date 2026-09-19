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
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('downloads');
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['distribution_id', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};
