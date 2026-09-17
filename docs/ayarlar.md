# Ayarlar

Kurulum → Modüller → İşNet e-Fatura ⚙. Her ayar bir `ISNETEFATURA_*` sabiti olarak `llx_const`'ta tutulur; değişiklikler güvenlik denetim günlüğüne (eski → yeni değer) yazılır.

## Bağlantı

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| Ortam | test | `test` / `prod`. URL ve gönderici VKN buna göre seçilir. |
| Şirket VKN | boş | Boşsa Şirket → Prof ID 1. |
| Test ortamı VKN | boş | Ortam test iken gönderici VKN olarak kullanılır (entegratörün test firması). Canlıda yok sayılır. |
| Bayi / şube numarası | boş | İşNet `CompanyVendorNumber`; çok şubeli firmalarda. Bağlantı testi şube listesini gösterir. |
| InvoiceService / AddressBookService URL (test, prod) | test URL'leri dolu | Prod URL'ler İşNet'ten alınır. |
| Zaman aşımı | 60 sn | SOAP istek süresi. |
| Cron kullanıcı id | 1 | Zamanlanmış görevlerin yazdığı olay kayıtlarının sahibi. |
| Ayrıntılı günlük | kapalı | Tam SOAP XML'i `dolibarr.log`'a ve deneme kaydına yazar. Sorun giderme için. |

## Davranış

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| Onaylayınca otomatik gönder | açık | Fatura "Onayla" anında İşNet'e gider. Kapalıysa sekmeden elle. |
| Gönderim hatasında onayı engelle | kapalı | Açık: İşNet reddederse fatura taslakta kalır. Kapalı: onaylanır, "Gönderilemedi" işaretlenir, sonra tekrar gönderilir. |
| Mükellef sorgulama | önbellekle | `Her gönderimde` / `Önbellekle` (cari kartına yazılır, süre dolana kadar sorulmaz) / `Sorgulama` (cari kartındaki "e-Fatura durumu" alanı esas). |
| Önbellek süresi | 7 gün | 0 = hep sorgula. |
| Vergi dairesi zorunlu | açık | 10 haneli VKN'li alıcıda Prof ID 2 boşsa gönderim engellenir. |
| Yurtiçi ülke kodu | TR | Diğer ülkeler ihracat sayılır. |

## Fatura varsayılanları

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| Varsayılan senaryo | TICARIFATURA | Yurtiçi e-Fatura; fatura ek alanından değiştirilebilir. İade faturaları her zaman TEMEL. |
| Varsayılan birim kodu | C62 | Dolibarr birimi UN/ECE koduna eşlenemezse. |
| IBAN basılacak hesap | faturanın hesabı | Sabit hesap seçilirse her belgede o IBAN. |
| Varsayılan KDV istisna kodu / açıklaması | boş | KDV %0 satırlı yurtiçi faturalar için; boş ve faturada da yoksa gönderim engellenir. |
| e-Arşiv faturayı e-posta ile gönder | açık | Cari e-postası varsa İşNet otomatik e-postalar. |
| Sabit fatura notu | boş | Her belgeye ilk not satırı. |
| Entegratör PDF'ini kaydet | açık | Belge imzalanınca İşNet PDF'i faturanın Belgeler alanına iner. |
| Prof ID etiketlerini Türkçeleştir | açık | Vergi No / Vergi Dairesi / MERSİS / Ticaret Sicil. |
| Dolibarr PDF'ini ek olarak gönder | kapalı | Dolibarr'da üretilmiş fatura PDF'i e-belgeye ek olur. |
| İnternet satışı web adresi | boş | "İnternet satışı" işaretli e-Arşiv'lerde. |
| Kontör uyarı eşiği | 100 | Bağlantı testinde bakiye altındaysa uyarı. 0 = kapalı. |

## Gelen faturalar

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| Gelen e-Faturaları senkronize et | açık | Saatlik cron + Faturalama menüsünde sayfa. e-İrsaliye açıksa gelen irsaliyeler de. |
| Geriye dönük gün | 30 | Senkron penceresi. |
| Tedarikçi carisini otomatik oluştur | açık | VKN eşleşmezse e-Faturadaki bilgilerle tedarikçi açılır. |

## e-İrsaliye

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| e-İrsaliye özelliği | açık | Sevkiyat sekmesi ve gelen irsaliyeler. |
| Sevkiyat onaylanınca otomatik gönder | açık | |
| Hatada sevkiyat onayını engelle | kapalı | |
| Varsayılan araç plakası / dorse | boş | Sevkiyatta girilmemişse. Boşluksuz (34ABC123). |
| Varsayılan sürücü adı / soyadı / TCKN | boş | GİB'de zorunlu; sevkiyat ek alanları öncelikli. |
| Varsayılan taşıyıcı firma / VKN | boş | Nakliye üçüncü firmayla yapılıyorsa; boşsa kendi araç. |

## İhracat

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| GTB alıcı adı / VKN / posta kutusu | Ticaret Bakanlığı- Bilgi Teknolojileri Genel Müdürlüğü / 1460415308 / urn:mail:ihracatpk@gtb.gov.tr | GİB kuralı; ad birebir doğrulanır. |
| Mal ihracatı istisna kodu / açıklaması | 301 / Mal İhracatı | |
| Hizmet ihracatı istisna kodu / açıklaması | 302 / Hizmet İhracatı | Yurtdışı hizmet faturaları e-Arşiv olarak. |
| Varsayılan taşıma şekli | 3 (karayolu) | UN/ECE Rec.19: 1 deniz, 2 demiryolu, 3 karayolu, 4 hava, 5 posta, 7 sabit, 8 iç suyolu. |
| Varsayılan kap cinsi | PK | UN/ECE Rec.21 (PK paket, CT karton, BX kutu, PX palet…). |
| Yurtdışı alıcı VKN sabiti | 1111111111 | VKN'si olmayan yurtdışı alıcılar için GİB sabiti. |

## Diğer bölümler (aynı sayfada)

- **Görüntü şablonları (XSLT / logo):** e-Fatura ve e-Arşiv için ayrı XSLT yükleme; yüklenirse her belgeyle gönderilir. Alternatif: İşNet'e firma şablonu tanımlatmak.
- **GİB kod listeleri:** il (82), ilçe (~1000), vergi dairesi (~950) — kayıt sayısı, son güncelleme, "Listeleri şimdi indir".
- **Bağlantı testi:** HealthCheck, kontör bakiyesi, şubeler, isteğe bağlı VKN sorgusu (mükellef mi, posta kutuları).

## Belge bazında ek alanlar

**Fatura:** e-Fatura senaryosu, tipi, KDV istisna kodu/açıklaması, tevkifat kodu/oranı, taşıma şekli, kap cinsi/adedi, irsaliye no/tarihi, internet satışı (ödeme şekli/tarihi, ödeme aracısı, kargo firması/VKN, kargoya veriliş), e-Belge no ve durumu (salt okunur).
**Cari:** e-Fatura durumu, posta kutusu, e-İrsaliye posta kutusu, mükellef sorgu tarihi.
**Ürün:** GTİP no, menşei ülke.
**Sevkiyat:** araç/dorse plakası, sürücü ad/soyad/TCKN, taşıyıcı firma/VKN.
