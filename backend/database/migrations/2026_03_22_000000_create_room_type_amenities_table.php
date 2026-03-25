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
        if (!Schema::hasTable('room_type_amenities')) {
            Schema::create('room_type_amenities', function (Blueprint $table) {
                $table->id();
                $table->string('room_type_code', 50);
                $table->unsignedBigInteger('inventory_item_id');
                $table->integer('quantity');
                $table->timestamps();

                $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->onDelete('cascade');
                // Assuming RoomType uses code or id. If RoomType code is the PK or mapped.
                // We'll just define the foreign key if needed, or leave it soft.
            });
        }
        
        // Ensure inventory_movements table exists or we'll create it if somehow missing
        if (!Schema::hasTable('inventory_movements')) {
            Schema::create('inventory_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('item_id');
                $table->string('movement_type', 20); // IN, OUT
                $table->integer('quantity');
                $table->string('reference_id', 50)->nullable();
                $table->string('reference_type', 50)->nullable();
                $table->text('reference_desc')->nullable();
                $table->date('movement_date');
                $table->decimal('cost_per_unit', 15, 2)->default(0);
                $table->string('source', 50)->default('system');
                $table->timestamps();

                $table->foreign('item_id')->references('id')->on('inventory_items')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_type_amenities');
        Schema::dropIfExists('inventory_movements');
    }
};
