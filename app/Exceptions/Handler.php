<?php

namespace OGame\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use OGame\Services\DiscordNotifier;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Taps into the render function allowing us to send to discord.
     *
     * @param  Request  $request
     *
     * @throws Throwable
     */
    public function render($request, Exception|Throwable $e): Response
    {
        $this->sendToDiscord($e);

        return parent::render($request, $e);
    }

    /**
     * Send error to Discord, if webhook setup
     */
    protected function sendToDiscord(Throwable $e): void
    {
        $webhook = DiscordNotifier::webhookUrl();
        if ($webhook !== '' && $this->shouldReport($e)) {
            config(['discord-alerts.webhook_urls.default' => $webhook]);

            $stackTrace = explode("\n", $e->getTraceAsString());

            // Limit to the first two stack trace lines
            $limitedStackTrace = implode("\n", array_slice($stackTrace, 0, 2));

            $message = sprintf(
                '🚨 **Exception in %s environment**
                ```%s```',
                app()->environment(),
                sprintf(
                    "\nMessage: %s\nFile: %s (Line: %d)\nStack Trace:\n%s\n",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $limitedStackTrace
                )
            );

            try {
                DiscordAlert::message($message);
            } catch (Throwable) {
                // never let alerting break error rendering
            }
        }
    }
}
