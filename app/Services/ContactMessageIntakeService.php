<?php

namespace App\Services;

use App\Models\ContactMessage;

class ContactMessageIntakeService
{
    public function __construct(
        private readonly AdminWebPushNotificationService $adminNotifications,
    ) {}

    public function create(array $attributes): ContactMessage
    {
        $message = ContactMessage::query()->create($attributes);
        $this->adminNotifications->notifyContactMessage($message);

        return $message;
    }
}
