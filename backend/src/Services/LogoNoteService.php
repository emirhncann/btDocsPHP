<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\LogoSchema;
use PDO;

/**
 * Logo PERDOC.LDATA — Delphi ShortString (1 byte uzunluk + Windows-1254 metin).
 *
 * Canlı Logo UI anahtarları (GO3DEMO / LG_103_01_PERDOC):
 *   INFOTYP = belge ailesi (siparis=5, fatura=7, irsaliye=7)
 *   DOCTYP  = 3 (metin not)
 *   DOCNR   = 1 (not sıra)
 *   INFOREF = belgenin kendi LOGICALREF'i
 *             fatura → INVOICE.LOGICALREF
 *             irsaliye → STFICHE.LOGICALREF
 *             siparis → ORFICHE.LOGICALREF
 */
final class LogoNoteService
{
    private const MAX_PAYLOAD = 255;
    private const INFO_TYP = [
        'siparis' => 5,
        // Logo fatura notu INVOICE LOGICALREF ile INFOTYP=7 kullanıyor
        'fatura' => 7,
        'irsaliye' => 7,
    ];
    private const DOC_TYP_TEXT = 3;
    private const DOC_NR_NOTE = 1;

    public function __construct(
        private readonly LogoDbConnection $logoDb,
        private readonly LogoSchema $schema,
    ) {
    }

    public static function decodeShortString(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $n = ord($bytes[0]);
        if ($n < 1) {
            return '';
        }

        $payload = substr($bytes, 1, $n);
        $converted = mb_convert_encoding($payload, 'UTF-8', 'Windows-1254');

        return is_string($converted) ? $converted : '';
    }

    public static function encodeShortString(string $text): string
    {
        $encoded = self::toWindows1254($text);
        if (strlen($encoded) > self::MAX_PAYLOAD) {
            $encoded = substr($encoded, 0, self::MAX_PAYLOAD);
        }

        return pack('C', strlen($encoded)) . $encoded;
    }

