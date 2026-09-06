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

class MagazineFollowController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MagazineFollowRepository $repository,
        private readonly ActivityPubManager $activityPubManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
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
            $this->validateCsrf('magazine_follow_add', $request->request->get('token'));

            $this->add($magazine, trim((string) $request->request->get('actor')));

            return $this->redirectToRoute('magazine_panel_follows', ['name' => $magazine->name]);
        }

        return $this->render(
            'magazine/panel/follows.html.twig',
            [
                'magazine' => $magazine,
                'follows' => $this->repository->findByMagazine($magazine),
            ]
        );
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

        return $this->redirectToRefererOrHome($request);
    }

    private function add(Magazine $magazine, string $actorInput): void
    {
        if ($magazine->postingRestrictedToMods) {
            $this->addFlash('error', 'flash_magazine_follow_restricted_error');

            return;
        }

        if ('' === $actorInput) {
            $this->addFlash('error', 'flash_magazine_follow_not_found_error');

            return;
        }

        try {
            $actor = $this->activityPubManager->findActorOrCreate($actorInput);
        } catch (\Exception $e) {
            $this->logger->warning(
                '[MagazineFollowController::add] Failed to resolve actor "{actor}": {message}',
                ['actor' => $actorInput, 'message' => $e->getMessage()]
            );
            $actor = null;
        }

        if (null === $actor) {
            $this->addFlash('error', 'flash_magazine_follow_not_found_error');

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
}
