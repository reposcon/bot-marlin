<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    protected $fillable = [
        'phone_number' ,
        'content',
        'event_date'
    ];
}
