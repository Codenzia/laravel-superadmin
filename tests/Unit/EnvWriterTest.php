<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Support\EnvWriter;

beforeEach(function (): void {
    $this->envPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'superadmin-env-'.uniqid('', true);
});

afterEach(function (): void {
    if (isset($this->envPath) && is_file($this->envPath)) {
        @unlink($this->envPath);
    }
});

it('creates the .env file when missing and writes a single key', function (): void {
    expect(is_file($this->envPath))->toBeFalse();

    $changed = EnvWriter::setMany($this->envPath, ['SUPER_ADMIN_EMAIL' => 'a@b.com']);

    expect($changed)->toBe(['SUPER_ADMIN_EMAIL']);
    expect(file_get_contents($this->envPath))->toBe("SUPER_ADMIN_EMAIL=a@b.com\n");
});

it('replaces an existing key in-place without disturbing other lines', function (): void {
    file_put_contents($this->envPath, "APP_NAME=MyApp\nSUPER_ADMIN_EMAIL=old@b.com\nDB_DATABASE=foo\n");

    EnvWriter::setMany($this->envPath, ['SUPER_ADMIN_EMAIL' => 'new@b.com']);

    expect(file_get_contents($this->envPath))
        ->toBe("APP_NAME=MyApp\nSUPER_ADMIN_EMAIL=new@b.com\nDB_DATABASE=foo\n");
});

it('appends a missing key at the end without trimming existing content', function (): void {
    file_put_contents($this->envPath, "APP_NAME=MyApp\n# comment\nDB_DATABASE=foo\n");

    EnvWriter::setMany($this->envPath, ['SUPER_ADMIN_PASSWORD' => 'pw']);

    expect(file_get_contents($this->envPath))
        ->toBe("APP_NAME=MyApp\n# comment\nDB_DATABASE=foo\nSUPER_ADMIN_PASSWORD=pw\n");
});

it('handles multiple pairs in one call (mix of update + append)', function (): void {
    file_put_contents($this->envPath, "APP_NAME=MyApp\nSUPER_ADMIN_EMAIL=old@b.com\n");

    EnvWriter::setMany($this->envPath, [
        'SUPER_ADMIN_EMAIL' => 'new@b.com',
        'SUPER_ADMIN_PASSWORD' => 'pw',
    ]);

    expect(file_get_contents($this->envPath))
        ->toBe("APP_NAME=MyApp\nSUPER_ADMIN_EMAIL=new@b.com\nSUPER_ADMIN_PASSWORD=pw\n");
});

it('quotes values containing spaces, #, or quotes', function (): void {
    EnvWriter::setMany($this->envPath, [
        'WITH_SPACE' => 'hello world',
        'WITH_HASH' => 'foo#bar',
        'WITH_QUOTE' => 'she said "hi"',
        'PLAIN' => 'simple',
    ]);

    $out = file_get_contents($this->envPath);

    expect($out)->toContain('WITH_SPACE="hello world"');
    expect($out)->toContain('WITH_HASH="foo#bar"');
    expect($out)->toContain('WITH_QUOTE="she said \\"hi\\""');
    expect($out)->toContain('PLAIN=simple');
});

it('preserves CRLF line endings on a Windows-style file', function (): void {
    file_put_contents($this->envPath, "APP_NAME=MyApp\r\nSUPER_ADMIN_EMAIL=old@b.com\r\n");

    EnvWriter::setMany($this->envPath, ['SUPER_ADMIN_EMAIL' => 'new@b.com']);

    expect(file_get_contents($this->envPath))
        ->toBe("APP_NAME=MyApp\r\nSUPER_ADMIN_EMAIL=new@b.com\r\n");
});

it('returns an empty array when no key actually changes', function (): void {
    file_put_contents($this->envPath, "SUPER_ADMIN_EMAIL=a@b.com\n");

    $changed = EnvWriter::setMany($this->envPath, ['SUPER_ADMIN_EMAIL' => 'a@b.com']);

    expect($changed)->toBe([]);
});

it('encodes nullable / boolean / numeric values cleanly', function (): void {
    EnvWriter::setMany($this->envPath, [
        'A_NULL' => null,
        'A_TRUE' => true,
        'A_FALSE' => false,
        'A_INT' => 42,
    ]);

    $out = file_get_contents($this->envPath);

    expect($out)->toContain('A_NULL=""');
    expect($out)->toContain('A_TRUE=true');
    expect($out)->toContain('A_FALSE=false');
    expect($out)->toContain('A_INT=42');
});
