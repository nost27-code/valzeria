<?php

namespace App\Services;

enum FieldEvent: string
{
    case CREATED = 'field_created';
    case REFRESHED = 'field_refreshed';
    case EXTENDED = 'field_extended';
    case OVERWRITTEN = 'field_overwritten';
    case EXPIRED = 'field_expired';
    case OVERLAY_CREATED = 'field_overlay_created';
    case OVERLAY_EXPIRED = 'field_overlay_expired';
    case ECHO_CREATED = 'field_echo_created';
    case ECHO_EXPIRED = 'field_echo_expired';
    case OVERWRITE_BLOCKED = 'field_overwrite_blocked';
    case LOCKED = 'field_locked';
    case LOCK_REFRESHED = 'field_lock_refreshed';
}
