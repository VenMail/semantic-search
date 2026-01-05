<?php

namespace Venmail\SemanticSearch\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Venmail\SemanticSearch\Tests\Models\Mail;

class MailFactory extends Factory
{
    protected $model = Mail::class;

    public function definition(): array
    {
        return [
            'subject' => $this->faker->sentence(3),
            'plain_body' => $this->faker->paragraph(),
            'sender_name' => $this->faker->name(),
            'sender_email' => $this->faker->unique()->safeEmail(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
