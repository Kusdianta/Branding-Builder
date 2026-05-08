<?php

// Tambahkan di bagian atas KolController.php:
// use App\Exports\KolExport;
// use Maatwebsite\Excel\Facades\Excel;

// Tambahkan method ini di dalam class KolController:

    /**
     * POST /kol/export
     * Export KOL list ke Excel
     */
    public function export(Request $request)
    {
        $request->validate([
            'kols'  => 'required|array',
            'kota'  => 'nullable|string',
            'niche' => 'nullable|string',
        ]);

        $kols  = $request->kols;
        $kota  = $request->kota ?? 'indonesia';
        $niche = $request->niche ?? 'lifestyle';

        $filename = 'kol-report-' . $kota . '-' . $niche . '-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(
            new KolExport($kols, $kota, $niche),
            $filename
        );
    }
