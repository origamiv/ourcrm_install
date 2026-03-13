<?php

namespace App\Providers;

use App\Services\TelegramBotService;
use App\Telegram\CommandDispatcher;
use App\Telegram\RedisSignalProcessor;
use Illuminate\Support\ServiceProvider;
use Telegram\Bot\Api;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CommandDispatcher::class, function () {
            $dispatcher = new CommandDispatcher();
            $dispatcher->register(config('telegram.commands', []));
            return $dispatcher;
        });

        $this->app->singleton(TelegramBotService::class, function ($app) {
            $api        = new Api(config('telegram.bot_token'));
            $dispatcher = $app->make(CommandDispatcher::class);
            return new TelegramBotService($api, $dispatcher);
        });

        $this->app->singleton(RedisSignalProcessor::class, function ($app) {
            return new RedisSignalProcessor($app->make(TelegramBotService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

    }
}
