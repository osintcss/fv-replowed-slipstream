<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\AssetsController;
use App\Http\Controllers\NeighborController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DailyGiftController;
use App\Http\Controllers\WorldShopController;
use App\Http\Controllers\ChatController;
use Illuminate\Http\Request;

// Flash checks this policy before a loaded SWF exposes scriptable data to the
// main game movie. Allow only the host that served the policy, rather than a
// broad wildcard policy.
Route::get('/crossdomain.xml', function (Request $request) {
    $host = $request->getHost();

    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        abort(400);
    }

    $policy = '<?xml version="1.0"?>' . "\n"
        . '<cross-domain-policy>' . "\n"
        . '  <allow-access-from domain="' . $host . '" secure="false" />' . "\n"
        . '</cross-domain-policy>' . "\n";

    return response($policy, 200)
        ->header('Content-Type', 'text/x-cross-domain-policy')
        ->header('Cache-Control', 'public, max-age=3600');
});

Route::get('/up', function () {
    $health = [
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'checks' => [],
    ];

    $dbStart = microtime(true);
    try {
        DB::select('SELECT 1');
        $health['checks']['database'] = [
            'status' => 'ok',
            'ping_ms' => round((microtime(true) - $dbStart) * 1000, 2),
        ];
    } catch (\Exception $e) {
        $health['status'] = 'degraded';
        $health['checks']['database'] = ['status' => 'error', 'message' => 'Connection failed'];
    }

    $cacheStart = microtime(true);
    try {
        Cache::put('health_check', true, 10);
        $cacheWorks = Cache::get('health_check') === true;
        $health['checks']['cache'] = [
            'status' => $cacheWorks ? 'ok' : 'error',
            'ping_ms' => round((microtime(true) - $cacheStart) * 1000, 2),
        ];
    } catch (\Exception $e) {
        $health['checks']['cache'] = ['status' => 'error'];
    }

    try {
        if (Storage::exists('last_backup.json')) {
            $backup = json_decode(Storage::get('last_backup.json'), true);
            $health['checks']['last_backup'] = [
                'status' => 'ok',
                'timestamp' => $backup['timestamp'] ?? null,
                'age_hours' => isset($backup['timestamp']) ? round((time() - $backup['timestamp']) / 3600, 1) : null,
            ];
        } else {
            $health['checks']['last_backup'] = ['status' => 'none'];
        }
    } catch (\Exception $e) {
        $health['checks']['last_backup'] = ['status' => 'error'];
    }

    return response()->json($health);
});

Route::get('/', function () {
    return view('welcome');
})->middleware('maintenance');

// Launcher routes
Route::get('/app', function () {
    if (auth()->check()) {
        return redirect('/play');
    }
    return view('launcher.home');
})->name('app')->middleware('maintenance');

Route::get('/play', [GameController::class, 'play'])->middleware(['auth', 'verified'])->name('play');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/game', [GameController::class, 'index'])->middleware(['auth', 'verified'])->name('game');

Route::post('/download-file', [AssetsController::class, 'downloadAssets'])->name('download.file');
Route::get('/download-progress', [AssetsController::class, 'getProgress'])->name('download.progress');
Route::post('/extract-file', [AssetsController::class, 'extractAssets'])->name('extract.file');
Route::get('/extract-progress', [AssetsController::class, 'extractProgress'])->name('extract.progress');

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('/admin', [AdminController::class, 'index'])->name('admin');
    Route::post('/admin/lookup', [AdminController::class, 'lookupUser'])->name('admin.lookup');
    Route::post('/admin/update-currency', [AdminController::class, 'updateCurrency'])->name('admin.update-currency');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings');
    Route::post('/profile/picture', [ProfileController::class, 'uploadProfilePicture'])
        ->name('profile.picture.upload')
        ->middleware('throttle:5,1'); // 5 uploads per minute
    Route::delete('/profile/picture', [ProfileController::class, 'deleteProfilePicture'])->name('profile.picture.delete');

    Route::get('/neighbors/data', [NeighborController::class, 'getNeighborsData'])->name('neighbors.data');
    Route::get('/neighbors/potential', [NeighborController::class, 'getPotentialNeighbors'])->name('neighbors.potential');
    Route::get('/neighbors/pending', [NeighborController::class, 'getPendingRequests'])->name('neighbors.pending');
    Route::post('/neighbors/add', [NeighborController::class, 'addNeighbor'])->name('neighbors.add');
    Route::post('/neighbors/remove', [NeighborController::class, 'removeNeighbor'])->name('neighbors.remove');
    Route::post('/neighbors/accept', [NeighborController::class, 'acceptNeighbor'])->name('neighbors.accept');
    Route::post('/neighbors/reject', [NeighborController::class, 'rejectNeighbor'])->name('neighbors.reject');
    Route::post('/neighbors/send-request', [NeighborController::class, 'sendNeighborRequest'])->name('neighbors.send-request');

    // Daily Gift routes
    Route::get('/daily-gift/status', [DailyGiftController::class, 'checkStatus'])->name('daily-gift.status');
    Route::post('/daily-gift/claim', [DailyGiftController::class, 'claim'])->name('daily-gift.claim');

    // World Shop routes
    Route::get('/api/world-shop/status', [WorldShopController::class, 'status'])->name('world-shop.status');
    Route::post('/api/world-shop/purchase', [WorldShopController::class, 'purchase'])->name('world-shop.purchase');

    // Chat routes
    Route::get('/chat/messages', [ChatController::class, 'messages'])->name('chat.messages');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
    Route::get('/chat/unread-count', [ChatController::class, 'unreadCount'])->name('chat.unread-count');
    Route::post('/chat/mark-read', [ChatController::class, 'markRead'])->name('chat.mark-read');
});

require __DIR__.'/auth.php';
