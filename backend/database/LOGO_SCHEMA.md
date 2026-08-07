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
- Satır: `STOCKREF`, `AMOUNT`, `PRICE`, `TOTAL`, `LINENET`, `DISTDISC`, `DISCPER`, `LINEEXP`, `LINETYPE`, `PARENTLNREF`, FK: `ORDFICHEREF` veya `STFICHEREF`
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
