# Logo şema notları (Tiger / GO Wings benzeri)

Bu sprint varsayılan olarak şu isimlendirmeyi kullanır:

| Tür | Tablo deseni |
|-----|----------------|
| Cari | `LG_{FIRMA}_CLCARD` |
| Malzeme | `LG_{FIRMA}_ITEMS` |
| Sipariş başlık/satır | `LG_{FIRMA}_{DONEM}_ORFICHE` / `ORFLINE` |
| İrsaliye/Fatura başlık/satır | `LG_{FIRMA}_{DONEM}_STFICHE` / `STLINE` |

Örnek: firma=1, dönem=1 → `LG_001_01_ORFICHE`

## Önemli kolonlar (varsayılan)

- Başlık: `LOGICALREF`, `FICHENO`, `DATE_`, `CLIENTREF`, `TRCODE`, `CANCELLED`, `GROSSTOTAL`, `TOTALVAT`, `NETTOTAL`, `TOTALDISCOUNTS`
- Satır: `STOCKREF`, `AMOUNT`, `PRICE`, `VAT`, `VATINC`, `TOTAL`, `LINENET`, `DISTDISC`, `DISCPER`, `LINEEXP`, `LINETYPE`, `PARENTLNREF`, FK: `ORDFICHEREF` veya `STFICHEREF`
- `VATINC = 1` ise `PRICE` KDV dahil birim fiyattır. API `birim_fiyat` bu durumda `PRICE / (1 + VAT/100)` olarak KDV hariç döner. `VATINC = 0` ise `PRICE` olduğu gibi (zaten KDV hariç) kalır.
- Cari: `CODE`, `DEFINITION_`, `ADDR1`, `ADDR2`, `CITY`, `ACTIVE` (0=aktif)
- Cari vergi / kimlik: `TAXNR` (kurumsal VKN — bu kurulumda `VERGINO` kolonu yok), `TCKNO` (şahıs TC). API `vkn` alanı olarak `COALESCE(TAXNR, TCKNO)` döner.
- GO3DEMO örnek veride birçok carinin `TAXNR` alanı boş; dolu kimlik çoğu zaman `TCKNO` üzerindedir. VKN araması her iki kolonda da `LIKE` yapar.

## STLINE / ORFLINE — LINETYPE ve indirim satırları

Doğrulama: GO3DEMO `LG_103_01_*`, fatura `FICHENO=0000000000000073` (`STFICHEREF=1124`).

| LINETYPE | Anlam (bu kurulum) | API davranışı |
|----------|--------------------|---------------|
| 0 | Malzeme / ürün | Kalem listesine eklenir |
| 2 | İndirim satırı | Ayrı kalem **değil**; `PARENTLNREF` → üst ürün `iskonto_tutari` |
| 4 | Hizmet | Kalem listesine eklenir |
| 6 | Set (üst) | Kalem listesine eklenir |
| 7 | Set bileşeni | Atlanır (üst sette özetlenir) |

### İndirim bağlama

- İndirim satırında `PARENTLNREF` = indirimin ait olduğu ürün satırının `LOGICALREF` değeri.
- İndirim tutarı indirim satırının `TOTAL` alanında (ör. %28 için `DISCPER=28`).
- Ürün satırında Logo ayrıca `DISTDISC` (dağıtılmış iskonto) ve `LINENET` (= `TOTAL − DISTDISC`, KDV hariç net) doldurur.
- `PREVLINEREF` / `PREVLINENO` bu örnekte 0; bağlama için güvenilir kolon **`PARENTLNREF`**.
- `PARENTLNREF` boşsa uygulama `LOGICALREF` sırasına göre bir önceki ürün satırına düşer.

### Başlık tutarları (dikkat)

- `GROSSTOTAL` = satır `TOTAL` toplamı (**iskonto öncesi** brüt). Ham haliyle “ara toplam” gibi göstermek şişirilmiş rakam verir (ör. 39071).
- `TOTALDISCOUNTS` = toplam iskonto.
- Satır net ara toplam ≈ `GROSSTOTAL − TOTALDISCOUNTS` = `SUM(LINENET)`.
- `NETTOTAL` ≈ satır netleri + `TOTALVAT` (genel toplam, KDV dahil).

API `getDocumentDetail`: `toplam_tutar = SUM(kalemler.tutar)` (`LINENET`); `genel_toplam` başlıktan `NETTOTAL`.

## Kurulumunuza göre ayar

`config/.env` içinde tablo desenleri ve TRCODE listelerini override edin.
Gerçek TRCODE değerlerini Logo'da şu sorguyla doğrulayın:

```sql
SELECT TRCODE, COUNT(*) FROM LG_001_01_STFICHE GROUP BY TRCODE ORDER BY TRCODE;
```

Migration `006_add_belge_no_and_match_index.sql` çalıştırılmalıdır.

## PERDOC — belge notu (`LG_{FIRMA}_{DONEM}_PERDOC`)

Logo arayüzündeki belge açıklama/not alanı `LDATA` kolonunda tutulur.

### LDATA formatı (Delphi ShortString, RTF değil)

Örnek (LG_103_01_PERDOC):

`0x19697273616C6979652064657461796CFD2061E7FD6B6C616D61`

= `0x19` (25 = uzunluk) + `"irsaliye detaylı açıklama"` (Windows-1254; `0xFD`=`ı`, `0xE7`=`ç`)

- 1 byte uzunluk öneki (max 255)
- ardından Windows-1254 düz metin
- null-terminator yok

### Eşleştirme anahtarları (canlı Logo UI — GO3DEMO / firma 103)

| Alan | Anlam | Değer |
|------|--------|--------|
| `INFOTYP` | Belge ailesi | sipariş=`5`; fatura/irsaliye=`7` (Logo fatura notu `7`) |
| `INFOREF` | Belgenin kendi `LOGICALREF` | fatura→`INVOICE`, irsaliye→`STFICHE`, sipariş→`ORFICHE` |
| `DOCTYP` | Not içeriği (metin) | `3` |
| `DOCNR` | Not sıra no | `1` |
| `LREF` | PERDOC PK | **IDENTITY** (INSERT’te verilmez) |

Fatura match key = `INVOICE.LOGICALREF` (= PERDOC.INFOREF; tablolar arası COALESCE yok).

### TEXTINC (Logo UI’da notun görünmesi)

PERDOC satırı yazılsa bile fiş/fatura başlığında `TEXTINC` `0` ise Logo notu göstermez. Match sonrası ilgili başlıkta `TEXTINC = 1` set edilir:

| Tip | Tablo |
|-----|--------|
| fatura | `LG_{FIRMA}_{DONEM}_INVOICE` (`LOGICALREF` = PERDOC.INFOREF) |
| irsaliye | `LG_{FIRMA}_{DONEM}_STFICHE` |
| sipariş | `LG_{FIRMA}_{DONEM}_ORFICHE` |

### FICHENO / uygulama `belge_no` kaynağı

| Tip | Kaynak |
|-----|--------|
| fatura | `LG_{FIRMA}_{DONEM}_INVOICE.FICHENO` (`logo_logicalref` = `INVOICE.LOGICALREF`) |
| irsaliye | `LG_{FIRMA}_{DONEM}_STFICHE.FICHENO` |
| sipariş | `LG_{FIRMA}_{DONEM}_ORFICHE.FICHENO` |

Uygulama DB (`Documents`): `belge_tipi` string + `logo_logicalref` + `belge_no` (yukarıdaki FICHENO).

Match (yeni uid) sonrası `PUBLIC_BASE_URL/belge/{uid}` PERDOC notuna eklenir (idempotent).

