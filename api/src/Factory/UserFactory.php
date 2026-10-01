<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Returns the plain entity, neither a proxy nor an auto-refreshing lazy object: tests
 * hand it to Doctrine relations and the JWT manager, and keep reading it across requests
 * the way they did with `new User()`.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return User::class;
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this->withoutAutorefresh();
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
        ];
    }
}
