<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ToolCallLog extends Model
{
    use HasUuids;

    protected $table = 'tool_calls_log';

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'json',
    ];
}
