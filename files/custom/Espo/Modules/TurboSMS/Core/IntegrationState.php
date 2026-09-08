<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use Espo\Core\Utils\Language;
use Espo\Entities\Integration;
use Espo\Entities\Notification;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use RuntimeException;

final class IntegrationState
{
    public const FIELDS = ['turboSmsBalance', 'turboSmsBalanceCheckedAt', 'turboSmsBalanceAlerted',
        'turboSmsSyncToken', 'turboSmsSyncStartedAt'];
    public const RECIPIENT_USER_TYPES = [User::TYPE_REGULAR, User::TYPE_ADMIN, User::TYPE_SUPER_ADMIN];

    public function __construct(private EntityManager $entityManager, private Language $language)
    {}

    public function acquire(): ?string
    {
        return $this->entityManager->getTransactionManager()->run(function (): ?string {
            $integration = $this->locked();
            $started = strtotime((string) $integration->get('turboSmsSyncStartedAt') . ' UTC');

            if ($integration->get('turboSmsSyncToken') && $started !== false && $started > time() - 600) {
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $this->write(['turboSmsSyncToken' => $token, 'turboSmsSyncStartedAt' => gmdate('Y-m-d H:i:s')]);

            return $token;
        });
    }

    public function release(string $token): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($token): void {
            if ($this->locked()->get('turboSmsSyncToken') === $token) {
                $this->write(['turboSmsSyncToken' => null, 'turboSmsSyncStartedAt' => null]);
            }
        });
    }

    public function balance(float $balance): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($balance): void {
            $integration = $this->locked();
            $values = ['turboSmsBalance' => $balance, 'turboSmsBalanceCheckedAt' => gmdate('Y-m-d H:i:s')];
            $threshold = $integration->get('turboSmsLowBalanceThreshold');
            $low = $integration->isEnabled() && $integration->get('turboSmsLowBalanceEnabled')
                && is_numeric($threshold) && $balance < (float) $threshold;

            if (!$low) {
                $values['turboSmsBalanceAlerted'] = false;
            } elseif (!$integration->get('turboSmsBalanceAlerted')) {
                $sent = false;
                $message = str_replace(['{balance}', '{threshold}'], [
                    number_format($balance, 2, '.', ''), number_format((float) $threshold, 2, '.', ''),
                ], $this->language->translateLabel('turboSmsLowBalanceNotification', 'messages', 'Integration'));

                foreach ($this->recipients($integration) as $user) {
                    $notification = $this->entityManager->getRDBRepositoryByClass(Notification::class)->getNew();
                    $notification->setType(Notification::TYPE_MESSAGE)->setMessage($message)->setUserId($user->getId());
                    $this->entityManager->saveEntity($notification);
                    $sent = true;
                }

                $values['turboSmsBalanceAlerted'] = $sent;
            }

            $this->write($values);
        });
    }

    /**
     * @return User[]
     */
    private function recipients(Integration $integration): array
    {
        $recipients = [];
        $userIds = $integration->get('turboSmsNotificationUsersIds');

        if (is_array($userIds) && $userIds) {
            $list = $this->entityManager->getRDBRepository(User::ENTITY_TYPE)
                ->where(['id' => $userIds, 'isActive' => true, 'type' => self::RECIPIENT_USER_TYPES])
                ->find();

            foreach ($list as $user) {
                $recipients[$user->getId()] = $user;
            }
        }

        $teamIds = $integration->get('turboSmsNotificationTeamsIds');

        if (is_array($teamIds) && $teamIds) {
            $list = $this->entityManager->getRDBRepository(User::ENTITY_TYPE)
                ->distinct()
                ->join('teams')
                ->where(['teams.id' => $teamIds, 'isActive' => true, 'type' => self::RECIPIENT_USER_TYPES])
                ->find();

            foreach ($list as $user) {
                $recipients[$user->getId()] = $user;
            }
        }

        if ($recipients) {
            return array_values($recipients);
        }

        return [...$this->entityManager->getRDBRepository(User::ENTITY_TYPE)
            ->where(['isActive' => true, 'type' => [User::TYPE_ADMIN, User::TYPE_SUPER_ADMIN]])
            ->find()];
    }

    private function locked(): Integration
    {
        $integration = $this->entityManager->getRDBRepository(Integration::ENTITY_TYPE)
            ->where(['id' => 'TurboSMS'])->forUpdate()->findOne();

        if (!$integration instanceof Integration) {
            throw new RuntimeException('TurboSMS integration has not been saved.');
        }

        return $integration;
    }

    private function write(array $values): void
    {
        // Only dedicated runtime columns; never overwrite concurrent Integration.data settings.
        $query = $this->entityManager->getQueryBuilder()->update()->in(Integration::ENTITY_TYPE)
            ->set($values)->where(['id' => 'TurboSMS'])->build();
        $this->entityManager->getQueryExecutor()->execute($query);
    }
}
