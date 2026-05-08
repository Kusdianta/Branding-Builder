<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Exports\KolExport;
use Maatwebsite\Excel\Facades\Excel;

class KolController extends Controller
{
    private ?string $apifyToken = null;
    private string $apifyBase = 'https://api.apify.com/v2';

    public function __construct()
    {
        $this->apifyToken = config('services.apify.token');
    }

    public function index()
    {
        return view('kol');
    }

    /**
     * POST /kol/search — start Apify run, return runId
     */
    public function search(Request $request)
    {
        $request->validate([
            'kota'          => 'required|string|max:100',
            'niche'         => 'nullable|string|max:100',
            'min_followers' => 'nullable|integer',
            'max_followers' => 'nullable|integer',
        ]);

        if (!$this->apifyToken) {
            return response()->json(['error' => 'APIFY_API_TOKEN belum diset di .env'], 500);
        }

        $kota  = strtolower(str_replace([' ', '-'], '', $request->kota));
        $niche = strtolower(str_replace([' ', '-'], '', $request->niche ?? 'lifestyle'));

        $hashtags = array_values(array_unique(array_filter([
            $niche . $kota,
            $kota . $niche,
            $kota . 'food',
            'food' . $kota,
            $kota . 'kuliner',
            'kuliner' . $kota,
            $kota . 'lifestyle',
            'lifestyle' . $kota,
            $kota . 'laundry',
            'laundry' . $kota,
            'umkm' . $kota,
            $kota . 'viral',
            $kota . 'hits',
            $kota,
        ])));

        $response = Http::withToken($this->apifyToken)
            ->timeout(30)
            ->post("{$this->apifyBase}/acts/apify~instagram-hashtag-scraper/runs", [
                'hashtags'     => $hashtags,
                'resultsLimit' => 100,
                'expandOwners' => true,
            ]);

        $data = $response->json();

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']['message'] ?? 'Apify error'], 500);
        }

        $runId = $data['data']['id'] ?? null;

        if (!$runId) {
            return response()->json(['error' => 'Gagal start Apify run'], 500);
        }

        return response()->json([
            'runId'    => $runId,
            'hashtags' => $hashtags,
        ]);
    }

    /**
     * GET /kol/status?runId=xxx
     */
    public function status(Request $request)
    {
        $request->validate(['runId' => 'required|string']);

        $response = Http::withToken($this->apifyToken)
            ->timeout(15)
            ->get("{$this->apifyBase}/actor-runs/{$request->runId}");

        $data   = $response->json();
        $status = $data['data']['status'] ?? 'UNKNOWN';

        return response()->json(['status' => $status]);
    }

    /**
     * GET /kol/results?runId=xxx&minFollowers=xxx&maxFollowers=xxx
     */
    public function results(Request $request)
    {
        $request->validate(['runId' => 'required|string']);

        $minFollowers = (int) ($request->minFollowers ?? 0);
        $maxFollowers = (int) ($request->maxFollowers ?? 99999999);

        $response = Http::withToken($this->apifyToken)
            ->timeout(30)
            ->get("{$this->apifyBase}/actor-runs/{$request->runId}/dataset/items", [
                'format' => 'json',
                'limit'  => 500,
            ]);

        $items = $response->json();

        if (!empty($items)) {
            \Log::info('Apify item sample', ['item' => $items[0]]);
        }




        
        $profileMap = [];

        foreach ($items as $item) {
            $username = $item['ownerUsername'] ?? null;
            if (!$username) continue;

            if (!isset($profileMap[$username])) {
                $profileMap[$username] = [
                    'username'      => $username,
                    'fullName'      => $item['ownerFullName'] ?? $username,
                    'profilePic'    => $item['ownerProfilePicUrl'] ?? '',
                    'followers'     => $item['ownerFollowersCount'] ?? 0,
                    'following'     => $item['ownerFollowingCount'] ?? 0,
                    'posts'         => $item['ownerPostsCount'] ?? 0,
                    'bio'           => $item['ownerBiography'] ?? '',
                    'externalUrl'   => $item['ownerExternalUrl'] ?? '',
                    'isVerified'    => $item['ownerIsVerified'] ?? false,
                    'totalLikes'    => 0,
                    'totalComments' => 0,
                    'postCount'     => 0,
                    'recentCaption' => $item['caption'] ?? '',
                ];
            }

            $profileMap[$username]['totalLikes']    += (int) ($item['likesCount'] ?? 0);
            $profileMap[$username]['totalComments'] += (int) ($item['commentsCount'] ?? 0);
            $profileMap[$username]['postCount']++;
        }

        // Keywords lokasi untuk filter relevansi
        $kotaRaw      = strtolower(trim($request->kota ?? ''));
        $lokasiFilter = array_unique(array_filter([
            $kotaRaw,
            'indonesia', 'indo', '.id',
            'jawa', 'java', 'jatim', 'jateng', 'jabar',
        ]));

        $kolList = [];

        foreach ($profileMap as $username => $p) {
            $followers = $p['followers'];
            $postCount = max($p['postCount'], 1);

            $avgLikes    = round($p['totalLikes'] / $postCount);
            $avgComments = round($p['totalComments'] / $postCount);
            $engagement  = $followers > 0
                ? round((($avgLikes + $avgComments) / $followers) * 100, 2)
                : 0;

            if ($followers > 0 && ($followers < $minFollowers || $followers > $maxFollowers)) {
                continue;
            }

            // Filter lokasi: skip kalau bio/caption tidak ada kata kunci Indonesia/kota
            if ($followers > 0 && !empty($lokasiFilter)) {
                $haystack = strtolower($p['bio'] . ' ' . $p['recentCaption'] . ' ' . $p['fullName'] . ' ' . $username);
                $matched  = false;
                foreach ($lokasiFilter as $keyword) {
                    if ($keyword && str_contains($haystack, $keyword)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) continue;
            }

            $kolList[] = [
                'username'    => $username,
                'fullName'    => $p['fullName'],
                'bio'         => $p['bio'] ?: $p['recentCaption'],
                'followers'   => $followers,
                'following'   => $p['following'],
                'posts'       => $p['posts'],
                'avgLikes'    => $avgLikes,
                'avgComments' => $avgComments,
                'engagement'  => $engagement,
                'profilePic'  => $p['profilePic'],
                'externalUrl' => $p['externalUrl'],
                'isVerified'  => $p['isVerified'],
                'igUrl'       => "https://instagram.com/{$username}",
                'tier'        => $this->getTier($followers),
                'postCount'   => $p['postCount'],
            ];
        }

        usort($kolList, fn($a, $b) => $b['postCount'] <=> $a['postCount']);

        return response()->json([
            'total' => count($kolList),
            'data'  => array_values($kolList),
        ]);
    }

    /**
     * POST /kol/export — export ke Excel
     */
    public function export(Request $request)
    {
        $request->validate([
            'kols'  => 'required|array',
            'kota'  => 'nullable|string',
            'niche' => 'nullable|string',
        ]);

        $kols     = $request->kols;
        $kota     = $request->kota ?? 'indonesia';
        $niche    = $request->niche ?? 'lifestyle';
        $filename = 'kol-report-' . $kota . '-' . $niche . '-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(
            new KolExport($kols, $kota, $niche),
            $filename
        );
    }

    private function getTier(int $followers): string
    {
        if ($followers >= 100000) return 'Macro';
        if ($followers >= 10000)  return 'Mid';
        if ($followers >= 1000)   return 'Micro';
        if ($followers > 0)       return 'Nano';
        return 'Unknown';
    }
}
