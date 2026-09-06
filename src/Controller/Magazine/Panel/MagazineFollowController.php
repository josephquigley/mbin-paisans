<?php

declare(strict_types=1);

namespace App\Controller\Magazine\Panel;

use App\Controller\AbstractController;
use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Message\ActivityPub\Outbox\FollowMessage;
use App\Repository\MagazineFollowRepository;
use App\Service\ActivityPubManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class MagazineFollowController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MagazineFollowRepository $repository,
        private readonly ActivityPubManager $activityPubManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('moderate', subject: 'magazine')]
    public function follows(
        #[MapEntity(mapping: ['name' => 'name'])]
        Magazine $magazine,
        Request $request,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->validateCsrf('magazine_follow_add', $request->getPayload()->get('token'));

            $this->add($magazine, trim((string) $request->request->get('actor')));
        }

        return $this->redirectToRoute('magazine_panel_tags', ['name' => $magazine->name]);
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('moderate', subject: 'magazine')]
    public function remove(
        #[MapEntity(mapping: ['magazine_name' => 'name'])]
        Magazine $magazine,
        #[MapEntity(id: 'follow_id')]
        MagazineFollow $follow,
        Request $request,
    ): Response {
        $this->validateCsrf('magazine_follow_remove', $request->getPayload()->get('token'));

        if ($follow->magazine !== $magazine) {
            throw $this->createAccessDeniedException();
        }

        $actor = $follow->getFollowingActor();

        $this->bus->dispatch(new FollowMessage(
            $magazine->getId(),
            $actor->getId(),
            unfollow: true,
            magazine: $actor instanceof Magazine,
            followerIsMagazine: true,
        ));

        $this->entityManager->remove($follow);
        $this->entityManager->flush();

        return $this->redirectToRoute('magazine_panel_tags', ['name' => $magazine->name]);
    }

    private function add(Magazine $magazine, string $actorInput): void
    {
        if ($magazine->postingRestrictedToMods) {
            $this->addFlash('error', 'flash_magazine_follow_restricted_error');

            return;
        }

        $actorInput = trim($actorInput);

        if ('' === $actorInput) {
            $this->addFlash('error', 'flash_magazine_follow_not_found_error');

            return;
        }

        [$normalizedInput, $derivedFromUrl] = $this->normalizeActorInput($actorInput);

        try {
            $actor = $this->activityPubManager->findActorOrCreate($normalizedInput);
            $malformed = false;
        } catch (\Throwable $e) {
            // Throwable, not Exception. Resolving an actor reaches webfinger and
            // a signed HTTP fetch, and those paths raise Error as well as
            // Exception: an instance whose site row has no keypair makes
            // ApHttpClient::getInstancePrivateKey() return null against a string
            // return type, which is a TypeError. Error does not extend
            // Exception, so catching Exception alone still returns a 500 to the
            // moderator.
            $this->logger->warning(
                '[MagazineFollowController::add] Failed to resolve actor "{actor}": {message}',
                ['actor' => $normalizedInput, 'message' => $e->getMessage()]
            );
            $malformed = str_contains($e->getMessage(), 'WebFinger handle is malformed');
            $actor = null;
        }

        if (null === $actor) {
            if ($malformed) {
                $this->addFlash('error', 'flash_magazine_follow_malformed_error');
            } elseif ($derivedFromUrl) {
                // The moderator typed a profile URL, not a handle. Say so in
                // terms of the handle we actually looked up, since that is
                // what really failed, not the URL as typed.
                $this->addFlash('error', $this->translator->trans(
                    'flash_magazine_follow_derived_handle_error',
                    ['%handle%' => $normalizedInput]
                ));
            } else {
                $this->addFlash('error', 'flash_magazine_follow_not_found_error');
            }

            return;
        }

        if (null === $actor->apId) {
            $this->addFlash('error', 'flash_magazine_follow_local_actor_error');

            return;
        }

        if (null !== $this->repository->findOneByMagazineAndActor($magazine, $actor)) {
            $this->addFlash('error', 'flash_magazine_follow_exists_error');

            return;
        }

        $follow = new MagazineFollow($magazine, $actor);
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $this->bus->dispatch(new FollowMessage(
            $magazine->getId(),
            $actor->getId(),
            magazine: $actor instanceof Magazine,
            followerIsMagazine: true,
        ));

        $this->addFlash('success', 'flash_magazine_follow_add_success');
    }

    /**
     * Normalise the handful of input shapes a moderator is likely to type
     * before handing them to ActivityPubManager::findActorOrCreate(), which
     * only accepts a webfinger handle containing "@" or an absolute
     * "http(s)://" URL.
     *
     * @return array{0: string, 1: bool} the string to resolve, and whether it
     *                                   was derived from a profile URL
     *                                   rather than typed as a handle
     */
    private function normalizeActorInput(string $actorInput): array
    {
        $hasScheme = (bool) preg_match('#^https?://#i', $actorInput);

        // Case 1 and 2: already a webfinger handle, typed with or without
        // its leading "@" ("@user@host" or "user@host"). Neither needs URL
        // parsing.
        if (!$hasScheme && str_contains($actorInput, '@')) {
            return [str_starts_with($actorInput, '@') ? $actorInput : '@'.$actorInput, false];
        }

        if (!$hasScheme && !preg_match('#^[\w.-]+\.[a-z]{2,}(/.*)?$#i', $actorInput)) {
            // Neither a handle nor a URL nor a bare domain (no dot, e.g. a
            // single typo'd word): leave it untouched and let
            // findActorOrCreate()'s existing webfinger validation reject it
            // as malformed, rather than turning it into a URL that was
            // never a plausible host.
            return [$actorInput, false];
        }

        $url = $hasScheme ? $actorInput : 'https://'.$actorInput;
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($segment) => '' !== $segment));

        if (null !== $host && 1 === \count($segments)) {
            // Case 3 and 4: a profile URL with exactly one path segment,
            // which is what a browser address bar copy ("host/user" or
            // "https://host/user/") and a Mastodon-style link
            // ("https://host/@user") both produce. Webfinger publishes the
            // handle as an alias of that exact URL, so deriving it here is
            // resolving the actor, not guessing at one.
            $username = ltrim($segments[0], '@');

            return [\sprintf('@%s@%s', $username, $host), true];
        }

        // Case 5: an absolute URL whose path already looks like an actor id
        // (no path segment, or more than one, such as
        // "/api/collections/user"). Pass it through as a URL, adding
        // "https://" only if it did not have a scheme, and drop a trailing
        // slash so it still matches a stored apId or apProfileId, which
        // mbin keeps without one.
        return [rtrim($url, '/'), false];
    }
}
