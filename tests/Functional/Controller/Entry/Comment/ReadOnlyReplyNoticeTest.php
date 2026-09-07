<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Entry\Comment;

use App\Tests\WebTestCase;

class ReadOnlyReplyNoticeTest extends WebTestCase
{
    private const string HOST = 'readonly.example.com';
    private const string HANDLE = '@remote@readonly.example.com';

    private function markHostReadOnly(): void
    {
        $settings = $this->settingsManager->getDto();
        $settings->MBIN_USE_FEDERATION_ALLOW_LIST = true;
        $this->settingsManager->save($settings);

        $instance = $this->instanceRepository->getOrCreateInstance(self::HOST);
        $this->instanceManager->allowInstanceFederation($instance);
        $this->instanceManager->markInstanceReadOnly($instance);
    }

    /**
     * The prefill is opt in, and it is what these tests are about: without it the box is
     * empty for everyone and the assertions below would pass while proving nothing.
     */
    private function reader(): \App\Entity\User
    {
        $reader = $this->getUserByUsername('reader');
        $reader->addMentionsEntries = true;
        $this->entityManager->persist($reader);
        $this->entityManager->flush();

        return $reader;
    }

    public function testTheReplyFormWarnsAndDoesNotPrefillAReadOnlyAuthor(): void
    {
        $this->markHostReadOnly();

        $author = $this->getUserByUsername(self::HANDLE);
        $entry = $this->getEntryByTitle('a thread started from a read only instance', user: $author);

        $this->client->loginUser($this->reader());
        $crawler = $this->client->request('GET', "/m/{$entry->magazine->name}/t/{$entry->getId()}/-");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Replies will not be shown to', $crawler->html());
        // the handle already carries the host, so the host is never named a second time
        self::assertStringContainsString(self::HANDLE.' and their community', $crawler->html());
        self::assertStringNotContainsString(self::HANDLE.' or '.self::HOST, $crawler->html());
        // the whole point: the box must not hand the member a mention that goes nowhere
        self::assertStringNotContainsString(self::HANDLE, $crawler->filter('textarea')->first()->text());
    }

    public function testAnOrdinaryAuthorIsStillPrefilledAndCarriesNoNotice(): void
    {
        $this->markHostReadOnly();

        $author = $this->getUserByUsername('local_author');
        $entry = $this->getEntryByTitle('an ordinary local thread', user: $author);

        $this->client->loginUser($this->reader());
        $crawler = $this->client->request('GET', "/m/{$entry->magazine->name}/t/{$entry->getId()}/-");

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Replies will not be shown to', $crawler->html());
        self::assertStringContainsString('@local_author', $crawler->filter('textarea')->first()->text());
    }
}
