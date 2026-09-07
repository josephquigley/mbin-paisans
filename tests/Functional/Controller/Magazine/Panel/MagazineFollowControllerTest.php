<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Magazine\Panel;

use App\Entity\MagazineFollow;
use App\Enum\MagazineFollowKind;
use App\Tests\WebTestCase;

class MagazineFollowControllerTest extends WebTestCase
{
    public function testFollowsPanelRedirectsToTagsPanel(): void
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));
        $this->getMagazineByName('acme');

        $this->client->request('GET', '/m/acme/panel/follows');

        $this->assertResponseRedirects('/m/acme/panel/tags');
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#main .options__main a.active', 'Tags & Follows');
    }

    public function testUnauthorizedUserCannotLoadFollowsPanel(): void
    {
        $this->client->loginUser($this->getUserByUsername('JaneDoe'));
        $this->getMagazineByName('acme');

        $this->client->request('GET', '/m/acme/panel/follows');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAddingMalformedActorHandleShowsNotFoundFlashInsteadOf500(): void
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));
        $this->getMagazineByName('acme');

        $crawler = $this->client->request('GET', '/m/acme/panel/tags');
        $this->client->submit(
            $crawler->filter('#main form[name=follow]')->selectButton('Add follow')->form([
                'actor' => 'hello',
            ])
        );

        $this->assertResponseRedirects('/m/acme/panel/tags');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert__danger', "That doesn't look like an actor.");
    }

    public function testTheFollowListShowsWhatEachFollowCarries(): void
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));
        $magazine = $this->getMagazineByName('acme');

        $follow = new MagazineFollow($magazine, $this->getUserByUsername('JaneDoe'));
        $follow->kind = MagazineFollowKind::Announce;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $this->client->request('GET', '/m/acme/panel/tags');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#main .follows-table', 'Boosts, Re-Blogs & Announcements');
    }

    public function testRemovingFollowFromAnotherMagazineIsRefused(): void
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));
        $magazineA = $this->getMagazineByName('acme');
        $magazineB = $this->getMagazineByName('other', $this->getUserByUsername('JohnDoe'));

        $follow = new MagazineFollow($magazineB, $this->getUserByUsername('JaneDoe'));
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        // Load magazine B's own panel first to get a genuine CSRF token for
        // this follow row, minted in the same session as the request below.
        $crawler = $this->client->request('GET', '/m/other/panel/tags');
        $token = $crawler->filter('#main .follows-table input[name=token]')->attr('value');

        $this->client->request(
            'POST',
            '/m/acme/panel/follows/'.$follow->getId().'/remove',
            ['token' => $token]
        );

        $this->assertResponseStatusCodeSame(403);

        // The follow belonging to magazine B must survive the refused attempt.
        $this->assertNotNull($this->entityManager->getRepository(MagazineFollow::class)->find($follow->getId()));
    }

    public function testAddingFollowOnRestrictedMagazineIsRefused(): void
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));
        $magazine = $this->getMagazineByName('acme');
        $magazine->postingRestrictedToMods = true;
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/m/acme/panel/tags');
        $this->client->submit(
            $crawler->filter('#main form[name=follow]')->selectButton('Add follow')->form([
                'actor' => '@someone@remote.tld',
            ])
        );

        $this->assertResponseRedirects('/m/acme/panel/tags');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '.alert__danger',
            'This magazine only accepts posts from moderators'
        );
    }
}
