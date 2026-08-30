<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class GameController extends Controller
{
    public function index()
    {
        $neighborController = new NeighborController();
        $neighborsData = $neighborController->getNeighborsData();

        $user = Auth::user()->load('userMeta');

        return view('game', [
            'neighbors' => $neighborsData['neighbors'],
            'neighborIds' => $neighborsData['neighborIds'],
            'neighborsBase64' => $neighborsData['neighborsBase64'],
            'user' => $user,
            'autoAcceptNeighborRequests' => NeighborController::autoAcceptNeighborRequests($user->uid),
            'fotdImages' => $this->getFotdImages()
        ]);
    }

    public function play()
    {
        $neighborController = new NeighborController();
        $neighborsData = $neighborController->getNeighborsData();

        $user = Auth::user()->load('userMeta');

        return view('game', [
            'neighbors' => $neighborsData['neighbors'],
            'neighborIds' => $neighborsData['neighborIds'],
            'neighborsBase64' => $neighborsData['neighborsBase64'],
            'user' => $user,
            'autoAcceptNeighborRequests' => NeighborController::autoAcceptNeighborRequests($user->uid),
            'isLauncher' => true,
            'fotdImages' => $this->getFotdImages()
        ]);
    }

    private function getFotdImages(int $count = 5): string
    {
        $basePath = public_path('farmville/assets/hashed/assets/fotd');
        $baseUrl = config('app.url') . '/farmville/assets/hashed/assets/fotd';
        $images = [];

        foreach (['Current', 'Defaults'] as $folder) {
            $pattern = "{$basePath}/{$folder}/*.jpg";
            foreach (glob($pattern) as $file) {
                $images[] = "{$baseUrl}/{$folder}/" . basename($file);
            }
        }

        if (empty($images)) {
            return '';
        }

        shuffle($images);

        return implode(';', \array_slice($images, 0, min($count, \count($images))));
    }
}
