<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandKit extends Model
{
    use HasUlids;

    protected $fillable = [
        'brand_audit_id',
        'generated_payload',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'generated_payload' => 'array',
        ];
    }

    public function brandAudit(): BelongsTo
    {
        return $this->belongsTo(BrandAudit::class);
    }
}
