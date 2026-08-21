<x-guest-layout>
    <p class="mb-6 text-sm text-gray-600">Create an account with Discord, then choose the name shown on your farm.</p>

    <a href="{{ route('discord.redirect', ['intent' => 'register']) }}" class="flex w-full items-center justify-center rounded-md px-4 py-2 font-semibold" style="background-color: #5865F2; color: #ffffff; text-decoration: none;">
        Continue with Discord
    </a>
    <x-input-error :messages="$errors->get('discord')" class="mt-4" />

    <p class="mt-6 text-center text-sm text-gray-600">
        Already registered?
        <a href="{{ route('login') }}" class="text-indigo-600 hover:underline">Sign in</a>
    </p>
</x-guest-layout>
