<?php

namespace App\Http\Controllers;

use App\Models\PlayerMeta;
use App\Models\UserWorld;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HomeWorldController extends Controller
{
    /**
     * Recover a player whose current travel destination cannot be rendered.
     * This deliberately changes only the selected world; it does not alter
     * any world, objects, inventory, or resources.
     */
    public function returnHome(): JsonResponse
    {
        $uid = (string) Auth::user()->uid;

        $hasHomeWorld = UserWorld::where('uid', $uid)
            ->where('type', 'farm')
            ->exists();

        if (! $hasHomeWorld) {
            return response()->json([
                'success' => false,
                'message' => 'Your home farm could not be found. Please contact support.',
            ], 422);
        }

        DB::transaction(static function () use ($uid): void {
            PlayerMeta::setValue($uid, 'currentWorldType', 'farm');
        });

        return response()->json([
            'success' => true,
            'worldType' => 'farm',
        ]);
    }
}
