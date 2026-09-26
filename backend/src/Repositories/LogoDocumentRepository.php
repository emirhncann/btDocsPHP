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

        usort($results, static function (array $a, array $b): int {
            $da = (string) ($a['tarih'] ?? '');
            $db = (string) ($b['tarih'] ?? '');
            if ($da !== $db) {
                return $db <=> $da;
            }

            $sa = (int) ($a['sort_ref'] ?? $a['logo_logicalref'] ?? 0);
            $sb = (int) ($b['sort_ref'] ?? $b['logo_logicalref'] ?? 0);

            return $sb <=> $sa;
        });

        foreach ($results as &$row) {
            unset($row['sort_ref']);
        }
        unset($row);

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

        $clcard = $this->schema->quoteIdent($this->schema->clcardTable($firmaNo));
        $trCodes = $this->schema->trCodes($belgeTipi);
        $params = [];

        if ($belgeTipi === 'fatura') {
            // Fatura listesi yalnızca INVOICE: logo_logicalref = INVOICE.LOGICALREF
            // TOP yok — bugün/dün/tümü filtresindeki tüm kayıtlar gelir.
            $invoice = $this->schema->quoteIdent($this->schema->invoiceTable($firmaNo, $donemNo));
            $sql = "SELECT
                        inv.LOGICALREF AS logo_logicalref,
                        inv.LOGICALREF AS sort_ref,
                        inv.FICHENO AS fis_no,
                        inv.DOCODE AS doc_no,
                        inv.FICHENO AS belge_no,
                        inv.DATE_ AS tarih,
                        COALESCE(inv.NETTOTAL, inv.GROSSTOTAL, 0) AS tutar,
                        c.CODE AS cari_kod,
                        c.DEFINITION_ AS cari_unvan
                    FROM {$invoice} inv
                    LEFT JOIN {$clcard} c ON c.LOGICALREF = inv.CLIENTREF
                    WHERE ISNULL(inv.CANCELLED, 0) = 0";
            $dateCol = 'inv.DATE_';
            $ficheCol = 'inv.FICHENO';
            $docCol = 'inv.DOCODE';
            $trCol = 'inv.TRCODE';
            $orderCol = 'inv.DATE_ DESC, inv.LOGICALREF DESC';
        } else {
            $header = $this->schema->quoteIdent($this->schema->headerTable($firmaNo, $donemNo, $belgeTipi));
            $sql = "SELECT TOP 100
                        h.LOGICALREF AS logo_logicalref,
                        h.LOGICALREF AS sort_ref,
                        h.FICHENO AS fis_no,
                        h.DOCODE AS doc_no,
                        h.FICHENO AS belge_no,
                        h.DATE_ AS tarih,
                        COALESCE(h.NETTOTAL, h.GROSSTOTAL, 0) AS tutar,
                        c.CODE AS cari_kod,
                        c.DEFINITION_ AS cari_unvan
                    FROM {$header} h
                    LEFT JOIN {$clcard} c ON c.LOGICALREF = h.CLIENTREF
                    WHERE ISNULL(h.CANCELLED, 0) = 0";
            $dateCol = 'h.DATE_';
            $ficheCol = 'h.FICHENO';
            $docCol = 'h.DOCODE';
            $trCol = 'h.TRCODE';
            $orderCol = 'h.DATE_ DESC, h.LOGICALREF DESC';
        }

        if ($trCodes !== []) {
            $placeholders = [];
            foreach ($trCodes as $i => $code) {
                $key = 'tr' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $code;
            }
            $sql .= ' AND ' . $trCol . ' IN (' . implode(', ', $placeholders) . ')';
        }

        if ($cariKod !== null && $cariKod !== '') {
            $sql .= ' AND c.CODE = :cari_kod';
            $params['cari_kod'] = $cariKod;
        }

        if ($belgeNo !== null && $belgeNo !== '') {
            $sql .= ' AND (' . $ficheCol . ' LIKE :belge_no OR ' . $docCol . ' LIKE :belge_no_doc)';
            $like = '%' . $this->escapeLike($belgeNo) . '%';
            $params['belge_no'] = $like;
            $params['belge_no_doc'] = $like;
        }

        if ($tarihBaslangic !== null && $tarihBaslangic !== '') {
            $sql .= ' AND CONVERT(date, ' . $dateCol . ') >= :tarih_baslangic';
            $params['tarih_baslangic'] = $tarihBaslangic;
        }

        if ($tarihBitis !== null && $tarihBitis !== '') {
            $sql .= ' AND CONVERT(date, ' . $dateCol . ') <= :tarih_bitis';
            $params['tarih_bitis'] = $tarihBitis;
        }

        $sql .= ' ORDER BY ' . $orderCol;

        $stmt = $this->logoDb->getConnection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row) use ($belgeTipi): array {
            $fisNo = self::trimNo($row['fis_no'] ?? $row['belge_no'] ?? '');
            $docNo = self::trimNo($row['doc_no'] ?? '');

            return [
                'logo_logicalref' => (int) $row['logo_logicalref'],
                'sort_ref' => (int) ($row['sort_ref'] ?? $row['logo_logicalref'] ?? 0),
                'belge_tipi' => $belgeTipi,
                'belge_no' => $fisNo,
                'fis_no' => $fisNo,
                'doc_no' => $docNo,
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

        $line = $this->schema->quoteIdent($this->schema->lineTable($firmaNo, $donemNo, $belgeTipi));
        $clcard = $this->schema->quoteIdent($this->schema->clcardTable($firmaNo));
        $items = $this->schema->quoteIdent($this->schema->itemsTable($firmaNo));

        $fkCol = $this->schema->lineHeaderFkColumn($belgeTipi);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $fkCol)) {
            throw new \InvalidArgumentException('Geçersiz satır FK kolonu');
        }

        if ($belgeTipi === 'fatura') {
            $invoice = $this->schema->quoteIdent($this->schema->invoiceTable($firmaNo, $donemNo));
            $headerSql = "SELECT TOP 1
                            inv.LOGICALREF AS logo_logicalref,
                            inv.FICHENO AS belge_no,
                            inv.FICHENO AS fis_no,
                            inv.DOCODE AS doc_no,
                            inv.DATE_ AS tarih,
                            inv.CAPIBLOCK_CREADEDDATE AS logo_created_at,
                            COALESCE(inv.TOTALVAT, 0) AS kdv_tutari,
                            COALESCE(inv.NETTOTAL, inv.GROSSTOTAL, 0) AS genel_toplam,
                            c.CODE AS cari_kod,
                            c.DEFINITION_ AS cari_unvan,
                            c.ADDR1 AS adres1,
                            c.ADDR2 AS adres2,
                            c.CITY AS sehir
                          FROM {$invoice} inv
                          LEFT JOIN {$clcard} c ON c.LOGICALREF = inv.CLIENTREF
                          WHERE inv.LOGICALREF = :logicalref
                            AND ISNULL(inv.CANCELLED, 0) = 0";
        } else {
            $header = $this->schema->quoteIdent($this->schema->headerTable($firmaNo, $donemNo, $belgeTipi));
            $headerSql = "SELECT TOP 1
                            h.LOGICALREF AS logo_logicalref,
                            h.FICHENO AS belge_no,
                            h.FICHENO AS fis_no,
                            h.DOCODE AS doc_no,
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
        }

        $stmt = $this->logoDb->getConnection()->prepare($headerSql);
        $stmt->execute(['logicalref' => $logicalRef]);
        $head = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($head === false) {
            return null;
        }

        // LINETYPE: 0 malzeme, 2 indirim, 4 hizmet, 6 set, 7 set detayı (bkz. LOGO_SCHEMA.md)
        if ($belgeTipi === 'fatura') {
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
                        WHERE l.INVOICEREF = :logicalref
                        ORDER BY l.LOGICALREF";
            $lineParams = ['logicalref' => $logicalRef];
        } else {
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
            $lineParams = ['logicalref' => $logicalRef];
        }

        $lineStmt = $this->logoDb->getConnection()->prepare($lineSql);
        $lineStmt->execute($lineParams);
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
            'belge_no' => self::trimNo($head['belge_no'] ?? ''),
            'fis_no' => self::trimNo($head['fis_no'] ?? $head['belge_no'] ?? ''),
            'doc_no' => self::trimNo($head['doc_no'] ?? ''),
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

    /**
     * Cari karttan SMS için telefonlar (TELNRS1 / TELNRS2).
     * Geçerli ve tekil numaralar döner (örn. ["5455613675", "5321112233"]).
     *
     * @return list<string>
     */
    public function findCariTelefonlar(int $firmaNo, string $cariKod): array
    {
        $cariKod = trim($cariKod);
        if ($firmaNo < 1 || $cariKod === '') {
            return [];
        }

        $table = $this->schema->quoteIdent($this->schema->clcardTable($firmaNo));
        $sql = "SELECT TOP 1
                    c.TELNRS1 AS tel1,
                    c.TELNRS2 AS tel2
                FROM {$table} c
                WHERE LTRIM(RTRIM(c.CODE)) = :cari_kod";

        $stmt = $this->logoDb->getConnection()->prepare($sql);
        $stmt->execute(['cari_kod' => $cariKod]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }

        $phones = [];
        foreach (['tel1', 'tel2'] as $key) {
            $normalized = self::normalizeGsm($row[$key] ?? null);
            if ($normalized !== null && !in_array($normalized, $phones, true)) {
                $phones[] = $normalized;
            }
        }

        return $phones;
    }

    /**
     * Cari karttan ilk geçerli SMS telefonu (TELNRS1 / TELNRS2).
     */
    public function findCariTelefon(int $firmaNo, string $cariKod): ?string
    {
        $phones = $this->findCariTelefonlar($firmaNo, $cariKod);

        return $phones[0] ?? null;
    }

    /**
     * Belge iş tarihi (DATE_) — YYYY-MM-DD.
     */
    public function findDocumentTarih(
        int $firmaNo,
        int $donemNo,
        int $logicalRef,
        string $belgeTipi,
    ): ?string {
        if ($firmaNo < 1 || $donemNo < 1 || $logicalRef < 1) {
            return null;
        }

        try {
            $this->assertBelgeTipi($belgeTipi);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if ($belgeTipi === 'fatura') {
            $invoice = $this->schema->quoteIdent($this->schema->invoiceTable($firmaNo, $donemNo));
            $sqlInv = "SELECT TOP 1 inv.DATE_ AS tarih
                       FROM {$invoice} inv
                       WHERE inv.LOGICALREF = :logicalref
                         AND ISNULL(inv.CANCELLED, 0) = 0";
            $stmtInv = $this->logoDb->getConnection()->prepare($sqlInv);
            $stmtInv->execute(['logicalref' => $logicalRef]);
            $rowInv = $stmtInv->fetch(PDO::FETCH_ASSOC);

            return $rowInv === false ? null : self::formatDate($rowInv['tarih'] ?? null);
        }

        $header = $this->schema->quoteIdent($this->schema->headerTable($firmaNo, $donemNo, $belgeTipi));
        $sql = "SELECT TOP 1 h.DATE_ AS tarih
                FROM {$header} h
                WHERE h.LOGICALREF = :logicalref
                  AND ISNULL(h.CANCELLED, 0) = 0";
        $stmt = $this->logoDb->getConnection()->prepare($sql);
        $stmt->execute(['logicalref' => $logicalRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::formatDate($row['tarih'] ?? null);
    }

    /**
     * Logo telefon alanını NetGSM no formatına çevirir (10 hane, 5xx...).
     */
    public static function normalizeGsm(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        // 905xxxxxxxxx → 5xxxxxxxxx
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        // 05xxxxxxxxx → 5xxxxxxxxx
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10 || !str_starts_with($digits, '5')) {
            return null;
        }

        return $digits;
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

    private static function trimNo(mixed $value): string
    {
        return trim((string) ($value ?? ''));
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
