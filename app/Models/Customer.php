<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiskBand;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string|null $document
 * @property int|null $risk_score
 * @property RiskBand|null $risk_band
 * @property CarbonImmutable|null $risk_scored_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 */
#[Fillable([
    'name',
    'email',
    'document',
])]
#[RouteKey('uuid')]
final class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The UUID is what we expose publicly; the auto-increment id never leaves
     * the database. Sequential public identifiers leak how many customers we
     * have and make enumeration trivial.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The one subscription that currently grants access, if any.
     *
     * @return HasMany<Subscription, $this>
     */
    public function activeSubscriptions(): HasMany
    {
        return $this->subscriptions()->grantingAccess();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'risk_score' => 'integer',
            'risk_band' => RiskBand::class,
            'risk_scored_at' => 'immutable_datetime',
        ];
    }
}
