<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TurboSMS\Core\SyncSchedule;

final class PostSync implements Action
{
    public function __construct(private User $user, private SyncSchedule $schedule)
    {}

    public function process(Request $request): Response
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }

        return ResponseComposer::json(['scheduled' => $this->schedule->enqueue()]);
    }
}
