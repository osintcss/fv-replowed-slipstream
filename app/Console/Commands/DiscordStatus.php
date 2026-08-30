<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DiscordStatus extends Command
{
    protected $signature = 'discord:status {status? : ONLINE, MAINTENANCE, or DOWN} {--set-message-id= : Set the message ID to edit}';
    protected $description = 'Update Discord server status message';

    private string $messageIdFile = 'discord_status_message_id.txt';

    public function handle()
    {
        if (!env('DISCORD_STATUS_ENABLED', false)) {
            $this->warn("Discord status is disabled. Set DISCORD_STATUS_ENABLED=true in .env");
            return 0;
        }

        $webhookUrl = env('DISCORD_STATUS_WEBHOOK');
        if (!$webhookUrl) {
            $this->error("DISCORD_STATUS_WEBHOOK not set in .env");
            return 1;
        }

        if ($setMessageId = $this->option('set-message-id')) {
            $this->storeMessageId($setMessageId);
            $this->info("Stored message ID: {$setMessageId}");
            return 0;
        }

        $status = strtoupper($this->argument('status') ?? $this->detectStatus());

        if (!in_array($status, ['ONLINE', 'MAINTENANCE', 'DOWN'])) {
            $this->error("Invalid status. Use: ONLINE, MAINTENANCE, or DOWN");
            return 1;
        }

        $messageId = $this->getStoredMessageId();
        $healthCheck = $this->checkHealth();
        $serverHealth = $this->getServerHealth();
        $embed = $this->buildEmbed($status, $healthCheck, $serverHealth);

        if ($messageId) {
            $success = $this->editMessage($webhookUrl, $messageId, $embed);
            if ($success) {
                $this->info("Updated Discord status to: {$status}");
                return 0;
            }
            $this->warn("Failed to edit message, will try sending new one...");
        }

        $newMessageId = $this->sendMessage($webhookUrl, $embed);
        if ($newMessageId) {
            $this->storeMessageId($newMessageId);
            $this->info("Sent new Discord status message: {$status}");
            return 0;
        }

        $this->error("Failed to send Discord status");
        return 1;
    }

    private function detectStatus(): string
    {
        if (config('app.maintenance_mode')) {
            return 'MAINTENANCE';
        }

        try {
            $response = Http::timeout(5)->get('https://play.farmplay.win/up');
            if (!$response->successful()) {
                return 'DOWN';
            }

            $data = $response->json();
            $status = $data['status'] ?? 'error';

            if ($status === 'ok') {
                return 'ONLINE';
            } elseif ($status === 'degraded') {
                return 'MAINTENANCE';
            }

            return 'DOWN';
        } catch (\Exception $e) {
            return 'DOWN';
        }
    }

    private function checkHealth(): array
    {
        try {
            $start = microtime(true);
            $response = Http::timeout(5)->get('https://play.farmplay.win/up');
            $latency = round((microtime(true) - $start) * 1000);

            $dbPing = null;
            $cachePing = null;
            if ($response->successful()) {
                $data = $response->json();
                $dbPing = $data['checks']['database']['ping_ms'] ?? null;
                $cachePing = $data['checks']['cache']['ping_ms'] ?? null;
            }

            return [
                'code' => $response->status(),
                'text' => $response->status() . ' ' . ($response->successful() ? 'OK' : 'Error'),
                'latency' => $latency,
                'db_ping' => $dbPing,
                'cache_ping' => $cachePing,
            ];
        } catch (\Exception $e) {
            return [
                'code' => 0,
                'text' => 'Connection failed',
                'latency' => 0,
                'db_ping' => null,
                'cache_ping' => null,
            ];
        }
    }

    private function getServerHealth(): array
    {
        $uptime = 'N/A';
        $memory = 'N/A';
        $cpu = 'N/A';

        try {
            $uptimeRaw = @file_get_contents('/proc/uptime');
            if ($uptimeRaw) {
                $seconds = (int) explode(' ', $uptimeRaw)[0];
                $days = floor($seconds / 86400);
                $hours = floor(($seconds % 86400) / 3600);
                $mins = floor(($seconds % 3600) / 60);
                $uptime = "{$days}d {$hours}h {$mins}m";
            }
        } catch (\Exception $e) {}

        try {
            $memInfo = @file_get_contents('/proc/meminfo');
            if ($memInfo) {
                preg_match('/MemTotal:\s+(\d+)/', $memInfo, $total);
                preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $available);
                if (!empty($total[1]) && !empty($available[1])) {
                    $used = ($total[1] - $available[1]) / 1024;
                    $totalMb = $total[1] / 1024;
                    $percent = round(($used / $totalMb) * 100);
                    $memory = round($used) . "MB / " . round($totalMb) . "MB ({$percent}%)";
                }
            }
        } catch (\Exception $e) {}

        try {
            $load = sys_getloadavg();
            if ($load) {
                $cpu = round($load[0], 2) . " / " . round($load[1], 2) . " / " . round($load[2], 2);
            }
        } catch (\Exception $e) {}

        return [
            'uptime' => $uptime,
            'memory' => $memory,
            'cpu' => $cpu,
        ];
    }

    private function getLastBackupInfo(): string
    {
        try {
            if (Storage::exists('last_backup.json')) {
                $data = json_decode(Storage::get('last_backup.json'), true);
                if ($data && isset($data['timestamp'])) {
                    return "<t:{$data['timestamp']}:R>";
                }
            }
        } catch (\Exception $e) {}

        return 'No backup found';
    }

    private function buildEmbed(string $status, array $healthCheck, array $serverHealth): array
    {
        $colors = [
            'ONLINE' => 0x22c55e,
            'MAINTENANCE' => 0xf59e0b,
            'DOWN' => 0xef4444,
        ];

        $emojis = [
            'ONLINE' => '🟢',
            'MAINTENANCE' => '🟡',
            'DOWN' => '🔴',
        ];

        $unixTimestamp = now()->timestamp;
        $appUrl = config('app.url');
        $playerCount = User::count();
        $latency = $healthCheck['latency'] ?? 0;
        $dbPing = $healthCheck['db_ping'] ?? null;
        $cachePing = $healthCheck['cache_ping'] ?? null;

        $location = env('DISCORD_STATUS_SERVER_LOCATION', 'Unknown');
        $downloadsUrl = env('DISCORD_STATUS_DOWNLOADS_URL') ?: $appUrl;

        return [
            'embeds' => [[
                'title' => "{$emojis[$status]} FarmVille Host Status: {$status}",
                'description' => "**Join:** {$appUrl}\n**Downloads:** {$downloadsUrl}",
                'color' => $colors[$status],
                'fields' => [
                    [
                        'name' => '🕐 Last Check',
                        'value' => "<t:{$unixTimestamp}:R>",
                        'inline' => true,
                    ],
                    [
                        'name' => '📍 Location',
                        'value' => $location,
                        'inline' => true,
                    ],
                    [
                        'name' => '📶 Response',
                        'value' => "{$healthCheck['text']} ({$latency}ms)" . ($dbPing !== null ? " | DB: {$dbPing}ms" : ''),
                        'inline' => true,
                    ],
                    [
                        'name' => '👥 Players',
                        'value' => (string) $playerCount,
                        'inline' => true,
                    ],
                    [
                        'name' => '⏱️ Uptime',
                        'value' => $serverHealth['uptime'],
                        'inline' => true,
                    ],
                    [
                        'name' => '💾 Memory',
                        'value' => $serverHealth['memory'],
                        'inline' => true,
                    ],
                    [
                        'name' => '🖥️ CPU Load',
                        'value' => $serverHealth['cpu'],
                        'inline' => true,
                    ],
                    [
                        'name' => '💿 Last DB Backup',
                        'value' => $this->getLastBackupInfo(),
                        'inline' => true,
                    ],
                ],
                'footer' => [
                    'text' => 'Auto-updated server status',
                ],
            ]],
        ];
    }

    private function getStoredMessageId(): ?string
    {
        if (Storage::exists($this->messageIdFile)) {
            return trim(Storage::get($this->messageIdFile));
        }
        return null;
    }

    private function storeMessageId(string $messageId): void
    {
        Storage::put($this->messageIdFile, $messageId);
    }

    private function editMessage(string $webhookUrl, string $messageId, array $payload): bool
    {
        try {
            $response = Http::patch("{$webhookUrl}/messages/{$messageId}", $payload);
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function sendMessage(string $webhookUrl, array $payload): ?string
    {
        try {
            $response = Http::post($webhookUrl . '?wait=true', $payload);
            if ($response->successful()) {
                return $response->json('id');
            }
        } catch (\Exception $e) {
        }
        return null;
    }
}
