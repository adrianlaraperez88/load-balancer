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
        $connection = config('load-balancer.database_connection');
        if (!Schema::connection($connection)->hasTable('ip_rules')) {
            Schema::connection($connection)->create('ip_rules', function (Blueprint $table) {
                $table->id();
                $table->string('ip_address');
                $table->foreignId('upstream_node_id')->constrained('upstream_nodes')->onDelete('cascade');
                $table->timestamps();
                
                $table->unique('ip_address');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('load-balancer.database_connection'))->dropIfExists('ip_rules');
    }
};
