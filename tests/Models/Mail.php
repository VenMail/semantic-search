<?php

namespace Venmail\SemanticSearch\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mail extends Model
{
    use HasFactory;

    protected $table = 'mails';

    protected $fillable = [
        'subject',
        'plain_body',
        'sender_name',
        'sender_email',
    ];
}
