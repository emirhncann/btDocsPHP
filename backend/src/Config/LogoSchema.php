<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Logo Unity / Tiger / GO tarzı dinamik tablo adlandırma.
 *
 * Gerçek şema müşteri kurulumuna göre değişebilir.
 * Kolon ve TRCODE listelerini .env üzerinden override edebilirsiniz.
 *
 * Varsayılan desenler:
 *   Cari   : LG_{FIRMA}_CLCARD
 *   Stok   : LG_{FIRMA}_ITEMS
 *   Sipariş: LG_{FIRMA}_{DONEM}_ORFICHE / ORFLINE
 *   İrsaliye/Fatura: LG_{FIRMA}_{DONEM}_STFICHE / STLINE
 */
final class LogoSchema
{
    public const BELGE_TIPLERI = ['siparis', 'irsaliye', 'fatura'];

    public function firmCode(int $firmaNo): string
    {
        if ($firmaNo < 1 || $firmaNo > 999) {
            throw new \InvalidArgumentException('firma_no 1-999 aralığında olmalı');
        }

        return str_pad((string) $firmaNo, 3, '0', STR_PAD_LEFT);
    }

    public function periodCode(int $donemNo): string
    {
        if ($donemNo < 1 || $donemNo > 99) {
            throw new \InvalidArgumentException('donem_no 1-99 aralığında olmalı');
        }

        return str_pad((string) $donemNo, 2, '0', STR_PAD_LEFT);
    }

    public function clcardTable(int $firmaNo): string
    {
        return $this->resolve(
            Settings::get('LOGO_TABLE_CLCARD', 'LG_{FIRMA}_CLCARD'),
            $firmaNo
        );
    }

    public function itemsTable(int $firmaNo): string
    {
        return $this->resolve(
            Settings::get('LOGO_TABLE_ITEMS', 'LG_{FIRMA}_ITEMS'),
            $firmaNo
        );
    }

    public function headerTable(int $firmaNo, int $donemNo, string $belgeTipi): string
    {
        $pattern = match ($belgeTipi) {
            'siparis' => Settings::get('LOGO_TABLE_SIPARIS_HEADER', 'LG_{FIRMA}_{DONEM}_ORFICHE'),
            'irsaliye' => Settings::get('LOGO_TABLE_IRSALIYE_HEADER', 'LG_{FIRMA}_{DONEM}_STFICHE'),
            'fatura' => Settings::get('LOGO_TABLE_FATURA_HEADER', 'LG_{FIRMA}_{DONEM}_STFICHE'),
            default => throw new \InvalidArgumentException('Geçersiz belge_tipi'),
        };

        return $this->resolve($pattern, $firmaNo, $donemNo);
    }

    public function lineTable(int $firmaNo, int $donemNo, string $belgeTipi): string
    {
        $pattern = match ($belgeTipi) {
            'siparis' => Settings::get('LOGO_TABLE_SIPARIS_LINE', 'LG_{FIRMA}_{DONEM}_ORFLINE'),
            'irsaliye' => Settings::get('LOGO_TABLE_IRSALIYE_LINE', 'LG_{FIRMA}_{DONEM}_STLINE'),
            'fatura' => Settings::get('LOGO_TABLE_FATURA_LINE', 'LG_{FIRMA}_{DONEM}_STLINE'),
            default => throw new \InvalidArgumentException('Geçersiz belge_tipi'),
        };

        return $this->resolve($pattern, $firmaNo, $donemNo);
    }

    /** Satırın başlığa FK kolon adı */
    public function lineHeaderFkColumn(string $belgeTipi): string
    {
        return $belgeTipi === 'siparis'
            ? (Settings::get('LOGO_COL_LINE_HEADER_FK_SIPARIS', 'ORDFICHEREF') ?? 'ORDFICHEREF')
            : (Settings::get('LOGO_COL_LINE_HEADER_FK_ST', 'STFICHEREF') ?? 'STFICHEREF');
    }

    /**
     * İrsaliye / fatura ayrımı için TRCODE listeleri (virgülle).
     * Siparişte genelde boş bırakılır (tüm ORFICHE).
     *
     * @return list<int>
     */
    public function trCodes(string $belgeTipi): array
    {
        $raw = match ($belgeTipi) {
            'siparis' => Settings::get('LOGO_TRCODES_SIPARIS', '') ?? '',
            'irsaliye' => Settings::get('LOGO_TRCODES_IRSALIYE', '6,7') ?? '6,7',
            'fatura' => Settings::get('LOGO_TRCODES_FATURA', '8,9') ?? '8,9',
            default => '',
        };

        $codes = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $codes[] = (int) $part;
            }
        }

        return $codes;
    }

    /**
     * Belge açıklama / not satırları: LG_{FIRMA}_{DONEM}_PERDOC
     */
    public function perdocTable(int $firmaNo, int $donemNo): string
    {
        return $this->resolve(
            Settings::get('LOGO_TABLE_PERDOC', 'LG_{FIRMA}_{DONEM}_PERDOC'),
            $firmaNo,
            $donemNo
        );
    }

    /**
     * Fatura başlık (fiş no) kaynağı: LG_{FIRMA}_{DONEM}_INVOICE
     */
    public function invoiceTable(int $firmaNo, int $donemNo): string
    {
        return $this->resolve(
            Settings::get('LOGO_TABLE_INVOICE', 'LG_{FIRMA}_{DONEM}_INVOICE'),
            $firmaNo,
            $donemNo
        );
    }

    /**
     * PERDOC.INFOTYP — siparis=5; fatura/irsaliye=7 (Logo fatura notu INFOTYP=7 + INVOICE INFOREF)
     */
    public function perdocInfoTyp(string $belgeTipi): int
    {
        return match ($belgeTipi) {
            'siparis' => 5,
            'fatura', 'irsaliye' => 7,
            default => throw new \InvalidArgumentException('Geçersiz belge_tipi'),
        };
    }

    /** Metin notu: canlı Logo UI'da DOCTYP=3 */
    public function perdocDocTyp(): int
    {
        return 3;
    }

    /** Not sıra no: canlı Logo UI'da DOCNR=1 */
    public function perdocDocNr(): int
    {
        return 1;
    }

    public function quoteIdent(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Geçersiz SQL identifier: ' . $identifier);
        }

        return '[' . $identifier . ']';
    }

    private function resolve(?string $pattern, int $firmaNo, ?int $donemNo = null): string
    {
        $pattern = $pattern ?? '';

        // Env pattern sadece alfanümerik + _{ } içerebilir (injection engeli).
        if ($pattern === '' || !preg_match('/^[A-Za-z0-9_{}]+$/', $pattern)) {
            throw new \InvalidArgumentException('Geçersiz Logo tablo deseni');
        }

        // firma/donem zaten sayısal aralık kontrolünden geçti; ekstra whitelist.
        $firma = $this->firmCode($firmaNo);
        $donem = $donemNo !== null ? $this->periodCode($donemNo) : '';
        if (!ctype_digit($firma) || ($donem !== '' && !ctype_digit($donem))) {
            throw new \InvalidArgumentException('Geçersiz firma/dönem kodu');
        }

        $resolved = str_replace(
            ['{FIRMA}', '{DONEM}'],
            [$firma, $donem],
            $pattern
        );

        // Süslü parantez kalmamalı; sadece güvenli identifier.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $resolved)) {
            throw new \InvalidArgumentException('Üretilen tablo adı geçersiz');
        }

        return $resolved;
    }
}