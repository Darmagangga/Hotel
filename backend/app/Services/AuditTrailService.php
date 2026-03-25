<?php

namespace App\Services;

use App\Models\AuditTrail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AuditTrailService
{
    private static bool $ensured = false;

    public function record(array $entry, ?Request $request = null, ?array $actor = null): void
    {
        $this->ensureTableExists();

        $resolvedActor = $actor;
        if (!$resolvedActor && $request) {
            $resolvedActor = $request->attributes->get('pms_user');
        }

        AuditTrail::query()->create([
            'user_id' => $resolvedActor['id'] ?? null,
            'user_name' => $resolvedActor['name'] ?? null,
            'user_email' => $resolvedActor['email'] ?? null,
            'user_role' => $resolvedActor['role'] ?? null,
            'module' => (string) ($entry['module'] ?? 'system'),
            'action' => (string) ($entry['action'] ?? 'updated'),
            'entity_type' => $entry['entity_type'] ?? null,
            'entity_id' => isset($entry['entity_id']) ? (string) $entry['entity_id'] : null,
            'entity_label' => $entry['entity_label'] ?? null,
            'description' => (string) ($entry['description'] ?? ''),
            'metadata' => $entry['metadata'] ?? null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    public function ensureTableExists(): void
    {
        if (self::$ensured || Schema::hasTable('audit_trails')) {
            self::$ensured = true;
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

        self::$ensured = true;
    }
}
