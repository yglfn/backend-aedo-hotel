<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur payment gateway dihentikan sepenuhnya.
 *
 * Migrasi ini membersihkan database kerja yang sudah punya struktur gateway:
 *  - tabel payment_gateway_logs dibuang,
 *  - kolom peninggalan gateway di payments & settings di-drop,
 *  - baris pembayaran bermetode payment_gateway dihapus,
 *  - invoice yang tadi ditandai lunas karena pembayaran gateway itu diturunkan
 *    kembali ke draft supaya tidak ada tagihan "lunas" tanpa uang yang tercatat.
 *
 * Semua langkah dijaga hasTable/hasColumn: pada instalasi baru (migrate:fresh) kolom
 * gateway tidak pernah dibuat, jadi migrasi ini harus tetap aman dijalankan.
 * Data pembayaran gateway yang sudah dihapus tidak dapat dikembalikan oleh down().
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const PAYMENT_COLUMNS = [
        'provider',
        'gateway_transaction_id',
        'channel',
        'gateway_payload',
        'expires_at',
    ];

    /** @var array<int, string> */
    private const SETTING_COLUMNS = [
        'payment_gateway_provider',
        'payment_gateway_config',
        'payment_expiry_minutes',
    ];

    public function up(): void
    {
        // 1. Tabel log dibuang lebih dulu: ia punya FK ke payments, jadi lebih aman
        //    dihapus sebelum baris pembayaran di dalamnya ikut dibersihkan.
        if (Schema::hasTable('payment_gateway_logs')) {
            Schema::dropIfExists('payment_gateway_logs');
        }

        if (!Schema::hasTable('payments')) {
            return;
        }

        // 2. Hapus pembayaran gateway, lalu ingat invoice yang pernah ia lunasi.
        $gatewayPaymentIds = DB::table('payments')
            ->where('method', 'payment_gateway')
            ->pluck('id')
            ->all();

        $invoiceIds = DB::table('payments')
            ->where('method', 'payment_gateway')
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id')
            ->unique()
            ->all();

        if ($gatewayPaymentIds !== []) {
            DB::table('payments')->whereIn('id', $gatewayPaymentIds)->delete();
        }

        // 3. Invoice "paid" tanpa lagi pembayaran verified yang menutup total -> kembali ke draft.
        foreach ($invoiceIds as $invoiceId) {
            DB::table('invoices')
                ->where('id', $invoiceId)
                ->where('status', 'paid')
                ->update(['status' => 'draft']);
        }

        // 4. Buang kolom gateway.
        $drop = array_values(array_filter(
            self::PAYMENT_COLUMNS,
            fn (string $column) => Schema::hasColumn('payments', $column)
        ));

        if ($drop !== []) {
            Schema::table('payments', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }

        if (Schema::hasTable('settings')) {
            $dropSettings = array_values(array_filter(
                self::SETTING_COLUMNS,
                fn (string $column) => Schema::hasColumn('settings', $column)
            ));

            if ($dropSettings !== []) {
                Schema::table('settings', function (Blueprint $table) use ($dropSettings) {
                    $table->dropColumn($dropSettings);
                });
            }
        }
    }

    public function down(): void
    {
        // Struktur dikembalikan agar rollback tidak gagal; datanya sudah hilang.
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (!Schema::hasColumn('payments', 'provider')) {
                    $table->string('provider')->nullable();
                }
                if (!Schema::hasColumn('payments', 'gateway_transaction_id')) {
                    $table->string('gateway_transaction_id')->nullable();
                    $table->index('gateway_transaction_id');
                }
                if (!Schema::hasColumn('payments', 'channel')) {
                    $table->string('channel')->nullable();
                }
                if (!Schema::hasColumn('payments', 'gateway_payload')) {
                    $table->json('gateway_payload')->nullable();
                }
                if (!Schema::hasColumn('payments', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                if (!Schema::hasColumn('settings', 'payment_gateway_provider')) {
                    $table->string('payment_gateway_provider')->nullable();
                }
                if (!Schema::hasColumn('settings', 'payment_gateway_config')) {
                    $table->text('payment_gateway_config')->nullable();
                }
                if (!Schema::hasColumn('settings', 'payment_expiry_minutes')) {
                    $table->integer('payment_expiry_minutes')->default(60);
                }
            });
        }

        if (!Schema::hasTable('payment_gateway_logs')) {
            Schema::create('payment_gateway_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->nullable()->constrained()->onDelete('set null');
                $table->string('provider');
                $table->string('event_type');
                $table->longText('payload');
                $table->json('headers')->nullable();
                $table->boolean('signature_valid')->default(false);
                $table->boolean('processed')->default(false);
                $table->text('error_message')->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }
};
