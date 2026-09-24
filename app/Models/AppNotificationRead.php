<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['app_notification_id', 'devotee_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
