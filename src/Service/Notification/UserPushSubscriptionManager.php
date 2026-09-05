<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Payloads\PushNotification;
use App\Repository\SiteRepository;
use App\Repository\UserPushSubscriptionRepository;
use App\Service\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserPushSubscriptionManager
{
    public function __construct(
        private readonly SettingsManager $settingsManager,
        private readonly SiteRepository $siteRepository,
        private readonly UserPushSubscriptionRepository $pushSubscriptionRepository,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return string[] the failure reason of every send that the push service refused, empty when all of them succeeded
     *
     * @throws \ErrorException
     */
    public function sendTextToUser(User $user, PushNotification|Notification $pushNotification, ?string $specificDeviceKey = null, ?AccessToken $specificToken = null): array
    {
        $webPush = $this->getWebPush();
        $criteria = ['user' => $user];
        if ($specificDeviceKey) {
            $criteria['deviceKey'] = $specificDeviceKey;
        }
        if ($specificToken) {
            $criteria['apiToken'] = $specificToken;
        }
        $subs = $this->pushSubscriptionRepository->findBy($criteria);
        foreach ($subs as $sub) {
            if ($pushNotification instanceof Notification) {
                $toSend = $pushNotification->getMessage($this->translator, $sub->locale ?? $this->settingsManager->get('KBIN_DEFAULT_LANG'), $this->urlGenerator);
            } elseif ($pushNotification instanceof PushNotification) {
                $toSend = $pushNotification;
            } else {
                throw new \InvalidArgumentException();
            }
            $this->logger->debug("Sending text '{t}' to {u}#{dk}. {json}", [
                't' => $toSend->title.'. '.$toSend->message,
                'u' => $user->username,
                'dk' => $sub->deviceKey ?? 'someOAuth',
                'json' => json_encode($sub),
            ]);
            $webPush->queueNotification(
                new Subscription($sub->endpoint, $sub->contentEncryptionPublicKey, $sub->serverAuthKey, contentEncoding: 'aes128gcm'),
                payload: json_encode($toSend)
            );
        }
        /**
         * Check sent results.
         *
         * @var MessageSentReport $report
         */
        $failures = [];
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $this->logger->debug('[v] Message sent successfully for subscription {e}.', ['e' => $endpoint]);
            } else {
                $failures[] = $report->getReason();
                $this->logger->warning('[x] Message failed to sent for subscription {e}: {r}', ['e' => $endpoint, 'r' => $report->getReason()]);
                if ($report->isSubscriptionExpired()) {
                    $subscriptions = $this->pushSubscriptionRepository->findBy(['endpoint' => $endpoint]);
                    foreach ($subscriptions as $sub) {
                        $this->entityManager->remove($sub);
                    }
                    $this->logger->info('Removed push subscription for user "{u}" at endpoint "{e}", because it expired', ['e' => $endpoint, 'u' => $user->username]);
                }
            }
        }

        return $failures;
    }

    /**
     * The "sub" claim of the VAPID token, which RFC 8292 section 2.1 requires to be a
     * "mailto:" or "https:" URI so that a push service operator can reach the sender.
     * Some push services enforce that: Apple refuses a bare domain with 403 BadJwtToken.
     */
    public function getVapidSubject(): string
    {
        $contactEmail = trim((string) $this->settingsManager->get('KBIN_CONTACT_EMAIL'));

        if ('' !== $contactEmail) {
            return 'mailto:'.$contactEmail;
        }

        return 'https://'.$this->settingsManager->get('KBIN_DOMAIN');
    }

    /**
     * @throws \ErrorException
     */
    public function getWebPush(): WebPush
    {
        $site = $this->siteRepository->findAll()[0];
        $auth = [
            'VAPID' => [
                'subject' => $this->getVapidSubject(),
                'publicKey' => $site->pushPublicKey,
                'privateKey' => $site->pushPrivateKey,
            ],
        ];

        return new WebPush($auth);
    }
}
