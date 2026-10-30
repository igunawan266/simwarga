<?php

namespace App\Controllers\Api\V1;

use App\Services\NotificationService;

/** /api/v1/office/notifications — tenant members (billing items for owners/admins only). */
class NotificationsController extends BaseApiController
{
    public function index()
    {
        $items = (new NotificationService())->forCurrentMember();

        return $this->ok(['notifications' => $items, 'unread' => count(array_filter($items, static fn ($n) => ! $n['read']))]);
    }

    public function markRead()
    {
        (new NotificationService())->markAllRead();

        return $this->ok(['read' => true]);
    }
}
