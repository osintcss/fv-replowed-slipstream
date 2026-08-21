<x-guest-layout>
    <form method="POST" action="{{ route('discord.register.store') }}">
        @csrf

        <p class="mb-6 text-sm text-gray-600">Discord is connected. Choose the name other players will see in-game.</p>

        <div>
            <x-input-label for="name" :value="__('In-game name')" />
            <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name')" required autofocus maxlength="100" autocomplete="nickname" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <p class="mt-3 text-sm text-gray-600">Use a nickname rather than personal information.</p>

        <div class="mt-6 flex justify-end">
            <x-primary-button>Create Farm</x-primary-button>
        </div>
    </form>
</x-guest-layout>
