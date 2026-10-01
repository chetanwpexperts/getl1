<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An early-access or demo request from the public website. */
class Lead extends Model
{
    public const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'converted' => 'Converted', 'closed' => 'Closed'];

    protected $fillable = ['name', 'company', 'email', 'phone', 'city', 'interest', 'monthly_spend', 'message', 'source', 'status', 'notes', 'ip'];
}
