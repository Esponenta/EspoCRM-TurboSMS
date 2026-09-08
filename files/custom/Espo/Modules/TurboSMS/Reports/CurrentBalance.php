<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Reports;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Language;
use Espo\Entities\Integration;
use Espo\Entities\Sms;
use Espo\Entities\User;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\ORM\EntityManager;

final class CurrentBalance implements GridReport
{
    public const INTERNAL_NAME = 'TurboSMS:CurrentBalance';
    public const COLUMN = 'SUM:turboSmsBalance';

    private const STUB = '__STUB__';

    public function __construct(
        private EntityManager $entityManager,
        private Language $language,
    ) {}

    public function run(?WhereItem $where, ?User $user): Result
    {
        if ($user !== null && !$user->isAdmin()) {
            throw new Forbidden();
        }

        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'TurboSMS');
        $balance = $integration?->get('turboSmsBalance');

        if (!is_numeric($balance)) {
            throw new NotFound('TurboSMS balance is not available.');
        }

        $balance = (float) $balance;

        return new Result(
            entityType: Sms::ENTITY_TYPE,
            groupByList: [],
            columnList: [self::COLUMN],
            numericColumnList: [self::COLUMN],
            summaryColumnList: [self::COLUMN],
            aggregatedColumnList: [self::COLUMN],
            sums: (object) [self::COLUMN => $balance],
            columnNameMap: [
                self::COLUMN => $this->language->translateLabel(
                    'turboSmsBalance',
                    'fields',
                    Sms::ENTITY_TYPE,
                ),
            ],
            columnTypeMap: [self::COLUMN => 'decimal'],
            grouping: [[self::STUB]],
            reportData: (object) [
                self::STUB => (object) [self::COLUMN => $balance],
            ],
            columnDecimalPlacesMap: (object) [self::COLUMN => 2],
            noSubReport: true,
        );
    }

    public function runSubReport(
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user,
    ): ListResult {
        throw new BadRequest('Sub-report is not available.');
    }
}
