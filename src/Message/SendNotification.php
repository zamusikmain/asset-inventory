<?php

namespace App\Message;

final class SendNotification
{
    public function __construct(public readonly int $notificationId)
    {
    }
}
