<?php

namespace LaravelUserDiscounts;

use Illuminate\Support\ServiceProvider;
use LaravelUserDiscounts\Services\DiscountManager;

class UserDiscountsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes(
            [__DIR__.'/../config/user_discounts.php' => config_path('user_discounts.php')],
            'config'
        );

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/user_discounts.php', 'user_discounts'
        );

        // Register the Discount Manager
        $this->app->singleton('user.discounts', function () {
            return new DiscountManager();
        });

        // Optional Facade Access
        $this->app->alias('user.discounts', DiscountManager::class);
    }
}