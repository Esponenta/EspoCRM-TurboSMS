<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

final class DeliveryState
{
    private const SUCCESS = ['Delivered', 'Read'];
    private const FAILURE = ['Expired', 'Undelivered', 'Rejected', 'Failed', 'Cancelled'];
    private const PROVIDER_STATES = ['Queued', 'Accepted', 'Sent', 'Delivered', 'Read', 'Expired',
        'Undelivered', 'Rejected', 'Failed', 'Cancelled', 'Unknown'];

    public static function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value) === 1;
    }

    public static function phone(string $value): string
    {
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        return strlen($digits) === 10 ? '38' . $digits : $digits;
    }

    public static function recipients(mixed $data): array
    {
        $data = (array) $data;
        $result = [];

        foreach ($data['recipients'] ?? [] as $row) {
            $row = (array) $row;

            foreach (['sms', 'viber'] as $channel) {
                if (isset($row[$channel])) {
                    $row[$channel] = (array) $row[$channel];
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    public static function initial(array $numbers, string $type): array
    {
        return array_map(static function (string $number) use ($type): array {
            $row = ['recipient' => $number, 'messageId' => null, 'responseCode' => null, 'nextCheckAt' => null];

            foreach (self::channels($type) as $channel) {
                $row[$channel] = ['status' => 'Unconfirmed'];
            }

            return $row;
        }, $numbers);
    }

    public static function capture(array $rows, array $response, string $now): array
    {
        $byPhone = [];

        foreach ($response as $item) {
            if (is_array($item) && is_string($item['phone'] ?? null)) {
                $byPhone[self::phone($item['phone'])] = $item;
            }
        }

        foreach ($rows as &$row) {
            $item = $byPhone[$row['recipient']] ?? [];
            $code = $item['response_code'] ?? null;
            $accepted = $code === 0 && self::validId($item['message_id'] ?? null);
            $row['responseCode'] = is_int($code) ? $code : null;
            $row['messageId'] = $accepted ? strtolower($item['message_id']) : null;
            $row['nextCheckAt'] = $accepted ? $now : null;

            foreach (['sms', 'viber'] as $channel) {
                if (!isset($row[$channel])) {
                    continue;
                }

                $row[$channel]['status'] = $accepted
                    ? ($channel === 'sms' && isset($row['viber']) ? 'WaitingFallback' : 'Queued')
                    : (is_int($code) && $code !== 0 ? 'Failed' : 'Unconfirmed');
            }
        }
        unset($row);

        return $rows;
    }

    public static function rejected(array $rows, ApiException $error): array
    {
        foreach ($rows as &$row) {
            $row['responseCode'] = $error->getCode() ?: null;

            foreach (['sms', 'viber'] as $channel) {
                if (isset($row[$channel])) {
                    $row[$channel]['status'] = $error->uncertain ? 'Unconfirmed' : 'Failed';
                }
            }
        }
        unset($row);

        return $rows;
    }

    public static function apply(array $row, ?array $detail, string $nextCheckAt): array
    {
        $row['nextCheckAt'] = $nextCheckAt;
        $row['statusResponseCode'] = is_int($detail['response_code'] ?? null) ? $detail['response_code'] : null;
        $row['checkedAt'] = gmdate('Y-m-d H:i:s');

        if (!$detail || ($detail['response_code'] ?? null) !== 0) {
            return $row;
        }

        foreach (['sms', 'viber'] as $channel) {
            $incoming = $detail[$channel] ?? null;

            if (!is_array($incoming) || !isset($row[$channel]) || !is_string($incoming['status'] ?? null)) {
                continue;
            }

            if (self::phone((string) ($incoming['recipient'] ?? '')) !== $row['recipient']) {
                continue;
            }

            $previous = $row[$channel];
            $updated = self::date($incoming['updated'] ?? null);

            // Provider timestamps are offset-free; retain their raw ordering, not an assumed timezone.
            if ($updated && ($previous['updatedAt'] ?? '') > $updated) {
                continue;
            }

            $status = in_array($incoming['status'], self::PROVIDER_STATES, true) ? $incoming['status'] : 'Unknown';

            if (in_array($previous['status'], self::SUCCESS, true) && !in_array($status, self::SUCCESS, true)) {
                continue;
            }

            if ($previous['status'] === 'Read' && $status === 'Delivered') {
                continue;
            }

            $row[$channel] = [
                'status' => $status,
                'providerStatus' => substr($incoming['status'], 0, 80),
                'sentAt' => self::date($incoming['sent'] ?? null),
                'updatedAt' => $updated,
                'cost' => is_numeric($incoming['price'] ?? null) ? (float) $incoming['price'] : null,
                'segments' => is_int($incoming['segments'] ?? null) ? $incoming['segments'] : null,
                'rejectedStatus' => is_string($incoming['rejected_status'] ?? null)
                    ? substr($incoming['rejected_status'], 0, 100) : null,
            ];
        }

        if (isset($row['sms'], $row['viber']) && in_array($row['viber']['status'], self::SUCCESS, true)
            && $row['sms']['status'] === 'WaitingFallback') {
            $row['sms']['status'] = 'NotUsed';
        }

        if (!self::pollable($row)) {
            $row['nextCheckAt'] = null;
        }

        return $row;
    }

    public static function pollable(array $row): bool
    {
        if (!self::validId($row['messageId'] ?? null)) {
            return false;
        }

        foreach (['sms', 'viber'] as $channel) {
            $status = $row[$channel]['status'] ?? null;

            if ($status === null || $status === 'NotUsed' || in_array($status, self::FAILURE, true)) {
                continue;
            }

            if ($status === 'Read' || ($channel === 'sms' && $status === 'Delivered')) {
                continue;
            }

            return true;
        }

        return false;
    }

    public static function aggregate(array $rows): string
    {
        $delivered = $failed = $unknown = $pending = 0;

        foreach ($rows as $row) {
            $states = array_values(array_filter([$row['sms']['status'] ?? null, $row['viber']['status'] ?? null]));

            if (array_intersect($states, self::SUCCESS)) {
                $delivered++;
            } elseif ($states && !array_diff($states, [...self::FAILURE, 'NotUsed'])) {
                $failed++;
            } elseif (!$states || array_intersect($states, ['Unknown', 'Unconfirmed'])) {
                $unknown++;
            } else {
                $pending++;
            }
        }

        $total = count($rows);

        if (!$total || $unknown === $total) {
            return 'Unknown';
        }
        if ($delivered === $total) {
            return 'Delivered';
        }
        if ($failed === $total) {
            return 'Failed';
        }
        if ($delivered > 0) {
            return 'PartiallyDelivered';
        }
        if ($unknown && !$pending) {
            return 'Unknown';
        }

        return $failed || $unknown || array_filter($rows, static fn ($r) =>
            in_array($r['sms']['status'] ?? '', ['Accepted', 'Sent'], true)
            || in_array($r['viber']['status'] ?? '', ['Accepted', 'Sent'], true)) ? 'InProgress' : 'Queued';
    }

    public static function nextCheck(array $rows): ?string
    {
        $dates = array_filter(array_column($rows, 'nextCheckAt'));

        return $dates ? min($dates) : null;
    }

    private static function channels(string $type): array
    {
        return $type === 'hybrid' ? ['viber', 'sms'] : [$type];
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)
            ? $value : null;
    }
}
