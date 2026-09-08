<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS;

use Espo\Core\Exceptions\Error;
use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Entities\Sms as SmsEntity;
use Espo\Modules\TurboSMS\Core\ApiClient;
use Espo\Modules\TurboSMS\Core\ApiException;
use Espo\Modules\TurboSMS\Core\DeliveryState;
use Espo\Modules\TurboSMS\Core\DeliveryTracker;

final class TurboSmsHttpSender implements Sender
{
    public function __construct(private ApiClient $client, private DeliveryTracker $tracker)
    {}

    public function send(Sms $sms): void
    {
        $integration = $this->client->integration();
        $type = (string) ($integration->get('turboSmsSendType') ?: 'sms');
        $recipients = array_values(array_unique(array_filter(array_map(DeliveryState::phone(...), $sms->getToNumberList()))));
        $body = self::buildBody(
            $recipients,
            $sms->getBody(),
            $type,
            (string) $integration->get('turboSmsSenderSms'),
            (string) $integration->get('turboSmsSenderViber'),
        );

        $tracked = $sms instanceof SmsEntity && (!$sms->isNew() || $sms->get('status') === SmsEntity::STATUS_SENDING);

        if ($tracked) {
            $this->tracker->start($sms, $recipients, $type, (int) ($integration->get('turboSmsTrackingDays') ?: 7));
        }

        try {
            $response = $this->client->send($body);
        } catch (ApiException $error) {
            if ($tracked) {
                $this->tracker->reject($sms, $error);
            }

            $this->client->reportError($error);
            throw new Error($error->getMessage());
        }

        $accepted = $tracked
            ? $this->tracker->accept($sms, $response)
            : (bool) array_filter(DeliveryState::capture(DeliveryState::initial($recipients, $type), $response, gmdate('Y-m-d H:i:s')),
                static fn ($row) => $row['messageId'] !== null);

        if (!$accepted) {
            throw new Error('TurboSMS did not confirm any recipient. Check delivery details before sending a new message.');
        }
    }

    public static function buildBody(array $recipients, string $message, string $type, string $senderSms, string $senderViber): array
    {
        if (!$recipients || count($recipients) > 5000 || array_filter($recipients,
            static fn ($number) => !preg_match('/^[1-9][0-9]{7,14}$/D', $number))) {
            throw new Error('TurboSMS recipient list is invalid.');
        }

        if (trim($message) === '' || !in_array($type, ['sms', 'viber', 'hybrid'], true)) {
            throw new Error('TurboSMS message body or send type is invalid.');
        }

        $body = ['recipients' => $recipients];

        foreach ($type === 'hybrid' ? ['viber', 'sms'] : [$type] as $channel) {
            $sender = trim($channel === 'sms' ? $senderSms : $senderViber);

            if ($sender === '') {
                throw new Error('TurboSMS ' . $channel . ' sender is not configured.');
            }

            $body[$channel] = ['sender' => $sender, 'text' => trim($message)];
        }

        return $body;
    }
}
