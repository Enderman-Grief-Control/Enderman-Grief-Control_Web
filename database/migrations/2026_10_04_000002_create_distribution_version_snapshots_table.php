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
        Schema::create('distribution_version_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('downloads');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['distribution_version_id', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribution_version_snapshots');
    }
};
