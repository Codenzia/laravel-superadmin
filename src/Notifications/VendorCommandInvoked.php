<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

/**
 * Dispatched whenever a vendor-only command runs (install, reset).
 * Configure mail/slack recipients on the customer's deployment so you
 * receive real-time alerts of every invocation.
 */
final class VendorCommandInvoked extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $command,
        public readonly string $email,
        public readonly string $hostname,
        public readonly string $ipAddress,
        public readonly string $invokedBy,
        public readonly \DateTimeInterface $occurredAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        $channels = [];

        if (is_string(config('superadmin.notifications.mail_to'))
            && config('superadmin.notifications.mail_to') !== '') {
            $channels[] = 'mail';
        }

        if (is_string(config('superadmin.notifications.slack_webhook'))
            && config('superadmin.notifications.slack_webhook') !== '') {
            $channels[] = 'slack';
        }

        return $channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $appName = config('app.name', 'Application');

        return (new MailMessage)
            ->subject('[SECURITY] Super-admin vendor command invoked on '.$appName)
            ->level('warning')
            ->greeting('A vendor-only command was just invoked')
            ->line('**Command:** `'.$this->command.'`')
            ->line('**Application:** '.$appName)
            ->line('**Account:** '.$this->email)
            ->line('**Host:** '.$this->hostname)
            ->line('**IP Address:** '.$this->ipAddress)
            ->line('**Invoked by (OS user):** '.$this->invokedBy)
            ->line('**Timestamp:** '.$this->occurredAt->format('Y-m-d H:i:s T'))
            ->line('If this was not authorized, investigate immediately. Rotate credentials and audit recent activity.');
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->warning()
            ->content(':rotating_light: Super-admin vendor command invoked on *'.config('app.name', 'Application').'*')
            ->attachment(function ($attachment): void {
                $attachment
                    ->title('Invocation details')
                    ->fields([
                        'Command' => $this->command,
                        'Account' => $this->email,
                        'Host' => $this->hostname,
                        'IP' => $this->ipAddress,
                        'OS User' => $this->invokedBy,
                        'Timestamp' => $this->occurredAt->format('Y-m-d H:i:s T'),
                    ]);
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'command' => $this->command,
            'email' => $this->email,
            'hostname' => $this->hostname,
            'ip_address' => $this->ipAddress,
            'invoked_by' => $this->invokedBy,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
