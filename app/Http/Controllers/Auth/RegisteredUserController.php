<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Exceptions\RegistrationCapacityReached;
use App\Models\User;
use App\Models\UserMeta;
use App\Models\UserAvatar;
use App\Support\RegistrationCapacity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register', [
            'registrationFull' => RegistrationCapacity::isFull(),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'firstName' => ['required', 'string', 'max:50'],
            'lastName' => ['required', 'string', 'max:50']
        ]);

        try {
            $user = DB::transaction(function () use ($request): User {
                RegistrationCapacity::ensureAvailable();

                // Generate unique UID (range: 1111111111-9999999999).
                $maxRetries = 10;
                $retries = 0;

                do {
                    $newUid = (string) rand(1111111111, 9999999999);

                    try {
                        $user = User::create([
                            'name' => $request->firstName . ' ' . $request->lastName,
                            'email' => $request->email,
                            'password' => Hash::make($request->password),
                            'uid' => $newUid,
                        ]);
                        break;
                    } catch (QueryException $exception) {
                        if ($exception->errorInfo[1] == 1062 && str_contains($exception->getMessage(), 'uid')) {
                            $retries++;
                            continue;
                        }
                        throw $exception;
                    }
                } while ($retries < $maxRetries);

                if (!isset($user)) {
                    throw new \Exception('Unable to generate unique UID after ' . $maxRetries . ' attempts. Please try again.');
                }

                UserMeta::create([
                    'uid' => $newUid,
                    'firstName' => $request->firstName,
                    'lastName' => $request->lastName,
                ]);

                UserAvatar::create([
                    'uid' => $newUid,
                ]);

                return $user;
            });
        } catch (RegistrationCapacityReached $exception) {
            return back()->withErrors(['registration' => $exception->getMessage()]);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('play', absolute: false));
    }
}
