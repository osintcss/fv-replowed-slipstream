<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\DiscordAuthenticatedSessionController;
use App\Http\Controllers\Auth\DiscordRegistrationController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register')
        ->middleware('maintenance');

    Route::get('register/name', [DiscordRegistrationController::class, 'create'])
        ->name('discord.register.name')
        ->middleware('maintenance');

    Route::post('register/name', [DiscordRegistrationController::class, 'store'])
        ->name('discord.register.store')
        ->middleware('maintenance');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login')
        ->middleware('maintenance');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('maintenance');

    Route::get('auth/discord', [DiscordAuthenticatedSessionController::class, 'redirect'])
        ->name('discord.redirect')
        ->middleware('maintenance');

    Route::get('auth/discord/launcher', [DiscordAuthenticatedSessionController::class, 'launcherRedirect'])
        ->name('discord.launcher.redirect')
        ->middleware(['maintenance', 'throttle:10,1']);

    Route::get('auth/discord/callback', [DiscordAuthenticatedSessionController::class, 'callback'])
        ->name('discord.callback')
        ->middleware('maintenance');

    Route::get('auth/discord/launcher/consume', [DiscordAuthenticatedSessionController::class, 'launcherConsume'])
        ->name('discord.launcher.consume')
        ->middleware('maintenance');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware(['auth', 'discord.member'])->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
