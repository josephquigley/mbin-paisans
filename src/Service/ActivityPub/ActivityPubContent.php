<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\DTO\EntryCommentDto;
use App\DTO\EntryDto;
use App\DTO\PostCommentDto;
use App\DTO\PostDto;
use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Contracts\VisibilityInterface;
use App\Entity\User;
use App\Utils\JsonldUtils;

abstract class ActivityPubContent
{
    /**
     * Decide an object's visibility from its addressing, which is the only statement
     * the sender makes about who it is for.
     *
     * An object that names the Public collection in `to` or `cc` is visible. One that
     * names only the actor's followers collection is followers-only, and is stored
     * private: WriteFreely addresses an unlisted blog's posts exactly that way, and
     * Mastodon's "followers only" is the same shape.
     *
     * A private object is shown to a logged in user who follows the author, and to
     * nobody else. **A magazine that follows the author does not widen that**, even
     * though such an object is routed into the magazine like any other. Routing decides
     * which magazine an object belongs to; it says nothing about who may see it.
     * Founder decision, 2026-09-06: re-delivering followers-only content to everyone who
     * can read a magazine is out of scope, because it would show content to people the
     * sender did not address. `tests/Functional/Service/DeliveryScopedRoutingTest.php`
     * pins it.
     *
     * @throws \LogicException if the object is addressed to neither, which today means
     *                         a direct message, and those are not implemented
     */
    protected function getVisibility(array $object, User $actor): string
    {
        $toAndCC = array_merge(JsonldUtils::getArrayValue($object, 'to'), JsonldUtils::getArrayValue($object, 'cc'));
        if (!$this->containsPublicTarget($toAndCC)) {
            if (!\in_array($actor->apFollowersUrl, $toAndCC)) {
                throw new \LogicException('PM: not implemented.');
            }

            return VisibilityInterface::VISIBILITY_PRIVATE;
        }

        return VisibilityInterface::VISIBILITY_VISIBLE;
    }

    protected function containsPublicTarget(array $toAndCC): bool
    {
        return \in_array(ActivityPubActivityInterface::PUBLIC_URL, $toAndCC)
            || \in_array(ActivityPubActivityInterface::PUBLIC_URL_NS, $toAndCC)
            || \in_array(ActivityPubActivityInterface::PUBLIC_URL_SHORT, $toAndCC);
    }

    protected function handleDate(PostDto|PostCommentDto|EntryCommentDto|EntryDto $dto, string $date): void
    {
        $dto->createdAt = new \DateTimeImmutable($date);
        $dto->lastActive = new \DateTime($date);
    }

    protected function handleSensitiveMedia(PostDto|PostCommentDto|EntryCommentDto|EntryDto $dto, string|bool $sensitive): void
    {
        if (true === filter_var($sensitive, FILTER_VALIDATE_BOOLEAN)) {
            $dto->isAdult = true;
        }
    }
}