    /**
     * Belge tipine göre fiş numarası (FICHENO).
     * fatura → INVOICE.FICHENO (STFICHE.INVOICEREF üzerinden, A3 logicalref ST olduğu için)
     * irsaliye/siparis → mevcut header tablosu FICHENO (A3 ile aynı)
     */
    public function resolveDocNr(
        string $belgeTipi,
        int $logicalRef,
        int $firmaNo,
        int $donemNo,
    ): string {
        $belgeTipi = strtolower(trim($belgeTipi));
        $pdo = $this->logoDb->getConnection();

        if ($belgeTipi === 'fatura') {
            return $this->resolveInvoiceFicheNo($pdo, $firmaNo, $donemNo, $logicalRef);
        }

        $header = $this->schema->quoteIdent(
            $this->schema->headerTable($firmaNo, $donemNo, $belgeTipi)
        );
        $stmt = $pdo->prepare(
            "SELECT TOP 1 FICHENO AS belge_no
             FROM {$header}
             WHERE LOGICALREF = :lref"
        );
        $stmt->execute(['lref' => $logicalRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Logo başlık kaydı bulunamadı (DOCNR)');
        }

        $ficheNo = trim((string) ($row['belge_no'] ?? ''));
        if ($ficheNo === '') {
            throw new \RuntimeException('Logo FICHENO boş');
        }

        return $ficheNo;
    }

    /**
     * PERDOC.INFOREF — belgenin kendi LOGICALREF'i.
     * A3 match key fatura için STFICHE olduğundan INVOICE.LOGICALREF'e çözülür.
     */
    public function resolveInfoRef(
        string $belgeTipi,
        int $logicalRef,
        int $firmaNo,
        int $donemNo,
    ): int {
        $belgeTipi = strtolower(trim($belgeTipi));
        if ($belgeTipi !== 'fatura') {
            // siparis → ORFICHE, irsaliye → STFICHE (A3 match key zaten bunlar)
            return $logicalRef;
        }

        return $this->resolveInvoiceLogicalRef(
            $this->logoDb->getConnection(),
            $firmaNo,
            $donemNo,
            $logicalRef,
        );
    }

    /**
     * @return array{skipped:bool,inserted:bool,updated:bool,truncated:bool}
     */
    public function appendBelgeUrl(
        int $firmaNo,
        int $donemNo,
        string $belgeTipi,
        int $logoLogicalRef,
        string $url,
    ): array {
        return $this->appendUrlToDocumentNote(
            $firmaNo,
            $donemNo,
            $belgeTipi,
            $logoLogicalRef,
            $url,
        );
    }

    /**
     * @return array{skipped:bool,inserted:bool,updated:bool,truncated:bool}
     */
    public function appendUrlToDocumentNote(
        int $firmaNo,
        int $donemNo,
        string $belgeTipi,
        int $logicalRef,
        string $url,
    ): array {
        $belgeTipi = strtolower(trim($belgeTipi));
        if (!isset(self::INFO_TYP[$belgeTipi])) {
            throw new \InvalidArgumentException('Geçersiz belge_tipi');
        }

        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('URL boş olamaz');
        }

        $urlBytes = self::toWindows1254($url);
        if (strlen($urlBytes) > self::MAX_PAYLOAD) {
            throw new \InvalidArgumentException(
                'URL Windows-1254 olarak 255 baytı aşıyor; kısaltılamaz'
            );
        }

        // Canlı Logo UI: INFOTYP=tip, DOCTYP=3, DOCNR=1,
        // INFOREF = fatura→INVOICE / irsaliye→STFICHE / siparis→ORFICHE LOGICALREF
        $infoTyp = self::INFO_TYP[$belgeTipi];
        $docTyp = self::DOC_TYP_TEXT;
        $docNr = self::DOC_NR_NOTE;
        $infoRef = $this->resolveInfoRef($belgeTipi, $logicalRef, $firmaNo, $donemNo);

        $table = $this->schema->quoteIdent($this->schema->perdocTable($firmaNo, $donemNo));
        $pdo = $this->logoDb->getConnection();

        $stmt = $pdo->prepare(
            "SELECT LREF, CONVERT(varbinary(256), LDATA) AS LDATA_BIN
             FROM {$table}
             WHERE INFOTYP = :infotyp
               AND INFOREF = :inforef
               AND DOCTYP = :doctyp
               AND DOCNR = :docnr"
        );
        $stmt->execute([
            'infotyp' => $infoTyp,
            'inforef' => $infoRef,
            'doctyp' => $docTyp,
            'docnr' => $docNr,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $binary = self::encodeShortString($url);
            $this->insertRow($pdo, $table, $infoTyp, $infoRef, $docTyp, $docNr, $binary);

            return [
                'skipped' => false,
                'inserted' => true,
                'updated' => false,
                'truncated' => false,
            ];
        }

        $existingBytes = $this->readBinary($row['LDATA_BIN'] ?? null);
        $existingText = self::decodeShortString($existingBytes);

        if ($existingText !== '' && str_contains($existingText, $url)) {
            return [
                'skipped' => true,
                'inserted' => false,
                'updated' => false,
                'truncated' => false,
            ];
        }

        $merged = $this->mergeNoteAndUrl($existingText, $url, $urlBytes);
        $binary = self::encodeShortString($merged['text']);

        $upd = $pdo->prepare(
            "UPDATE {$table}
             SET LDATA = CONVERT(varbinary(256), :ldata_hex, 2)
             WHERE LREF = :lref"
        );
        $upd->execute([
            'ldata_hex' => bin2hex($binary),
            'lref' => (int) $row['LREF'],
        ]);

        return [
            'skipped' => false,
            'inserted' => false,
            'updated' => true,
            'truncated' => $merged['truncated'],
        ];
    }

    /**
     * @return array{text:string,truncated:bool}
     */
    private function mergeNoteAndUrl(string $existingText, string $url, string $urlBytes): array
    {
        $existingText = trim($existingText);
        if ($existingText === '') {
            return ['text' => $url, 'truncated' => false];
        }

        $sep = "\r\n";
        $sepBytes = self::toWindows1254($sep);
        $budget = self::MAX_PAYLOAD - strlen($urlBytes) - strlen($sepBytes);

        if ($budget <= 0) {
            return ['text' => $url, 'truncated' => true];
        }

        $noteBytes = self::toWindows1254($existingText);
        if (strlen($noteBytes) <= $budget) {
            return ['text' => $existingText . $sep . $url, 'truncated' => false];
        }

        $ellipsis = '...';
        $ellipsisBytes = self::toWindows1254($ellipsis);
        $keep = $budget - strlen($ellipsisBytes);
        if ($keep < 1) {
            return ['text' => $url, 'truncated' => true];
        }

        $truncatedNoteBytes = substr($noteBytes, 0, $keep);
        $truncatedNote = mb_convert_encoding($truncatedNoteBytes, 'UTF-8', 'Windows-1254');
        if (!is_string($truncatedNote)) {
            $truncatedNote = '';
        }

        return [
            'text' => rtrim($truncatedNote) . $ellipsis . $sep . $url,
            'truncated' => true,
        ];
    }

    private function resolveInvoiceLogicalRef(
        PDO $pdo,
        int $firmaNo,
        int $donemNo,
        int $logicalRef,
    ): int {
        $invoice = $this->schema->quoteIdent(
            $this->schema->invoiceTable($firmaNo, $donemNo)
        );

        // 1) Zaten INVOICE.LOGICALREF
        $stmt = $pdo->prepare(
            "SELECT TOP 1 LOGICALREF FROM {$invoice} WHERE LOGICALREF = :lref"
        );
        $stmt->execute(['lref' => $logicalRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (int) $row['LOGICALREF'];
        }

        // 2) A3 match key = STFICHE.LOGICALREF → INVOICEREF
        $stfiche = $this->schema->quoteIdent(
            $this->schema->headerTable($firmaNo, $donemNo, 'fatura')
        );
        $stmt = $pdo->prepare(
            "SELECT TOP 1 h.INVOICEREF
             FROM {$stfiche} h
             WHERE h.LOGICALREF = :lref
               AND ISNULL(h.INVOICEREF, 0) > 0"
        );
        $stmt->execute(['lref' => $logicalRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException(
                'Fatura için INVOICE.LOGICALREF bulunamadı (ST LOGICALREF=' . $logicalRef . ')'
            );
        }

        $invoiceRef = (int) $row['INVOICEREF'];
        if ($invoiceRef < 1) {
            throw new \RuntimeException('STFICHE.INVOICEREF boş');
        }

        return $invoiceRef;
    }

    private function resolveInvoiceFicheNo(
        PDO $pdo,
        int $firmaNo,
        int $donemNo,
        int $logicalRef,
    ): string {
        $invoiceRef = $this->resolveInvoiceLogicalRef($pdo, $firmaNo, $donemNo, $logicalRef);
        $invoice = $this->schema->quoteIdent(
            $this->schema->invoiceTable($firmaNo, $donemNo)
        );
        $stmt = $pdo->prepare(
            "SELECT TOP 1 FICHENO FROM {$invoice} WHERE LOGICALREF = :lref"
        );
        $stmt->execute(['lref' => $invoiceRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException(
                'Fatura için INVOICE.FICHENO bulunamadı (LOGICALREF=' . $invoiceRef . ')'
            );
        }

        $ficheNo = trim((string) ($row['FICHENO'] ?? ''));
        if ($ficheNo === '') {
            throw new \RuntimeException('INVOICE.FICHENO boş');
        }

        return $ficheNo;
    }

    private function insertRow(
        PDO $pdo,
        string $quotedTable,
        int $infoTyp,
        int $infoRef,
        int $docTyp,
        int $docNr,
        string $binary,
    ): void {
        // LREF bu kurulumda IDENTITY
        $stmt = $pdo->prepare(
            "INSERT INTO {$quotedTable} (INFOTYP, INFOREF, DOCTYP, DOCNR, LDATA)
             VALUES (
                :infotyp, :inforef, :doctyp, :docnr,
                CONVERT(varbinary(256), :ldata_hex, 2)
             )"
        );
        $stmt->execute([
            'infotyp' => $infoTyp,
            'inforef' => $infoRef,
            'doctyp' => $docTyp,
            'docnr' => $docNr,
            'ldata_hex' => bin2hex($binary),
        ]);
    }

    private function readBinary(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return is_string($contents) ? $contents : '';
        }

        return is_string($value) ? $value : '';
    }

    private static function toWindows1254(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'Windows-1254//IGNORE', $utf8);
        if ($converted === false) {
            $converted = mb_convert_encoding($utf8, 'Windows-1254', 'UTF-8');
        }

        return is_string($converted) ? $converted : '';
    }
}
