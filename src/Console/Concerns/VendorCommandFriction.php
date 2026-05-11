<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Concerns;

use Carbon\Carbon;
use Codenzia\SuperAdmin\Notifications\VendorCommandInvoked;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Friction layer applied to vendor-only commands. Not cryptographic
 * security: a determined actor with shell access can bypass anything
 * here. Its purpose is to (a) prevent accidental invocation, (b) make
 * casual misuse harder, and (c) ensure every successful invocation is
 * loudly audited so the vendor learns about it within minutes.
 */
trait VendorCommandFriction
{
    protected function applyFriction(): bool
    {
        if (! $this->checkConfirmFlag()) {
            return false;
        }

        if (! $this->checkTypedPhrase()) {
            return false;
        }

        return true;
    }

    protected function announceInvocation(string $email): void
    {
        $this->logInvocation($email);
        $this->notifyInvocation($email);
    }

    protected function shouldHideFromList(): bool
    {
        return (bool) config('superadmin.vendor_commands.hide_from_list', true);
    }

    private function checkConfirmFlag(): bool
    {
        if (! (bool) config('superadmin.vendor_commands.require_confirm_flag', true)) {
            return true;
        }

        if ($this->option('confirm')) {
            return true;
        }

        $this->error('This is a vendor-only command. Re-run with --confirm if you are authorized.');
        $this->warn('Every invocation of this command is logged and notifies the package vendor.');

        return false;
    }

    private function checkTypedPhrase(): bool
    {
        if (! (bool) config('superadmin.vendor_commands.require_typed_phrase', true)) {
            return true;
        }

        $expected = (string) config('superadmin.vendor_commands.typed_phrase', 'I AM THE VENDOR');

        $typed = $this->ask('Type the following phrase exactly to proceed: "'.$expected.'"');

        if ($typed !== $expected) {
            $this->error('Phrase did not match. Aborting.');

            return false;
        }

        return true;
    }

    private function logInvocation(string $email): void
    {
        $channel = config('superadmin.log_channel') ?? config('logging.default');

        Log::channel($channel)->warning('Super admin vendor command invoked', [
            'command' => $this->signature ?? static::class,
            'email' => $email,
            'hostname' => gethostname(),
            'invoked_by' => get_current_user() ?: 'unknown',
            'php_user' => get_current_user(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function notifyInvocation(string $email): void
    {
        if (! (bool) config('superadmin.vendor_commands.notify_on_invocation', true)) {
            return;
        }

        if (! (bool) config('superadmin.notifications.enabled', true)) {
            return;
        }

        $mail = config('superadmin.notifications.mail_to');
        $slack = config('superadmin.notifications.slack_webhook');

        if (! is_string($mail) && ! is_string($slack)) {
            return;
        }

        $notification = new VendorCommandInvoked(
            command: (string) ($this->signature ?? static::class),
            email: $email,
            hostname: (string) gethostname(),
            ipAddress: $this->resolveIp(),
            invokedBy: get_current_user() ?: 'unknown',
            occurredAt: Carbon::now(),
        );

        $pending = null;

        if (is_string($mail) && $mail !== '') {
            $recipients = array_values(array_filter(array_map('trim', explode(',', $mail))));

            if ($recipients !== []) {
                $pending = Notification::route('mail', count($recipients) === 1 ? $recipients[0] : $recipients);
            }
        }

        if (is_string($slack) && $slack !== '') {
            $pending = $pending !== null
                ? $pending->route('slack', $slack)
                : Notification::route('slack', $slack);
        }

        $pending?->notify($notification);
    }

    private function resolveIp(): string
    {
        $hostname = gethostname();

        if ($hostname === false) {
            return 'unknown';
        }

        $ip = gethostbyname($hostname);

        return $ip !== $hostname ? $ip : 'unknown';
    }
}
