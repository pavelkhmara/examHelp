<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    private string $botToken;

    private string $chatId;

    private bool $enabled;

    public function __construct()
    {
        $this->botToken = config('monitoring.telegram.bot_token', '');
        $this->chatId = config('monitoring.telegram.chat_id', '');
        $this->enabled = config('monitoring.telegram.enabled', false);
    }

    /**
     * Send alert message to Telegram
     *
     * @param  string  $message  Alert message
     * @param  array  $context  Additional context (exception, request, etc.)
     * @return bool Success status
     */
    public function sendAlert(string $message, array $context = []): bool
    {
        if (! $this->enabled) {
            Log::debug('Telegram notifications disabled');

            return false;
        }

        if (empty($this->botToken) || empty($this->chatId)) {
            Log::error('Telegram bot token or chat ID not configured');

            return false;
        }

        // Rate limiting: max 1 alert per error type per 5 minutes
        $rateLimitKey = $this->getRateLimitKey($message, $context);
        if (Cache::has($rateLimitKey)) {
            Log::debug('Telegram alert rate limited', ['key' => $rateLimitKey]);

            return false;
        }

        $formattedMessage = $this->formatMessage($message, $context);

        try {
            $response = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                [
                    'chat_id' => $this->chatId,
                    'text' => $formattedMessage,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            );

            if ($response->successful()) {
                // Set rate limit cache: 5 minutes
                Cache::put($rateLimitKey, true, now()->addMinutes(5));
                Log::info('Telegram alert sent successfully');

                return true;
            }

            Log::error('Telegram API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram alert', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Send server error alert (500 errors)
     */
    public function sendServerErrorAlert(\Throwable $exception, array $requestContext = []): bool
    {
        $message = "🔴 <b>Server Error (500)</b>\n\n";

        return $this->sendAlert($message, [
            'exception' => $exception,
            'request' => $requestContext,
        ]);
    }

    /**
     * Send test notification
     */
    public function sendTestNotification(): bool
    {
        $message = "✅ <b>Test Notification</b>\n\n";
        $message .= "Telegram monitoring is working correctly!\n";
        $message .= 'Server: '.config('app.name')."\n";
        $message .= 'Environment: '.config('app.env')."\n";
        $message .= 'Time: '.now()->format('Y-m-d H:i:s');

        try {
            $response = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                ]
            );

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Failed to send test notification', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Format alert message with context
     */
    private function formatMessage(string $message, array $context): string
    {
        $formatted = $message;

        // Add exception details
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $formatted .= '<b>Exception:</b> '.get_class($e)."\n";
            $formatted .= '<b>Message:</b> '.$this->truncate($e->getMessage(), 200)."\n";
            $formatted .= '<b>File:</b> '.basename($e->getFile()).':'.$e->getLine()."\n\n";
        }

        // Add request details
        if (isset($context['request'])) {
            $req = $context['request'];
            if (isset($req['method'], $req['url'])) {
                $formatted .= "<b>Request:</b> {$req['method']} {$req['url']}\n";
            }
            if (isset($req['ip'])) {
                $formatted .= "<b>IP:</b> {$req['ip']}\n";
            }
            if (isset($req['user_id'])) {
                $formatted .= "<b>User ID:</b> {$req['user_id']}\n";
            }
            $formatted .= "\n";
        }

        // Add environment info
        $formatted .= '<b>Environment:</b> '.config('app.env')."\n";
        $formatted .= '<b>Server:</b> '.gethostname()."\n";
        $formatted .= '<b>Time:</b> '.now()->format('Y-m-d H:i:s');

        // Telegram message limit is 4096 characters
        return $this->truncate($formatted, 4000);
    }

    /**
     * Generate rate limit cache key
     */
    private function getRateLimitKey(string $message, array $context): string
    {
        $key = 'telegram_alert:';

        // Use exception class if available
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $key .= get_class($context['exception']).':';
        }

        // Use request URL if available
        if (isset($context['request']['url'])) {
            $key .= md5($context['request']['url']);
        } else {
            $key .= md5($message);
        }

        return $key;
    }

    /**
     * Truncate string to max length
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).'...';
    }
}
