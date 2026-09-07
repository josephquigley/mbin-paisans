<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\WebTestCase;

/**
 * A reply to a comment prefills the comment's own mentions, so a member can
 * see who their reply will reach and drop anyone they do not want to include.
 * A reply to the post or entry itself prefilled only its author, and ignored
 * the mentions the post carries.
 *
 * That is an inconsistency rather than a policy: MentionManager::handleChain
 * merges the subject's mentions into the reply on save either way, so those
 * people are addressed whether or not the member was shown them. The form is
 * simply telling the member something different from what will happen.
 */
class PrefillSubjectMentionsTest extends WebTestCase
{
    /**
     * The prefill is opt in, so a user with it switched off would give an
     * empty box and an assertion that proved nothing.
     */
    private function reader(): \App\Entity\User
    {
        $reader = $this->getUserByUsername('reader');
        $reader->addMentionsPosts = true;
        $reader->addMentionsEntries = true;
        $this->entityManager->persist($reader);
        $this->entityManager->flush();

        return $reader;
    }

    public function testReplyingToAPostPrefillsItsMentions(): void
    {
        $author = $this->getUserByUsername('post_author');
        $post = $this->createPost('a post that addresses somebody', user: $author);
        $post->mentions = ['@someone@remote.example'];
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->client->loginUser($this->reader());
        $crawler = $this->client->request('GET', "/m/{$post->magazine->name}/p/{$post->getId()}/-/reply");

        self::assertResponseIsSuccessful();
        $prefill = $crawler->filter('textarea')->first()->text();
        self::assertStringContainsString('@post_author', $prefill);
        self::assertStringContainsString('@someone@remote.example', $prefill);
    }

    public function testReplyingToAnEntryPrefillsItsMentions(): void
    {
        $author = $this->getUserByUsername('entry_author');
        $entry = $this->getEntryByTitle('an entry that addresses somebody', user: $author);
        $entry->mentions = ['@someone@remote.example'];
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->client->loginUser($this->reader());
        $crawler = $this->client->request('GET', "/m/{$entry->magazine->name}/t/{$entry->getId()}/-");

        self::assertResponseIsSuccessful();
        $prefill = $crawler->filter('textarea')->first()->text();
        self::assertStringContainsString('@entry_author', $prefill);
        self::assertStringContainsString('@someone@remote.example', $prefill);
    }

    /**
     * One line, single spaces. The reply box puts the caret at the end of the
     * prefill, so a handle on a line of its own would leave the member typing
     * in the middle of the list.
     */
    public function testThePrefillIsASingleLineOfHandles(): void
    {
        $author = $this->getUserByUsername('post_author');
        $post = $this->createPost('a post that addresses two people', user: $author);
        $post->mentions = ['@someone@remote.example', '@another@remote.example'];
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->client->loginUser($this->reader());
        $crawler = $this->client->request('GET', "/m/{$post->magazine->name}/p/{$post->getId()}/-/reply");

        self::assertResponseIsSuccessful();
        $prefill = $crawler->filter('textarea')->first()->text();

        self::assertStringNotContainsString("\n", $prefill);
        self::assertSame(
            '@post_author@kbin.test @someone@remote.example @another@remote.example',
            trim($prefill)
        );
    }

    /**
     * The member composing the reply is never handed their own handle, which
     * is what the reply-to-a-comment branch already does.
     */
    public function testTheReadersOwnHandleIsNotPrefilled(): void
    {
        $reader = $this->reader();
        $author = $this->getUserByUsername('post_author');
        $post = $this->createPost('a post that addresses the reader', user: $author);
        $post->mentions = ['@'.$reader->username, '@someone@remote.example'];
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->client->loginUser($reader);
        $crawler = $this->client->request('GET', "/m/{$post->magazine->name}/p/{$post->getId()}/-/reply");

        self::assertResponseIsSuccessful();
        $prefill = $crawler->filter('textarea')->first()->text();
        self::assertStringNotContainsString('@'.$reader->username, $prefill);
        self::assertStringContainsString('@someone@remote.example', $prefill);
    }

    /**
     * A member replying to their own post gets the mentions but not their own
     * handle as the author, matching the reply-to-a-comment branch.
     */
    public function testAuthorReplyingToTheirOwnPostGetsOnlyTheMentions(): void
    {
        $reader = $this->reader();
        $post = $this->createPost('my own post', user: $reader);
        $post->mentions = ['@someone@remote.example'];
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->client->loginUser($reader);
        $crawler = $this->client->request('GET', "/m/{$post->magazine->name}/p/{$post->getId()}/-/reply");

        self::assertResponseIsSuccessful();
        $prefill = $crawler->filter('textarea')->first()->text();
        self::assertStringNotContainsString('@'.$reader->username, $prefill);
        self::assertStringContainsString('@someone@remote.example', $prefill);
    }
}
