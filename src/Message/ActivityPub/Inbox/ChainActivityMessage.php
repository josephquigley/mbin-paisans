<?php

declare(strict_types=1);

namespace App\Message\ActivityPub\Inbox;

use App\Message\Contracts\ActivityPubResolveInterface;

class ChainActivityMessage implements ActivityPubResolveInterface
{
    public function __construct(
        public array $chain,
        public ?array $parent = null,
        public ?array $announce = null,
        public ?array $like = null,
        public ?array $dislike = null,
        // The actor that delivered the activity this chain came from, and the kind of
        // activity it was. Both are read from the already signature-verified activity,
        // never from the object it points at.
        public ?string $deliveredBy = null,
        public ?string $deliveredKind = null,
    ) {
    }
}
