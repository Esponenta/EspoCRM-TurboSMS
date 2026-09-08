<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use Espo\Entities\Sms;
use Espo\ORM\EntityManager;
use RuntimeException;

final class SyncService
{
    public function __construct(
        private ApiClient $client,
        private IntegrationState $state,
        private DeliveryTracker $tracker,
        private EntityManager $entityManager,
    ) {}

    public function run(): void
    {
        $integration = $this->client->integration();
        $token = $this->state->acquire();

        if ($token === null) {
            throw new RuntimeException('TurboSMS synchronization is already running; retry this job.');
        }

        try {
            $error = null;

            try {
                $this->state->balance($this->client->balance());
            } catch (ApiException $exception) {
                $this->client->reportError($exception);
                $error = $exception;
            }

            try {
                $this->poll(max(1, min(100, (int) ($integration->get('turboSmsSyncBatchSize') ?: 50))));
            } catch (ApiException $exception) {
                $this->client->reportError($exception);
                $error = $exception;
            }

            if ($error !== null) {
                throw $error;
            }
        } finally {
            $this->state->release($token);
        }
    }

    private function poll(int $limit): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $records = $this->entityManager->getRDBRepository(Sms::ENTITY_TYPE)
            ->where(['turboSmsNextCheckAt<=' => $now])->order('turboSmsNextCheckAt')->order('id')->limit(0, 100)->find();
        $selected = [];
        $ids = [];

        foreach ($records as $sms) {
            $rows = DeliveryState::recipients($sms->get('turboSmsDeliveryData'));

            if ($sms->get('turboSmsTrackingEndsAt') && $sms->get('turboSmsTrackingEndsAt') <= $now) {
                $this->tracker->save($sms, $rows, true);
                continue;
            }

            $indices = [];
            $oldestFirst = array_keys($rows);
            usort($oldestFirst, static fn (int $a, int $b): int =>
                ($rows[$a]['nextCheckAt'] ?? '') <=> ($rows[$b]['nextCheckAt'] ?? ''));

            foreach ($oldestFirst as $index) {
                $row = $rows[$index];
                if (!($row['nextCheckAt'] ?? null) || $row['nextCheckAt'] > $now || !DeliveryState::pollable($row)) {
                    continue;
                }

                if (count($ids) >= $limit && !isset($ids[$row['messageId']])) {
                    break;
                }

                $ids[$row['messageId']] = true;
                $indices[] = $index;
            }

            if ($indices) {
                $selected[] = [$sms, $rows, $indices];
            }

            if (count($ids) >= $limit) {
                break;
            }
        }

        if (!$ids) {
            return;
        }

        $response = $this->client->details(array_keys($ids));
        $details = [];

        foreach ($response as $detail) {
            if (is_array($detail) && DeliveryState::validId($detail['message_id'] ?? null)) {
                $details[strtolower($detail['message_id'])] = $detail;
            }
        }

        foreach ($selected as [$sms, $rows, $indices]) {
            foreach ($indices as $index) {
                $row = $rows[$index];
                $rows[$index] = DeliveryState::apply($row, $details[$row['messageId']] ?? null, gmdate('Y-m-d H:i:s', time() + 60));
            }

            $this->tracker->save($sms, $rows);
        }
    }
}
