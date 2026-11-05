<?php

namespace Database\Factories;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NewsletterSubscriber> */
class NewsletterSubscriberFactory extends Factory
{
    protected $model = NewsletterSubscriber::class;

    public function definition(): array
    {
        return [
            'email' => 'reader'.fake()->unique()->numberBetween(1, 999999).'@example.test',
            'status' => NewsletterStatus::Pending,
        ];
    }
}