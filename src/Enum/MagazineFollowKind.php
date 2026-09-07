<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\Magazine;
use App\Entity\User;

/**
 * Which activity kinds a magazine follow carries into its magazine.
 *
 * A follow exists so a magazine receives what an actor publishes, and what
 * "publishes" means differs by actor: a person writes, a group announces, and an
 * instance actor may do either. So the kind is a default per actor type and a
 * moderator's choice after that, because no type check can tell a Person that is
 * functionally an instance from one that is not.
 */
enum MagazineFollowKind: string
{
    case Create = 'create';
    case Announce = 'announce';
    case Both = 'both';

    public function carries(string $activityType): bool
    {
        return match ($this) {
            self::Both => 'Create' === $activityType || 'Announce' === $activityType,
            self::Create => 'Create' === $activityType,
            self::Announce => 'Announce' === $activityType,
        };
    }

    public static function defaultFor(User|Magazine $actor): self
    {
        if ($actor instanceof Magazine) {
            // a Group's announces are the one-to-one equivalent of Mbin threads
            return self::Announce;
        }

        // Mbin stores an instance actor as Application or Service depending on the
        // remote software, and the reason for carrying both kinds (it may relay by
        // announcing or deliver directly) applies to either spelling.
        return match ($actor->type) {
            'Application', 'Service' => self::Both,
            default => self::Create,
        };
    }
}
