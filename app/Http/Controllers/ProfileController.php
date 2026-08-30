<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;
use App\Models\UserMeta;
use App\Models\UserAvatar;
use App\Models\UserWorld;
use App\Models\PlayerMeta;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $userMeta = $user->userMeta;

        $validated = $request->validate([
            'firstName' => 'nullable|string|max:255',
            'lastName' => 'nullable|string|max:255',
            'auto_accept_neighbor_requests' => 'sometimes|boolean',
        ]);

        $nameChanged = false;

        if (!empty($validated['firstName'])) {
            $userMeta->firstName = $validated['firstName'];
            $nameChanged = true;
        }

        if (!empty($validated['lastName'])) {
            $userMeta->lastName = $validated['lastName'];
            $nameChanged = true;
        }

        if ($nameChanged) {
            $user->name = $userMeta->firstName . ' ' . $userMeta->lastName;
            $user->save();
        }

        if (array_key_exists('auto_accept_neighbor_requests', $validated)) {
            NeighborController::setAutoAcceptNeighborRequests(
                $user->uid,
                (bool) $validated['auto_accept_neighbor_requests'],
            );
        }

        $userMeta->save();

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'firstName' => $userMeta->firstName,
            'lastName' => $userMeta->lastName,
            'autoAcceptNeighborRequests' => NeighborController::autoAcceptNeighborRequests($user->uid),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        DB::transaction(function () use ($user) {
            $neighborEntries = PlayerMeta::where('meta_key', 'like', '%_neighbors')
            ->where('meta_value', 'like', '%' . $user->uid . '%')->get();

            UserMeta::where('uid', '=', $user->uid)->delete();
            UserAvatar::where('uid', '=', $user->uid)->delete();
            UserWorld::where('uid', '=', $user->uid)->delete();
            PlayerMeta::where('uid', '=', $user->uid)->delete();
            
            $neighborEntries->each(function (PlayerMeta $entry) use ($user) {
                $neighbors = unserialize($entry->meta_value, ['allowed_classes' => false]);

                if (!is_array($neighbors)) {
                    return;
                }

                $neighbors = array_values(array_diff($neighbors, [(string) $user->uid]));
                $entry->meta_value = serialize($neighbors);
                $entry->save();
            });

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
