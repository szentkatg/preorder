<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ADMIN_EMAIL = 'szega77@hotmail.com';

    private const GUARD_NAME = 'web';

    private const ROLE_NAME = 'super_admin';

    public function up(): void
    {
        $now = now();

        $roleId = DB::table('roles')
            ->where('name', self::ROLE_NAME)
            ->where('guard_name', self::GUARD_NAME)
            ->value('id');

        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => self::ROLE_NAME,
                'guard_name' => self::GUARD_NAME,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $userId = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [self::ADMIN_EMAIL])
            ->value('id');

        if (! $userId) {
            return;
        }

        DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $userId,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $userId = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [self::ADMIN_EMAIL])
            ->value('id');

        $roleId = DB::table('roles')
            ->where('name', self::ROLE_NAME)
            ->where('guard_name', self::GUARD_NAME)
            ->value('id');

        if ($userId && $roleId) {
            DB::table('model_has_roles')
                ->where('role_id', $roleId)
                ->where('model_type', User::class)
                ->where('model_id', $userId)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
