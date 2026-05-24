<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'google_id',
    'hub_user_id',
    'email',
    'name',
    'avatar_url',
    'credits_balance',
    'credits_lifetime_earned',
    'credits_lifetime_spent',
    'last_login_at',
])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_login_at'           => 'datetime',
            'credits_balance'         => 'integer',
            'credits_lifetime_earned' => 'integer',
            'credits_lifetime_spent'  => 'integer',
        ];
    }

    /** @return HasMany<BrandAudit> */
    public function brandAudits(): HasMany
    {
        return $this->hasMany(BrandAudit::class);
    }
}
