<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * The password every test user gets. Hashed once per process rather than
     * per row — bcrypt is deliberately slow, and a suite that creates fifty
     * users pays for it fifty times otherwise.
     */
    public const string PASSWORD = 'password';

    protected $model = User::class;

    private static ?string $hashedPassword = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$hashedPassword ??= bcrypt(self::PASSWORD),
            'role' => UserRole::Customer,
            'customer_id' => Customer::factory(),

            // Set explicitly so a factory-built model carries the attribute.
            // `Model::shouldBeStrict()` turns reading an unhydrated column
            // into an exception, and a column omitted here is absent from the
            // insert and therefore from the returned instance.
            'last_login_at' => null,
        ];
    }

    /**
     * A staff user: no customer, sees everything.
     */
    public function staff(): self
    {
        return $this->state(fn (): array => [
            'role' => UserRole::Staff,
            'customer_id' => null,
        ]);
    }

    /**
     * A customer-role user bound to an existing customer.
     *
     * Named `belongingTo` rather than `for` because `Factory::for()` already
     * exists with a different signature — overriding it would be a silent
     * Liskov violation that PHP rejects outright.
     */
    public function belongingTo(Customer $customer): self
    {
        return $this->state(fn (): array => [
            'role' => UserRole::Customer,
            'customer_id' => $customer->id,
        ]);
    }
}
