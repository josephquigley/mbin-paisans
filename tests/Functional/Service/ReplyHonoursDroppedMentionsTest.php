<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Tests\WebTestCase;

/**
 * A reply addresses the accounts it actually names, plus the author of what
 * it is replying to.
 *
 * MentionManager::handleChain used to merge the subject's whole mention list
 * into every reply, unconditionally. The reply form prefills those mentions,
 * so a member who deleted one from the box had no way to drop that account:
 * the handle was gone from the visible text and the mention was put back on
 * save, which meant a Mention tag and a delivery to somebody the member had
 * explicitly removed.
 *
 * The author of the subject is still added whether or not they are named.
 * Prefilling the author is opt in (and off by default for entries), so
 * inheriting it is what keeps a reply reaching the person being replied to.
 */
class ReplyHonoursDroppedMentionsTest extends WebTestCase
{
    public function testAReplyKeepsAMentionItNames(): void
    {
        $post = $this->createPost('a post that addresses somebody', user: $this->getUserByUsername('post_author'));
        $post->mentions = ['@someone@remote.example'];
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $comment = $this->createPostComment('@someone@remote.example still talking to you', $post, $this->getUserByUsername('replier'));

        self::assertContains('@someone@remote.example', $comment->mentions ?? []);
    }

    public function testAReplyDropsAMentionItDoesNotName(): void
    {
        $post = $this->createPost('a post that addresses somebody', user: $this->getUserByUsername('post_author'));
        $post->mentions = ['@someone@remote.example'];
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $comment = $this->createPostComment('deliberately not addressing them', $post, $this->getUserByUsername('replier'));

        self::assertNotContains(
            '@someone@remote.example',
            $comment->mentions ?? [],
            'a mention the member deleted from the reply must not be added back'
        );
    }

    /**
     * The one inheritance that stays: whoever is being replied to.
     */
    public function testAReplyStillAddressesTheAuthorOfWhatItRepliesTo(): void
    {
        $post = $this->createPost('a post nobody named in the reply', user: $this->getUserByUsername('post_author'));

        $comment = $this->createPostComment('a reply naming nobody', $post, $this->getUserByUsername('replier'));

        self::assertContains('@post_author', $comment->mentions ?? []);
    }
}
