<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use App\Repositories\AccessTokenRepository;

/**
 * Public /belge erişiminde ileride zorunlu token kontrolü için hazır katman.
 * Varsayılan: zorunlu değil (PUBLIC_ACCESS_TOKEN_REQUIRED=false).
 */
final class PublicAccessGuard
{
    public function __construct(
        private readonly AccessTokenRepository $accessTokens,
    ) {
    }

    public function isTokenRequired(): bool
    {
        $value = strtolower((string) (Settings::get('PUBLIC_ACCESS_TOKEN_REQUIRED', 'false') ?? 'false'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return null|string Hata mesajı; null = geçiş OK
     */
    public function validate(?string $token, int $documentId): ?string
    {
        // Şimdilik pas: zorunluluk kapalıysa token kontrol edilmez.
        if (!$this->isTokenRequired()) {
            return null;
        }

        if ($token === null || $token === '') {
            return 'Bu belgeye erişim için geçerli bir bağlantı (token) gerekir.';
        }

        if (!$this->accessTokens->isValidForDocument($token, $documentId)) {
            return 'Bağlantı geçersiz, süresi dolmuş veya iptal edilmiş.';
        }

        return null;
    }
}
