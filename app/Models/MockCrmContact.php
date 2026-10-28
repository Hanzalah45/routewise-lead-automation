<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockCrmContact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }
}
