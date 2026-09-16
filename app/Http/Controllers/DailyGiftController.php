<?php

namespace App\Http\Controllers;

use App\Models\DailyGift;
use App\Models\UserMeta;
use App\Support\ResourceAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DailyGiftController extends Controller
{
    private const MIN_CASH = 1;
    private const MAX_CASH = 5;
    private const MIN_GOLD = 100;
    private const MAX_GOLD = 1000;

    public function checkStatus()
    {
        $user = Auth::user();
        $uid = $user->uid;
        $today = Carbon::today();

        $todayClaim = DailyGift::where('uid', $uid)
            ->whereDate('claimed_at', $today)
            ->first();

        return response()->json([
            'canClaim' => $todayClaim === null
        ]);
    }

    public function claim()
    {
        $user = Auth::user();
        $uid = $user->uid;
        $today = Carbon::today();

        $todayClaim = DailyGift::where('uid', $uid)
            ->whereDate('claimed_at', $today)
            ->first();

        if ($todayClaim) {
            return response()->json(['success' => false, 'message' => 'Already claimed today'], 400);
        }

        $cashAmount = random_int(self::MIN_CASH, self::MAX_CASH);
        $goldAmount = random_int(self::MIN_GOLD, self::MAX_GOLD);

        $userMeta = UserMeta::where('uid', $uid)->first();
        if ($userMeta) {
            $userMeta->cash = min($userMeta->cash + $cashAmount, 99999);
            $userMeta->gold = min($userMeta->gold + $goldAmount, 999999999);
            $userMeta->save();
            ResourceAudit::record($uid, 'daily_gift', $goldAmount, 0, $cashAmount);
        }

        DailyGift::create([
            'uid' => $uid,
            'cash_amount' => $cashAmount,
            'gold_amount' => $goldAmount,
            'claimed_at' => $today
        ]);

        return response()->json([
            'success' => true,
            'cashAmount' => $cashAmount,
            'goldAmount' => $goldAmount,
            'message' => "You received {$cashAmount} Farm Cash and {$goldAmount} Coins!"
        ]);
    }
}
