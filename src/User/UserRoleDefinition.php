<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Exception\EntityDefinitionException;

final class UserRoleDefinition
{
    private string $label = '';

    /** @var list<string> */
    private array $capabilities = [];

    private function __construct(
        private readonly string $role,
        private readonly bool $builtin,
    ) {}

    public static function create(string $role): self
    {
        self::assertRole($role);

        return new self($role, false);
    }

    public static function builtin(string $role): self
    {
        self::assertRole($role);

        return new self($role, true);
    }

    public function label(string $label): self
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("UserRoleDefinition::label() is not allowed for builtin role '{$this->role}'");
        }

        $this->label = $label;

        return $this;
    }

    public function capabilities(string ...$capabilities): self
    {
        $this->capabilities = array_values(array_unique([...$this->capabilities, ...$capabilities]));

        return $this;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isBuiltin(): bool
    {
        return $this->builtin;
    }

    public function roleLabel(): string
    {
        if ($this->label !== '') {
            return $this->label;
        }

        return $this->role;
    }

    /** @return list<string> */
    public function capabilityList(): array
    {
        return $this->capabilities;
    }

    private static function assertRole(string $role): void
    {
        if (preg_match('/^[a-z0-9_\-]+$/', $role) !== 1) {
            throw new EntityDefinitionException("invalid user role: {$role}");
        }
    }
}
