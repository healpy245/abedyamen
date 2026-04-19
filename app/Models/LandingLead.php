<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingLead extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'phone',
        'country',
        'restaurant_name',
        'restaurant_status',
        'ip_address',
        'user_agent',
    ];
}
