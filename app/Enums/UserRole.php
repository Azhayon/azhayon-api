<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case User = 'user';

    public function isStaff(): bool
    {
        return in_array($this, [self::Admin, self::Editor], true);
    }
}