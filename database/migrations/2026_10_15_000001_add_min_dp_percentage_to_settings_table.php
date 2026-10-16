<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DP minimum sebagai persentase dari total tagihan.
     *
     * Dipakai untuk mengubah status reservasi pending -> confirmed:
     * otomatis saat pembayaran terverifikasi sudah mencapai ambang,
     * atau lewat konfirmasi manual admin (yang boleh melewati ambang).
     * Default 0 = hotel tidak memaksa DP, perilaku lama tetap jalan.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('min_dp_percentage', 5, 2)->default(0)->after('tax_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('min_dp_percentage');
        });
    }
};
