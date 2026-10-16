<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kamar tidak bisa dijual pada tanggal yang diminta (double booking, masih occupied,
 * atau sedang maintenance).
 *
 * AGENTS.md bagian 6 butir 1 mewajibkan jawaban HTTP 409 untuk konflik ketersediaan,
 * supaya frontend bisa membedakannya dari kegagalan validasi biasa (422).
 */
class RoomUnavailableException extends RuntimeException
{
}
