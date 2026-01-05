<?php

namespace Venmail\SemanticSearch\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SensitiveData extends Model
{
    use HasFactory;

    protected $table = 'sensitive_data';

    protected $guarded = [];
}
