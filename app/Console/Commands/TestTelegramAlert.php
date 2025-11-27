<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramNotificationService;
use Illuminate\Console\Command;

class TestTelegramAlert extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitoring:test-telegram {--error : Send test error alert instead of test message}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Telegram notification system';

    /**
     * Execute the console command.
     */
    public function handle(TelegramNotificationService $telegram): int
    {
        $this->info('Testing Telegram notifications...');

        // Check configuration
        if (! config('monitoring.telegram.enabled')) {
            $this->error('Telegram notifications are disabled.');
            $this->info('Set TELEGRAM_NOTIFICATIONS_ENABLED=true in .env');

            return self::FAILURE;
        }

        if (empty(config('monitoring.telegram.bot_token'))) {
            $this->error('Telegram bot token is not configured.');
            $this->info('Set TELEGRAM_BOT_TOKEN in .env');
            $this->info('To create a bot: message @BotFather on Telegram and use /newbot command');

            return self::FAILURE;
        }

        if (empty(config('monitoring.telegram.chat_id'))) {
            $this->error('Telegram chat ID is not configured.');
            $this->info('Set TELEGRAM_CHAT_ID in .env');
            $this->info('To get your chat ID: message @userinfobot on Telegram');

            return self::FAILURE;
        }

        $this->info('Configuration OK');
        $this->info('Bot Token: '.substr(config('monitoring.telegram.bot_token'), 0, 10).'...');
        $this->info('Chat ID: '.config('monitoring.telegram.chat_id'));

        // Send test message
        if ($this->option('error')) {
            $this->info('Sending test error alert...');
            $testException = new \RuntimeException('This is a test error from monitoring:test-telegram command');
            $success = $telegram->sendServerErrorAlert($testException, [
                'method' => 'CLI',
                'url' => 'artisan monitoring:test-telegram --error',
                'ip' => '127.0.0.1',
            ]);
        } else {
            $this->info('Sending test notification...');
            $success = $telegram->sendTestNotification();
        }

        if ($success) {
            $this->info('✅ Telegram notification sent successfully!');
            $this->info('Check your Telegram messages.');

            return self::SUCCESS;
        }

        $this->error('❌ Failed to send Telegram notification.');
        $this->info('Check logs for details: storage/logs/laravel.log');

        return self::FAILURE;
    }
}
