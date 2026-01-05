<?php

namespace Venmail\SemanticSearch\Tests\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'title',
        'content',
        'user_id',
        'published',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
