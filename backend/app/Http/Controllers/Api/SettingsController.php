<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    private const SETTINGS_CACHE_KEY = 'pms.settings.policies';

    public function policies(): JsonResponse
    {
        return response()->json([
            'data' => [
                'cancellationPolicy' => $this->cancellationPolicyPayload(),
            ],
        ]);
    }

    public function updatePolicies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cancellationPenaltyPercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $percent = round((float) $validated['cancellationPenaltyPercent'], 2);

        Cache::forever(self::SETTINGS_CACHE_KEY, [
            'cancellationPenaltyPercent' => $percent,
        ]);

        return response()->json([
            'message' => 'Booking policy settings updated successfully.',
            'data' => [
                'cancellationPolicy' => $this->cancellationPolicyPayload($percent),
            ],
        ]);
    }

    private function cancellationPolicyPayload(?float $percent = null): array
    {
        $settings = Cache::get(self::SETTINGS_CACHE_KEY, []);
        $resolvedPercent = $percent ?? (float) ($settings['cancellationPenaltyPercent'] ?? 0);

        return [
            'percent' => $resolvedPercent,
            'label' => rtrim(rtrim(number_format($resolvedPercent, 2, '.', ''), '0'), '.'),
            'enabled' => $resolvedPercent > 0,
        ];
    }
}
