<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reset link sent to the protected super admin's own mailbox by the
 * recovery route. Names the app and host that triggered it so an
 * unsolicited link doubles as a probe alert for the vendor.
 */
final class RecoveryLinkNotification extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name');
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'));
        $expires = (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire', 60);

        return (new MailMessage)
            ->subject(__('Super admin password reset — :app', ['app' => $appName]))
            ->line(__('A super admin password reset was requested for :app (:host).', ['app' => $appName, 'host' => $host]))
            ->action(__('Set a new password'), route('superadmin.recovery.form', ['token' => $this->token]))
            ->line(__('The link expires in :count minutes and can be used once.', ['count' => $expires]))
            ->line(__('If you did not request this, no action is needed — but someone may be probing the recovery route on this host.'));
    }
}
