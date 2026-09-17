# Geliştirme

## Yapı

```
isnetefatura/
├── core/modules/modIsnetEfatura.class.php    modül tanımı: sabitler, ek alanlar, cron, sekmeler, menü, Prof ID override
├── core/triggers/interface_90_…Autosend      BILL_VALIDATE / SHIPPING_VALIDATE → "Onayla + Gönder"
├── class/
│   ├── isnetclient.class.php                 SOAP sarmalayıcı (tüm İşNet operasyonları)
│   ├── isnetinvoicemapper.class.php          Facture → Invoice / ArchiveInvoice; ön kontrol; ihracat, tevkifat, iade, internet satışı
│   ├── isnetsender.class.php                 gönderim orkestrasyonu, mükellef kararı, durum yenileme, PDF, cron
│   ├── isnetdespatch.class.php               Expedition → DespatchAdvice; gönderim, durum, PDF
│   ├── isnetincoming.class.php               gelen e-Fatura / e-İrsaliye senkronu, aktarım, yanıtlar
│   ├── isnetdocument.class.php               llx_isnetefatura_document DAO; sonuç modeli (ok/issued/fail/cancelled/pending/error)
│   ├── isnetcoderesolver.class.php           GİB il/ilçe/vergi dairesi kod önbelleği ve isim→kod
│   └── isnetaudit.class.php                  denetim kayıtları (ActionComm + Events + syslog)
├── admin/setup.php                           ayar sayfası, XSLT yükleme, kod listeleri, bağlantı testi
├── card.php / despatch.php / incoming.php    fatura sekmesi / sevkiyat sekmesi / gelen belgeler
├── scripts/isnet_check.php                   CLI bağlantı testi
├── sql/                                      tablolar, indeksler, update_*.sql
├── class/mcp/toolisnetefatura.class.php   Dolibarr 24 AI/MCP araçları (class/actions_isnetefatura.class.php → addMcpTools hook)
└── langs/tr_TR, en_US
```

Çekirdeğe dokunulmaz: hook yok, yalnız trigger + extrafield + kendi tabloları.

## İlkeler

- Şirkete/kuruluma özgü **hiçbir değer kodda olmaz**; her davranış `ISNETEFATURA_*` sabiti.
- Her gönderim öncesi `preflight()` insan-okur mesajlarla engeller; İşNet'e hatalı belge gitmesin.
- Her dış etkisi olan işlem `IsnetAudit` ile kayıt altına alınır.
- Yeni özellik = ayar + dil anahtarı (tr_TR **ve** en_US) + doküman.

## Yerel geliştirme akışı

1. Değişiklik → `php -l` → hedef Dolibarr'a kopyala (`custom/isnetefatura/`).
2. Modül tanımı (sabit/ek alan/tablo/cron/menü) değiştiyse modülü **kapat-aç** (UI'dan; CLI'da iki ayrı süreç). Aktif modülde `init()` çağırmak menü çakışmasında durur ve sabitleri eklemez.
3. Test ortamında İşNet test firmalarına belge gönderip sonuçları `docs/…` test kayıtlarıyla karşılaştır.
4. Lang dosyalarında `%` karakteri `%%` olarak yazılır (`trans()` sprintf uygular).

## Sürüm notları

Bkz. `ChangeLog`.

## Yol haritası

- Satır bazında tevkifat kodu (şu an fatura bazında)
- Gelen e-Fatura satırlarını Dolibarr ürünleriyle eşleme (tedarikçi ürün kodu)
- Çoklu şirket (Multicompany) — entity başına VKN/URL
- DoliStore paketi

## TRTevkifat modülü ile birlikte çalışma

`trtevkifat` modülü tevkifatı faturaya `special_code = 99` işaretli negatif bir satır olarak yazar. Mapper bu satırı `billableLines()`'da atlar, tevkifat kodu/oranını `isnet_tevkifat_*` boşsa `trtevkifat_*` ek alanlarından okur ve UBL toplamlarını (`TotalTaxInclusiveAmount`, `TotalVATAmount`) faturanın `total_*` alanlarından değil satırlardan toplar; `TotalPayableAmount` böylece Dolibarr'ın ödenecek toplamıyla birebir aynı çıkar.
