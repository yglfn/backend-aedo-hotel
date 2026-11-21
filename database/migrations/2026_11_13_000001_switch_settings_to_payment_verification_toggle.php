<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verifikasi pembayaran kini dikendalikan satu saklar:
     * - true  : transfer/QRIS menunggu persetujuan admin.
     * - false : transfer/QRIS langsung dianggap lunas saat dicatat (tanpa bukti).
     *
     * Nilai lama dipindahkan agar perilaku instalasi yang sudah berjalan tidak berubah:
     * auto_verify_transfers = true berarti sistem boleh melunasi sendiri,
     * sehingga padanannya adalah payment_verification_enabled = false.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('payment_verification_enabled')->default(true)->after('min_dp_percentage');
        });

        DB::table('settings')->update([
            'payment_verification_enabled' => DB::raw('CASE WHEN auto_verify_transfers = 1 THEN 0 ELSE 1 END'),
        ]);

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['auto_verify_transfers', 'auto_verify_max_amount']);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('auto_verify_transfers')->default(true)->after('min_dp_percentage');
            $table->decimal('auto_verify_max_amount', 12, 2)->default(0)->after('auto_verify_transfers');
        });

        DB::table('settings')->update([
            'auto_verify_transfers' => DB::raw('CASE WHEN payment_verification_enabled = 1 THEN 0 ELSE 1 END'),
        ]);

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('payment_verification_enabled');
        });
    }
};
