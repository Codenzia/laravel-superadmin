<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\SpatieTestCase;
use Codenzia\SuperAdmin\Tests\TestCase;
use Illuminate\Support\Facades\Hash;

uses(TestCase::class)->in('Feature', 'Unit');
uses(SpatieTestCase::class)->in('Spatie');

function createProtectedSuperAdmin(string $email = 'superadmin@aqarkom.test', string $password = 'super-secret-pw-12345'): User
{
    return SuperAdmin::withoutProtection(fn (): User => User::query()->create([
        'name' => 'Super Admin',
        'email' => $email,
        'password' => Hash::make($password),
        'email_verified_at' => now(),
        'is_protected' => true,
    ]));
}

function createUser(string $email = 'regular@aqarkom.test'): User
{
    return User::query()->create([
        'name' => 'Regular User',
        'email' => $email,
        'password' => Hash::make('password-1234'),
        'email_verified_at' => now(),
        'is_protected' => false,
    ]);
}

/**
 * Captures raw `fwrite(STDOUT, ...)` output produced while `$callback` runs
 * (e.g. the auto-install hook's CLI messages), which normal output
 * buffering / `expectOutputString()` cannot see since it bypasses PHP's
 * output buffer entirely. Uses a stream filter appended directly to the
 * STDOUT resource.
 */
function captureStdout(callable $callback): string
{
    static $registered = false;

    if (! $registered) {
        stream_filter_register('superadmin-test-stdout-capture', StdoutCaptureFilter::class);
        $registered = true;
    }

    StdoutCaptureFilter::$buffer = '';
    $filter = stream_filter_append(STDOUT, 'superadmin-test-stdout-capture');

    try {
        $callback();
    } finally {
        stream_filter_remove($filter);
    }

    return StdoutCaptureFilter::$buffer;
}

final class StdoutCaptureFilter extends php_user_filter
{
    public static string $buffer = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$buffer .= $bucket->data;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
