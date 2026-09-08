<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use Cron\CronExpression;
use InvalidArgumentException;

final class SyncSettings
{
    public static function normalize(array $values): array
    {
        foreach (['turboSmsSyncEnabled' => true, 'turboSmsLowBalanceEnabled' => false] as $field => $default) {
            $values[$field] ??= $default;

            if (!is_bool($values[$field])) {
                throw new InvalidArgumentException($field . ' must be a boolean.');
            }
        }

        foreach ([
            'turboSmsSyncBatchSize' => [50, 1, 100],
            'turboSmsTrackingDays' => [7, 1, 30],
            'turboSmsConnectTimeout' => [5, 1, 30],
            'turboSmsRequestTimeout' => [30, 5, 120],
        ] as $field => [$default, $min, $max]) {
            $raw = $values[$field] ?? $default;
            $value = is_bool($raw) ? false : filter_var($raw, FILTER_VALIDATE_INT);

            if ($value === false || $value < $min || $value > $max) {
                throw new InvalidArgumentException($field . ' is outside its allowed range.');
            }

            $values[$field] = $value;
        }

        unset($values['turboSmsSendTimeout']);

        if ($values['turboSmsRequestTimeout'] < $values['turboSmsConnectTimeout']) {
            throw new InvalidArgumentException('TurboSMS request timeout must not be shorter than the connection timeout.');
        }

        $cron = $values['turboSmsSyncCron'] ?? SyncSchedule::CRON;

        if (!is_string($cron) || !CronExpression::isValidExpression($cron)) {
            throw new InvalidArgumentException('TurboSMS cron expression is invalid.');
        }

        $values['turboSmsSyncCron'] = preg_replace('/\s+/', ' ', trim($cron));
        $threshold = $values['turboSmsLowBalanceThreshold'] ?? null;

        if ($threshold === '' && !$values['turboSmsLowBalanceEnabled']) {
            $threshold = null;
        }

        if (($threshold === null && $values['turboSmsLowBalanceEnabled']) || ($threshold !== null
            && (!is_numeric($threshold) || !is_finite((float) $threshold) || (float) $threshold < 0))) {
            throw new InvalidArgumentException('TurboSMS low-balance threshold must be a non-negative amount.');
        }

        $values['turboSmsLowBalanceThreshold'] = $threshold === null ? null : number_format((float) $threshold, 2, '.', '');
        foreach (['turboSmsNotificationUsersIds', 'turboSmsNotificationTeamsIds'] as $field) {
            $ids = $values[$field] ?? [];

            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100
                || array_filter($ids, static fn ($id) => !is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,24}$/D', $id))) {
                throw new InvalidArgumentException($field . ' contains invalid identifiers.');
            }

            $values[$field] = array_values(array_unique($ids));
        }

        return $values;
    }
}
