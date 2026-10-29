<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Exception\InvalidArgumentException;
use WP_Post;
use WP_Term;
use WP_User;

enum ObjectType: string
{
    case Post = 'post';
    case Term = 'term';
    case User = 'user';

    public function resolveId(WP_Post|WP_Term|WP_User|int $object): int
    {
        if (\is_int($object)) {
            return $this->positiveId($object);
        }

        return match ($this) {
            self::Post => $object instanceof WP_Post
                ? $this->positiveId((int) $object->ID)
                : throw new InvalidArgumentException('expected WP_Post for post meta, got ' . $object::class),
            self::Term => $object instanceof WP_Term
                ? $this->positiveId((int) $object->term_id)
                : throw new InvalidArgumentException('expected WP_Term for term meta, got ' . $object::class),
            self::User => $object instanceof WP_User
                ? $this->positiveId((int) $object->ID)
                : throw new InvalidArgumentException('expected WP_User for user meta, got ' . $object::class),
        };
    }

    public function metaType(): string
    {
        return $this->value;
    }

    private function positiveId(int $id): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('meta object ID must be a positive integer');
        }

        return $id;
    }
}
