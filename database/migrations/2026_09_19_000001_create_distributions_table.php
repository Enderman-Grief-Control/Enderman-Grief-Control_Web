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
        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('name');
            $table->string('loader');
            $table->string('project_identifier');
            $table->string('listing_url');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'project_identifier']);
            $table->index(['provider', 'active']);
            $table->index(['loader', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributions');
    }
};
