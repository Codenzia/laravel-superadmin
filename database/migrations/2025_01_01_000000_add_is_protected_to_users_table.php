<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'is_protected')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_protected')->default(false)->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_protected')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_protected']);
            $table->dropColumn('is_protected');
        });
    }
};
