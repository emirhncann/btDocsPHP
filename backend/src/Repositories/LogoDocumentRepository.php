<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\LogoSchema;
use App\Services\LogoDbConnection;
use PDO;

final class LogoDocumentRepository
{
    public function __construct(
        private readonly LogoDbConnection $logoDb,
        private readonly LogoSchema $schema,
    ) {
    }

    /**
     * @return list<array{cari_kod:string,cari_unvan:string,vkn:?string,adres:string}>
     */
    public function searchCari(int $firmaNo, int $donemNo, string $query): array
    {
        // Cari kart tablosu dönemden bağımsızdır.
        unset($donemNo);

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $table = $this->schema->quoteIdent($this->schema->clcardTable($firmaNo));
        $term = '%' . $this->escapeLike($query) . '%';
        // VKN/TCKN aramasında ara boşlukları yoksay (örn. "223 665 01274")
        $compact = preg_replace('/\s+/u', '', $query) ?? $query;
        $compactTerm = '%' . $this->escapeLike($compact) . '%';

        // GO3DEMO CLCARD: vergi no kolonu TAXNR (VERGINO yok), şahıs için TCKNO.
        // Logo'da ACTIVE = 0 genelde "aktif" anlamına gelir.
        $sql = "SELECT TOP 30
                    c.CODE AS cari_kod,
                    c.DEFINITION_ AS cari_unvan,
                    NULLIF(LTRIM(RTRIM(COALESCE(
                        NULLIF(LTRIM(RTRIM(c.TAXNR)), ''),
                        NULLIF(LTRIM(RTRIM(c.TCKNO)), '')
                    ))), '') AS vkn,
                    c.ADDR1 AS adres1,
                    c.ADDR2 AS adres2,
                    c.CITY AS sehir
                FROM {$table} c
                WHERE ISNULL(c.ACTIVE, 0) = 0
                  AND (
                        LTRIM(RTRIM(c.CODE)) LIKE :q1
                     OR LTRIM(RTRIM(c.DEFINITION_)) LIKE :q2
                     OR LTRIM(RTRIM(c.TAXNR)) LIKE :q3
                     OR LTRIM(RTRIM(c.TCKNO)) LIKE :q4
                     OR REPLACE(LTRIM(RTRIM(ISNULL(c.TAXNR, ''))), ' ', '') LIKE :q5
                     OR REPLACE(LTRIM(RTRIM(ISNULL(c.TCKNO, ''))), ' ', '') LIKE :q6
                  )
                ORDER BY c.CODE";

        $stmt = $this->logoDb->getConnection()->prepare($sql);
        $stmt->execute([
            'q1' => $term,
            'q2' => $term,
            'q3' => $term,
            'q4' => $term,
            'q5' => $compactTerm,
            'q6' => $compactTerm,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $adres = trim(implode(' ', array_filter([
                (string) ($row['adres1'] ?? ''),
                (string) ($row['adres2'] ?? ''),
                (string) ($row['sehir'] ?? ''),
            ], static fn (string $p): bool => trim($p) !== '')));

            $vkn = isset($row['vkn']) ? trim((string) $row['vkn']) : '';

            return [
                'cari_kod' => (string) ($row['cari_kod'] ?? ''),
                'cari_unvan' => (string) ($row['cari_unvan'] ?? ''),
                'vkn' => $vkn !== '' ? $vkn : null,
                'adres' => $adres,
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchDocuments(
        int $firmaNo,
        int $donemNo,
        ?string $belgeTipi = null,
        ?string $cariKod = null,
        ?string $belgeNo = null,
        ?string $tarihBaslangic = null,
        ?string $tarihBitis = null,
    ): array {
        $types = ($belgeTipi !== null && $belgeTipi !== '')
            ? [$belgeTipi]
            : LogoSchema::BELGE_TIPLERI;

        $results = [];
        foreach ($types as $tip) {
            $results = array_merge(
                $results,
                $this->searchDocumentsByType(
                    $firmaNo,
                    $donemNo,
                    $tip,
                    $cariKod,
                    $belgeNo,
                    $tarihBaslangic,
                    $tarihBitis,
                )
            );
        }

        if (count($types) > 1) {
            usort($results, static function (array $a, array $b): int {
                $da = (string) ($a['tarih'] ?? '');
                $db = (string) ($b['tarih'] ?? '');
                if ($da !== $db) {
                    return $db <=> $da;
                }

                return strcmp((string) ($b['belge_no'] ?? ''), (string) ($a['belge_no'] ?? ''));
            });
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchDocumentsByType(
        int $firmaNo,
        int $donemNo,
        string $belgeTipi,
        ?string $cariKod = null,
        ?string $belgeNo = null,
        ?string $tarihBaslangic = null,
        ?string $tarihBitis = null,
    ): array {
        $this->assertBelgeTipi($belgeTipi);

        $header = $this->schema->quoteIdent($this->schema->headerTable($firmaNo, $donemNo, $belgeTipi));
        $clcard = $this->schema->quoteIdent($this->schema->clcardTable($firmaNo));
        $trCodes = $this->schema->trCodes($belgeTipi);

        $sql = "SELECT TOP 100
                    h.LOGICALREF AS logo_logicalref,
                    h.FICHENO AS belge_no,
                    h.DATE_ AS tarih,
                    COALESCE(h.NETTOTAL, h.GROSSTOTAL, 0) AS tutar,
                    c.CODE AS cari_kod,
                    c.DEFINITION_ AS cari_unvan
                FROM {$header} h
                LEFT JOIN {$clcard} c ON c.LOGICALREF = h.CLIENTREF
                WHERE ISNULL(h.CANCELLED, 0) = 0";

        $params = [];

        if ($trCodes !== []) {
            $placeholders = [];
            foreach ($trCodes as $i => $code) {
                $key = 'tr' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $code;
            }
            $sql .= ' AND h.TRCODE IN (' . implode(', ', $placeholders) . ')';
        }

        if ($cariKod !== null && $cariKod !== '') {
            $sql .= ' AND c.CODE = :cari_kod';
            $params['cari_kod'] = $cariKod;
        }

        if ($belgeNo !== null && $belgeNo !== '') {
            $sql .= ' AND h.FICHENO LIKE :belge_no';
            $params['belge_no'] = '%' . $this->escapeLike($belgeNo) . '%';
        }

        if ($tarihBaslangic !== null && $tarihBaslangic !== '') {
            $sql .= ' AND CONVERT(date, h.DATE_) >= :tarih_baslangic';
            $params['tarih_baslangic'] = $tarihBaslangic;
        }

        if ($tarihBitis !== null && $tarihBitis !== '') {
            $sql .= ' AND CONVERT(date, h.DATE_) <= :tarih_bitis';
            $params['tarih_bitis'] = $tarihBitis;
        }

        $sql .= ' ORDER BY h.DATE_ DESC, h.FICHENO DESC';

        $stmt = $this->logoDb->getConnection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row) use ($belgeTipi): array {
            return [
                'logo_logicalref' => (int) $row['logo_logicalref'],
                'belge_tipi' => $belgeTipi,
                'belge_no' => (string) ($row['belge_no'] ?? ''),
                'cari_kod' => (string) ($row['cari_kod'] ?? ''),
                'cari_unvan' => (string) ($row['cari_unvan'] ?? ''),
                'tarih' => self::formatDate($row['tarih'] ?? null),
                'tutar' => (float) ($row['tutar'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDocumentDetail(
        int $firmaNo,
        int $donemNo,
        int $logicalRef,
        string $belgeTipi,
    ): ?array {
        $this->assertBelgeTipi($belgeTipi);

        $header = $this->schema->quoteIdent($this->schema->headerTable($firmaNo, $donemNo, $belgeTipi));
        $line = $this->schema->quoteIdent($this->schema->lineTable($firmaNo, $donemNo, $belgeTipi));
        $clcard = $this->schema->quoteIdent($this->schema->clcardTable($firmaNo));
        $items = $this->schema->quoteIdent($this->schema->itemsTable($firmaNo));

        $fkCol = $this->schema->lineHeaderFkColumn($belgeTipi);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $fkCol)) {
            throw new \InvalidArgumentException('Geçersiz satır FK kolonu');
        }

        $headerSql = "SELECT TOP 1
                        h.LOGICALREF AS logo_logicalref,
                        h.FICHENO AS belge_no,
                        h.DATE_ AS tarih,
                        h.CAPIBLOCK_CREADEDDATE AS logo_created_at,
                        COALESCE(h.TOTALVAT, 0) AS kdv_tutari,
                        COALESCE(h.NETTOTAL, h.GROSSTOTAL, 0) AS genel_toplam,
                        c.CODE AS cari_kod,
                        c.DEFINITION_ AS cari_unvan,
                        c.ADDR1 AS adres1,
                        c.ADDR2 AS adres2,
                        c.CITY AS sehir
                      FROM {$header} h
                      LEFT JOIN {$clcard} c ON c.LOGICALREF = h.CLIENTREF
                      WHERE h.LOGICALREF = :logicalref
                        AND ISNULL(h.CANCELLED, 0) = 0";

        // Fatura: CAPIBLOCK_CREADEDDATE INVOICE'dan (A3 key STFICHE; INVOICEREF ile bağlanır)
        if ($belgeTipi === 'fatura') {
            $invoice = $this->schema->quoteIdent($this->schema->invoiceTable($firmaNo, $donemNo));
            $headerSql = "SELECT TOP 1
                            h.LOGICALREF AS logo_logicalref,
                            h.FICHENO AS belge_no,
                            h.DATE_ AS tarih,
                            COALESCE(inv.CAPIBLOCK_CREADEDDATE, h.CAPIBLOCK_CREADEDDATE) AS logo_created_at,
                            COALESCE(h.TOTALVAT, 0) AS kdv_tutari,
                            COALESCE(h.NETTOTAL, h.GROSSTOTAL, 0) AS genel_toplam,
                            c.CODE AS cari_kod,
                            c.DEFINITION_ AS cari_unvan,
                            c.ADDR1 AS adres1,
                            c.ADDR2 AS adres2,
                            c.CITY AS sehir
                          FROM {$header} h
                          LEFT JOIN {$invoice} inv ON inv.LOGICALREF = h.INVOICEREF
                          LEFT JOIN {$clcard} c ON c.LOGICALREF = h.CLIENTREF
                          WHERE h.LOGICALREF = :logicalref
                            AND ISNULL(h.CANCELLED, 0) = 0";
        }

        $stmt = $this->logoDb->getConnection()->prepare($headerSql);
        $stmt->execute(['logicalref' => $logicalRef]);
        $head = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($head === false) {
            return null;
        }

        // LINETYPE: 0 malzeme, 2 indirim, 4 hizmet, 6 set, 7 set detayı (bkz. LOGO_SCHEMA.md)
        $lineSql = "SELECT
                        l.LOGICALREF AS line_ref,
                        COALESCE(l.LINETYPE, 0) AS linetype,
                        COALESCE(l.PARENTLNREF, 0) AS parent_ln_ref,
                        COALESCE(i.NAME, l.LINEEXP, '') AS urun_adi,
                        COALESCE(l.AMOUNT, 0) AS miktar,
                        COALESCE(l.PRICE, 0) AS birim_fiyat,
                        COALESCE(l.TOTAL, 0) AS brut_tutar,
                        COALESCE(l.LINENET, l.TOTAL, 0) AS linenet,
                        COALESCE(l.DISTDISC, 0) AS dist_disc
                    FROM {$line} l
                    LEFT JOIN {$items} i ON i.LOGICALREF = l.STOCKREF
                    WHERE l.[{$fkCol}] = :logicalref
                    ORDER BY l.LOGICALREF";

        $lineStmt = $this->logoDb->getConnection()->prepare($lineSql);
        $lineStmt->execute(['logicalref' => $logicalRef]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $kalemler = $this->buildKalemlerFromLines($lines);

        $toplamTutar = 0.0;
        foreach ($kalemler as $kalem) {
            $toplamTutar += (float) ($kalem['tutar'] ?? 0);
        }

        $adres = trim(
            ((string) ($head['adres1'] ?? '')) . ' ' .
            ((string) ($head['adres2'] ?? '')) . ' ' .
            ((string) ($head['sehir'] ?? ''))
        );

        return [
            'logo_logicalref' => (int) $head['logo_logicalref'],
            'belge_tipi' => $belgeTipi,
            'belge_no' => (string) ($head['belge_no'] ?? ''),
            // DATE_ (iş tarihi) + CAPIBLOCK_CREADEDDATE saati — ayrı "saat" alanı yok
            'tarih' => self::formatDateWithLogoCreatedTime(
                $head['tarih'] ?? null,
                $head['logo_created_at'] ?? null,
            ),
            'cari' => [
                'kod' => (string) ($head['cari_kod'] ?? ''),
                'unvan' => (string) ($head['cari_unvan'] ?? ''),
                'adres' => $adres,
            ],
            'kalemler' => $kalemler,
            'toplam_tutar' => $toplamTutar,
            'kdv_tutari' => (float) ($head['kdv_tutari'] ?? 0),
            'genel_toplam' => (float) ($head['genel_toplam'] ?? 0),
        ];
    }

    /**
     * Logo STLINE/ORFLINE satırlarını API kalemlerine dönüştürür.
     * LINETYPE=2 (indirim) ayrı kalem olmaz; PARENTLNREF ile üst ürünün iskonto_tutari alanına yazılır.
     * PARENTLNREF yoksa ürün satırındaki DISTDISC kullanılır (Logo genelde ikisini birlikte doldurur).
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function buildKalemlerFromLines(array $lines): array
    {
        /** @var array<int, float> $discountByParent */
        $discountByParent = [];
        foreach ($lines as $row) {
            if ((int) ($row['linetype'] ?? 0) !== 2) {
                continue;
            }
            $parentRef = (int) ($row['parent_ln_ref'] ?? 0);
            if ($parentRef <= 0) {
                continue;
            }
            $discountByParent[$parentRef] =
                ($discountByParent[$parentRef] ?? 0.0) + (float) ($row['brut_tutar'] ?? 0);
        }

        $kalemler = [];
        $lastProductIndex = null;

        foreach ($lines as $row) {
            $linetype = (int) ($row['linetype'] ?? 0);

            if ($linetype === 2) {
                // PARENTLNREF boş ise bir önceki ürün satırına bağla (Logo sıra konvansiyonu).
                // DISTDISC zaten doluysa çift sayma.
                if (
                    (int) ($row['parent_ln_ref'] ?? 0) <= 0
                    && $lastProductIndex !== null
                    && (float) $kalemler[$lastProductIndex]['iskonto_tutari'] == 0.0
                ) {
                    $kalemler[$lastProductIndex]['iskonto_tutari'] =
                        (float) ($row['brut_tutar'] ?? 0);
                }
                continue;
            }

            if ($linetype === 7) {
                continue;
            }

            $lineRef = (int) ($row['line_ref'] ?? 0);
            $distDisc = (float) ($row['dist_disc'] ?? 0);
            $iskonto = array_key_exists($lineRef, $discountByParent)
                ? (float) $discountByParent[$lineRef]
                : $distDisc;

            $linenet = (float) ($row['linenet'] ?? 0);
            $brut = (float) ($row['brut_tutar'] ?? 0);
            $tutar = $linenet > 0 ? $linenet : max(0.0, $brut - $iskonto);

            $kalemler[] = [
                'urun_adi' => (string) ($row['urun_adi'] ?? ''),
                'miktar' => (float) ($row['miktar'] ?? 0),
                'birim_fiyat' => (float) ($row['birim_fiyat'] ?? 0),
                'iskonto_tutari' => $iskonto,
                'tutar' => $tutar,
            ];
            $lastProductIndex = array_key_last($kalemler);
        }

        return $kalemler;
    }

    private function assertBelgeTipi(string $belgeTipi): void
    {
        if (!in_array($belgeTipi, LogoSchema::BELGE_TIPLERI, true)) {
            throw new \InvalidArgumentException('Geçersiz belge_tipi');
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function formatDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? (string) $value : date('Y-m-d', $ts);
    }

    /**
     * İş tarihi (DATE_) + Logo oluşturma saati (CAPIBLOCK_CREADEDDATE).
     * Ayrı "saat" alanı dönülmez; örnek: "2026-08-07 14:32"
     */
    private static function formatDateWithLogoCreatedTime(mixed $dateValue, mixed $createdAt): ?string
    {
        $datePart = self::formatDate($dateValue);
        $timePart = self::formatTimeHm($createdAt);

        if ($datePart === null) {
            return $timePart;
        }
        if ($timePart === null) {
            return $datePart;
        }

        return $datePart . ' ' . $timePart;
    }

    private static function formatTimeHm(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        if ($value === null || $value === '') {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? null : date('H:i', $ts);
    }
}
