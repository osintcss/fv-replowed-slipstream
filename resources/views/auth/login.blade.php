<x-guest-layout>
    <a href="{{ route('discord.redirect') }}" class="flex w-full items-center justify-center rounded-md px-4 py-2 font-semibold" style="background-color: #5865F2; color: #ffffff; text-decoration: none;">
        Sign in with Discord
    </a>
    <x-input-error :messages="$errors->get('discord')" class="mt-4" />
</x-guest-layout>
