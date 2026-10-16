<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengganti "approval admin" dengan verifikasi berbasis bukti.
     *
     * - auto_verify_transfers: transfer/QRIS manual yang buktinya lengkap
     *   (nomor referensi unik + file bukti) dan nilainya tidak melebihi sisa tagihan
     *   akan terverifikasi sendiri, tanpa menunggu admin membuka sistem.
     * - auto_verify_max_amount: plafon nilai per pembayaran yang boleh diverifikasi
     *   otomatis. 0 = tanpa plafon. Di atas plafon tetap butuh persetujuan admin,
     *   dan admin selalu punya jalur reversal (post-audit) untuk membatalkan.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('auto_verify_transfers')->default(true)->after('min_dp_percentage');
            $table->decimal('auto_verify_max_amount', 12, 2)->default(0)->after('auto_verify_transfers');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['auto_verify_transfers', 'auto_verify_max_amount']);
        });
    }
};
