<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">

                    <section class="mb-8 p-4 border border-red-300 dark:border-red-800 rounded-lg max-w-2xl">
                        <h3 class="text-lg font-medium mb-2">Restore player save</h3>
                        <p class="text-sm mb-4 text-gray-600 dark:text-gray-400">
                            Restores the export into the existing account whose UID is embedded in the save file. This replaces that account’s worlds, items, inventories, and game progress. Account credentials and chat are not changed.
                        </p>
                        @if (session('status'))
                            <p class="mb-3 text-sm text-green-600 dark:text-green-400">{{ session('status') }}</p>
                        @endif
                        <form method="POST" action="{{ route('admin.import-save') }}" enctype="multipart/form-data" class="space-y-3">
                            @csrf
                            <input type="file" name="save_file" accept="application/json,.json" required class="block text-sm">
                            @error('save_file')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="replace_existing_save" value="1" required class="mt-1">
                                <span>I understand this permanently replaces the target player’s current game save.</span>
                            </label>
                            @error('replace_existing_save')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            <button type="submit" onclick="return confirm('Restore this save and replace the target player’s current game data?');"
                                class="px-4 py-2 bg-red-700 text-white rounded-md text-xs font-semibold uppercase hover:bg-red-600 transition">
                                Restore save
                            </button>
                        </form>
                    </section>

                    <div x-data="{
                            email: '',
                            user: null,
                            error: '',
                            success: '',
                            loading: false,
                            cashAmount: '',
                            goldAmount: '',
                            xpAmount: '',
                            lookup() {
                                this.error = '';
                                this.success = '';
                                this.user = null;
                                if (!this.email) return;
                                this.loading = true;
                                fetch('{{ route('admin.lookup') }}', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                    body: JSON.stringify({ email: this.email })
                                })
                                .then(r => r.json().then(data => ({ ok: r.ok, data })))
                                .then(({ ok, data }) => {
                                    this.loading = false;
                                    if (!ok) { this.error = data.error; return; }
                                    this.user = data;
                                })
                                .catch(() => { this.loading = false; this.error = 'Request failed.'; });
                            },
                            update(currency, action) {
                                this.error = '';
                                this.success = '';
                                let amount = currency === 'cash' ? this.cashAmount : (currency === 'gold' ? this.goldAmount : this.xpAmount);
                                if (amount === '' || amount < 0 || (action !== 'set' && amount < 1)) { this.error = 'Enter a valid amount.'; return; }
                                this.loading = true;
                                fetch('{{ route('admin.update-currency') }}', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                    body: JSON.stringify({ email: this.email, currency, action, amount })
                                })
                                .then(r => r.json().then(data => ({ ok: r.ok, data })))
                                .then(({ ok, data }) => {
                                    this.loading = false;
                                    if (!ok) { this.error = data.error; return; }
                                    this.success = data.message;
                                    this.user.cash = data.cash;
                                    this.user.gold = data.gold;
                                    this.user.xp = data.xp;
                                })
                                .catch(() => { this.loading = false; this.error = 'Request failed.'; });
                            }
                        }">
                            <h3 class="text-lg font-medium mb-4">User Currency Management</h3>

                            {{-- Email Lookup --}}
                            <div class="mb-4">
                                <label for="email" class="block text-sm font-medium mb-1">User Email</label>
                                <div class="flex gap-2 max-w-md">
                                    <input type="email" x-model="email" id="email" placeholder="user@example.com"
                                        class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm flex-1"
                                        @keydown.enter.prevent="lookup()">
                                    <button @click="lookup()" :disabled="loading"
                                        class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                        Lookup
                                    </button>
                                </div>
                            </div>

                            {{-- Error/Success Messages --}}
                            <template x-if="error">
                                <p class="text-red-500 text-sm mb-4" x-text="error"></p>
                            </template>
                            <template x-if="success">
                                <p class="text-green-500 text-sm mb-4" x-text="success"></p>
                            </template>

                            {{-- User Info & Currency Controls --}}
                            <template x-if="user">
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 max-w-md">
                                    <p class="mb-3"><strong x-text="user.name"></strong> (<span x-text="user.email"></span>)</p>

                                    {{-- Cash --}}
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium mb-1">Cash: <span class="text-green-400 font-bold" x-text="user.cash"></span></label>
                                        <div class="flex gap-2">
                                            <input type="number" x-model="cashAmount" min="1" placeholder="Amount"
                                                class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm w-28">
                                            <button @click="update('cash', 'increase')" :disabled="loading"
                                                class="px-3 py-1 bg-green-600 text-white rounded-md text-xs font-semibold hover:bg-green-700 transition">+ Add</button>
                                            <button @click="update('cash', 'decrease')" :disabled="loading"
                                                class="px-3 py-1 bg-red-600 text-white rounded-md text-xs font-semibold hover:bg-red-700 transition">- Remove</button>
                                        </div>
                                    </div>

                                    {{-- Gold --}}
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium mb-1">Gold: <span class="text-yellow-400 font-bold" x-text="user.gold"></span></label>
                                        <div class="flex gap-2">
                                            <input type="number" x-model="goldAmount" min="1" placeholder="Amount"
                                                class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm w-28">
                                            <button @click="update('gold', 'increase')" :disabled="loading"
                                                class="px-3 py-1 bg-green-600 text-white rounded-md text-xs font-semibold hover:bg-green-700 transition">+ Add</button>
                                            <button @click="update('gold', 'decrease')" :disabled="loading"
                                                class="px-3 py-1 bg-red-600 text-white rounded-md text-xs font-semibold hover:bg-red-700 transition">- Remove</button>
                                        </div>
                                    </div>

                                    {{-- Experience --}}
                                    <div>
                                        <label class="block text-sm font-medium mb-1">XP: <span class="text-blue-400 font-bold" x-text="user.xp"></span></label>
                                        <div class="flex gap-2">
                                            <input type="number" x-model="xpAmount" min="0" placeholder="XP amount"
                                                class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm w-28">
                                            <button @click="update('xp', 'set')" :disabled="loading"
                                                class="px-3 py-1 bg-blue-600 text-white rounded-md text-xs font-semibold hover:bg-blue-700 transition">Set XP</button>
                                            <button @click="update('xp', 'increase')" :disabled="loading"
                                                class="px-3 py-1 bg-green-600 text-white rounded-md text-xs font-semibold hover:bg-green-700 transition">+ Add</button>
                                            <button @click="update('xp', 'decrease')" :disabled="loading"
                                                class="px-3 py-1 bg-red-600 text-white rounded-md text-xs font-semibold hover:bg-red-700 transition">- Remove</button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                    </div>

                </div>
            </div>
        </div>
    </div>
</x-app-layout>
