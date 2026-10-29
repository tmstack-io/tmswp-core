<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

final readonly class SubmitTokenResult
{
    public function __construct(
        public TokenStatus $status,
        public string $jti = '',
        public int $issuedAt = 0,
    ) {}
}
