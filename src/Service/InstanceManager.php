<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ModeratorDto;
use App\Entity\Instance;
use App\Entity\User;
use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class InstanceManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SettingsManager $settingsManager,
        private InstanceRepository $instanceRepository,
    ) {
    }

    public function addModerator(ModeratorDto $dto): void
    {
        $dto->user->roles = array_unique(array_merge($dto->user->roles, ['ROLE_MODERATOR']));

        $this->entityManager->persist($dto->user);
        $this->entityManager->flush();
    }

    public function removeModerator(User $user): void
    {
        $user->roles = array_diff($user->roles, ['ROLE_MODERATOR']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    /** @param string[] $bannedInstances */
    #[\Deprecated]
    public function setBannedInstances(array $bannedInstances): void
    {
        $previousBannedInstances = $this->instanceRepository->getBannedInstanceUrls();
        foreach ($bannedInstances as $instance) {
            if (!\in_array($instance, $previousBannedInstances, true)) {
                $this->banInstance($this->instanceRepository->getOrCreateInstance($instance));
            }
        }
        foreach ($previousBannedInstances as $instance) {
            if (!\in_array($instance, $bannedInstances, true)) {
                $this->unbanInstance($this->instanceRepository->getOrCreateInstance($instance));
            }
        }
    }

    public function banInstance(Instance $instance): void
    {
        if ($this->settingsManager->getUseAllowList()) {
            throw new \LogicException('Cannot ban an instance when using an allow list');
        }
        $instance->isBanned = true;
        $instance->isExplicitlyAllowed = false;

        $this->entityManager->flush();
    }

    public function unbanInstance(Instance $instance): void
    {
        if ($this->settingsManager->getUseAllowList()) {
            throw new \LogicException('Cannot unban an instance when using an allow list');
        }
        $instance->isBanned = false;

        $this->entityManager->flush();
    }

    public function allowInstanceFederation(Instance $instance): void
    {
        if (!$this->settingsManager->getUseAllowList()) {
            throw new \LogicException('Cannot allow instance federation when not using an allow list');
        }
        $instance->isExplicitlyAllowed = true;
        $instance->isBanned = false;

        $this->entityManager->flush();
    }

    public function denyInstanceFederation(Instance $instance): void
    {
        if (!$this->settingsManager->getUseAllowList()) {
            throw new \LogicException('Cannot deny instance federation when not using an allow list');
        }
        $instance->isExplicitlyAllowed = false;
        // clear the read only marking too, so allowing the instance again later does not
        // silently restore a state the admin set in a different context
        $instance->isReadOnly = false;

        $this->entityManager->flush();
    }

    public function markInstanceReadOnly(Instance $instance): void
    {
        if (!$this->settingsManager->getUseAllowList()) {
            throw new \LogicException('Cannot mark an instance read only when not using an allow list');
        }
        if (!$instance->isExplicitlyAllowed) {
            throw new \LogicException('Cannot mark an instance read only when it is not allowed to federate');
        }
        $instance->isReadOnly = true;

        $this->entityManager->flush();
    }

    public function markInstanceReadWrite(Instance $instance): void
    {
        if (!$this->settingsManager->getUseAllowList()) {
            throw new \LogicException('Cannot mark an instance read write when not using an allow list');
        }
        // deliberately no isExplicitlyAllowed guard: clearing the flag has to stay possible
        // on an instance whose allow was withdrawn while it was marked read only
        $instance->isReadOnly = false;

        $this->entityManager->flush();
    }
}
