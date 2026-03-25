<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_trails')) {
            return;
        }

        Schema::create('audit_trails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name', 150)->nullable();
            $table->string('user_email', 150)->nullable();
            $table->string('user_role', 100)->nullable();
            $table->string('module', 80)->index();
            $table->string('action', 80)->index();
            $table->string('entity_type', 120)->nullable()->index();
            $table->string('entity_id', 120)->nullable()->index();
            $table->string('entity_label', 191)->nullable();
            $table->text('description');
            $table->longText('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_trails');
    }
};
