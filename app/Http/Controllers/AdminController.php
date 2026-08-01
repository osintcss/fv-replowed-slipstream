<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin');
    }

    public function lookupUser(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        $meta = $user->userMeta;

        if (!$meta) {
            return response()->json(['error' => 'User metadata not found.'], 404);
        }

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'cash' => $meta->cash,
            'gold' => $meta->gold,
        ]);
    }

    public function updateCurrency(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'currency' => 'required|in:cash,gold',
            'action' => 'required|in:increase,decrease',
            'amount' => 'required|numeric|min:1',
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

        $max = $field === 'cash' ? 99_999 : 999_999_999;

        if ($request->action === 'increase') {
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
        ]);
    }
}
