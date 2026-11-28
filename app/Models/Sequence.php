<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['key', 'prefix', 'next_number'];
}
