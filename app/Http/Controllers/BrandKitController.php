<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateBrandKit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BrandKitController extends Controller
{
    public function index()
    {
        return view('home');
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'business_name'     => 'required|string|max:255',
            'location'          => 'required|string|max:255',
            'service_type'      => 'required|string|max:255',
            'target_customer'   => 'required|string|max:500',
            'differentiator'    => 'required|string|max:500',
            'brand_personality' => 'required|string|max:500',
            'price_segment'     => 'required|string|max:100',
            'competitors'       => 'nullable|string|max:500',
        ]);

        $token = Str::uuid()->toString();

        Cache::put("brandkit:{$token}", ['status' => 'pending'], now()->addHours(2));

        GenerateBrandKit::dispatch($token, $validated);

        // On Windows (local/Herd): spawn a detached worker automatically.
        // On Linux hosting: leave it to cron (* * * * * php artisan queue:work --once).
        if (PHP_OS_FAMILY === 'Windows') {
            $phpBin = 'C:\\Users\\Axioo Pongo\\.config\\herd\\bin\\php84\\php.exe';
            $artisan = base_path('artisan');
            $cmd = 'cmd /C start /B "" "' . $phpBin . '" "' . $artisan . '" queue:work --once --timeout=180 --tries=1 >NUL 2>NUL';
            pclose(popen($cmd, 'r'));
        }

        return redirect("/loading?token={$token}");
    }

    public function loading(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return redirect('/');
        }

        return view('loading', compact('token'));
    }

    public function status(Request $request)
    {
        $token = $request->query('token');
        $data  = Cache::get("brandkit:{$token}");

        if (!$data) {
            return response()->json(['status' => 'expired']);
        }

        if ($data['status'] === 'done') {
            // Move result to session so /results can render it
            session([
                'brand_kit'  => $data['brand_kit'],
                'brand_data' => $data['brand_data'],
            ]);
            Cache::forget("brandkit:{$token}");
        }

        return response()->json([
            'status'  => $data['status'],
            'message' => $data['message'] ?? null,
        ]);
    }

    public function results()
    {
        $brandKit  = session('brand_kit');
        $brandData = session('brand_data');

        if (!$brandKit || !$brandData) {
            return redirect('/');
        }

        return view('results', compact('brandKit', 'brandData'));
    }

    public function download()
    {
        $brandKit  = session('brand_kit');
        $brandData = session('brand_data');

        if (!$brandKit || !$brandData) {
            return redirect('/');
        }

        $pdf      = Pdf::loadView('pdf.brand-kit', compact('brandKit', 'brandData'))
                       ->setPaper('a4', 'landscape');
        $filename = 'brand-kit-' . Str::slug($brandData['business_name']) . '.pdf';

        return $pdf->download($filename);
    }
}
