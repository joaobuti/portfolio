<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * An account that authenticates against the API.
 *
 * Deliberately not the same thing as a {@see Customer}: see the migration for
 * why. What this class owns is one question — **whose data may I act on?** —
 * answered by {@see canActOnCustomer()}. Policies ask it; nothing else decides.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string $password
 * @property UserRole $role
 * @property int|null $customer_id
 * @property int|null $demo_workspace_id
 * @property Carbon|null $last_login_at
 * @property-read Customer|null $customer
 */
#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'customer_id',
])]
#[Hidden(['password', 'remember_token'])]
#[RouteKey('uuid')]
final class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = ['demo_workspace_id' => null, 'remember_token' => null];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Whether this user may act on the given customer's data.
     *
     * The one authorisation primitive in the application. Every policy reduces
     * to this call, which means "who can see what" has a single definition and
     * a single place to get wrong — rather than a comparison repeated across
     * four policies that drift apart the first time a role is added.
     */
    public function canActOnCustomer(Customer $customer): bool
    {
        return $this->canActOnCustomerId($customer->id);
    }

    /**
     * The same question against a foreign key.
     *
     * Exists so policies can authorise a subscription without touching its
     * `customer` relation. Reading the relation would lazy load — which this
     * application turns into an exception via `Model::shouldBeStrict()` — and
     * eager loading it everywhere just to compare an integer is a query per
     * authorisation for no information.
     */
    public function canActOnCustomerId(?int $customerId): bool
    {
        if ($customerId === null || ! Customer::query()->whereKey($customerId)
            ->where('demo_workspace_id', $this->demo_workspace_id)->exists()) {
            return false;
        }
        if ($this->role->accessesAnyCustomer()) {
            return true;
        }

        // Both nulls are guarded deliberately: a customer-role user with no
        // customer must match nobody, and `null === null` would otherwise
        // authorise them against any unsaved record.
        return $this->customer_id !== null
            && $this->customer_id === $customerId;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'password' => 'hashed',
            'last_login_at' => 'immutable_datetime',
            'demo_workspace_id' => 'integer',
        ];
    }
}
