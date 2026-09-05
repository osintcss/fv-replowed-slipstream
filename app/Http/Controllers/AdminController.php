<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin');
    }

    public function lookupUser(Request $request)
    {
        $request->validate([
            'query' => 'nullable|string|max:120',
            'email' => 'nullable|email',
        ]);

        $query = trim((string) ($request->input('query') ?? $request->input('email')));
        if ($query === '') {
            return response()->json(['error' => 'Enter a player email, UID, or exact account name.'], 422);
        }

        $user = User::query()
            ->where('email', $query)
            ->orWhere('uid', $query)
            ->orWhere('name', $query)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        $meta = $user->userMeta;

        if (!$meta) {
            return response()->json(['error' => 'User metadata not found.'], 404);
        }

        return response()->json([
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
            'cash' => $meta->cash,
            'gold' => $meta->gold,
            'xp' => $meta->xp,
        ]);
    }

    /**
     * Temporarily switch an administrator's session to a player account.
     * Player credentials and Discord identity are never read or changed.
     */
    public function impersonate(Request $request)
    {
        $request->validate(['uid' => 'required|string|max:20']);

        $administrator = $request->user();
        $target = User::where('uid', $request->string('uid')->toString())->firstOrFail();

        if ($target->is($administrator)) {
            return back()->withErrors(['impersonation' => 'You are already signed in as that account.']);
        }

        if ($target->is_admin) {
            return back()->withErrors(['impersonation' => 'Administrator accounts cannot be impersonated.']);
        }

        if ($request->session()->has('impersonator_user_id')) {
            return back()->withErrors(['impersonation' => 'Stop the current impersonation before starting another one.']);
        }

        $request->session()->put('impersonator_user_id', $administrator->getKey());
        $request->session()->put('impersonated_user_id', $target->getKey());
        Auth::login($target);
        $request->session()->regenerate();

        Log::notice('Administrator impersonation started', [
            'administrator_id' => $administrator->getKey(),
            'administrator_uid' => $administrator->uid,
            'target_id' => $target->getKey(),
            'target_uid' => $target->uid,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('play');
    }

    /** Restore the administrator identity saved when impersonation began. */
    public function stopImpersonating(Request $request)
    {
        $administratorId = $request->session()->get('impersonator_user_id');
        abort_unless($administratorId, 403);

        $target = $request->user();
        $administrator = User::whereKey($administratorId)
            ->where('is_admin', true)
            ->first();

        if (!$administrator) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'discord' => 'The original administrator account is no longer available.',
            ]);
        }

        Auth::login($administrator);
        $request->session()->forget(['impersonator_user_id', 'impersonated_user_id']);
        $request->session()->regenerate();

        Log::notice('Administrator impersonation stopped', [
            'administrator_id' => $administrator->getKey(),
            'administrator_uid' => $administrator->uid,
            'target_id' => $target?->getKey(),
            'target_uid' => $target?->uid,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin');
    }

    public function updateCurrency(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'currency' => 'required|in:cash,gold,xp',
            'action' => 'required|in:increase,decrease,set',
            'amount' => 'required|integer|min:0',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        $meta = $user->userMeta;

        if (!$meta) {
            return response()->json(['error' => 'User metadata not found.'], 404);
        }

        $field = $request->currency;
        $amount = (int) $request->amount;

        $max = match ($field) {
            'cash' => UserMeta::CASH_MAX,
            'gold' => UserMeta::GOLD_MAX,
            'xp' => UserMeta::XP_MAX,
        };

        if ($request->action === 'set') {
            $meta->$field = min($amount, $max);
        } elseif ($amount < 1) {
            return response()->json(['error' => 'Amount must be at least 1.'], 422);
        } elseif ($request->action === 'increase') {
            $meta->$field = min($meta->$field + $amount, $max);
        } else {
            if ($meta->$field < $amount) {
                return response()->json(['error' => 'Insufficient ' . $field . '. Current: ' . $meta->$field], 422);
            }
            $meta->$field -= $amount;
        }

        $meta->save();

        return response()->json([
            'message' => ucfirst($field) . ' updated successfully.',
            'cash' => $meta->cash,
            'gold' => $meta->gold,
            'xp' => $meta->xp,
        ]);
    }
}
