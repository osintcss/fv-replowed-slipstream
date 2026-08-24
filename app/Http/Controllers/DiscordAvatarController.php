<?php

namespace App\Http\Controllers;

use App\Models\DiscordIdentity;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DiscordAvatarController extends Controller
{
    public function show(string $uid): Response
    {
        $avatarUrl = DiscordIdentity::query()
            ->whereHas('user', fn ($query) => $query->where('uid', $uid))
            ->value('avatar_url');
        if (!is_string($avatarUrl) || !str_starts_with($avatarUrl, 'https://cdn.discordapp.com/')) {
            return $this->placeholder();
        }

        $cachePath = 'discord-avatar-cache/'.sha1($avatarUrl.'-50x50-jpeg');
        $disk = Storage::disk('local');

        if (!$disk->exists($cachePath)) {
            try {
                $remote = Http::timeout(5)->get($avatarUrl);
                $contentType = strtolower((string) $remote->header('Content-Type'));

                if (!$remote->successful() || !str_starts_with($contentType, 'image/') || strlen($remote->body()) > 2_000_000) {
                    return $this->placeholder();
                }

                $thumbnail = (new ImageManager(new GdDriver()))
                    ->read($remote->body())
                    ->cover(50, 50)
                    ->encode(new JpegEncoder(quality: 90));

                $disk->put($cachePath, (string) $thumbnail);
            } catch (\Throwable) {
                return $this->placeholder();
            }
        }

        return response($disk->get($cachePath), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function placeholder(): Response
    {
        return response(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#e5e7eb"/><circle cx="50" cy="38" r="18" fill="#9ca3af"/><path d="M18 92c4-22 20-32 32-32s28 10 32 32" fill="#9ca3af"/></svg>',
            200,
            ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600'],
        );
    }
}
