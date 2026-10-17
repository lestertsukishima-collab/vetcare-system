<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class ClinicModel extends Model
{
    // Controllers validate input and pass explicit fields to domain models.
    protected $guarded = ['id'];
}
