# İşNet API Notları (geliştiriciler için)

İşNet web servisleri WCF tabanlı SOAP 1.1 servislerdir (`InvoiceService.svc`, `AddressBookService.svc`). Modül PHP `SoapClient`'ı WSDL modunda kullanır (`class/isnetclient.class.php`).

## Genel

- **Kimlik doğrulama IP + VKN.** Kullanıcı/şifre yok; canlıda sunucu IP'si İşNet firewall'una tanımlanır. Test ortamında gönderici VKN olarak İşNet'in verdiği test firması kullanılır (`ISNETEFATURA_TEST_COMPANY_VKN`).
- **Tag sırası alfabetik olmalı** (WCF DataContract). `SoapClient` WSDL modunda alanları şema sırasına göre kodlar; assoc array sırası önemsizdir. Elle XML üretmeyin.
- WSDL'de ilk port `http`; `location` ile `https` zorlanır.
- Her operasyon tek `request` parametresi alır, yanıt `<Op>Result` içinde gelir (`IsnetClient::call()` açar). `Result=Failed` + `ErrorMessage` → `ISNET_FAILED:` ön ekli hata.
- `ArrayOfX` gönderimde `['X' => [item, …]]`, yanıtta `stdClass{X: item|item[]}` → `IsnetClient::toList()`.
- **Sayfalı aramalarda `PagingRequest` zorunlu** (`SearchArchiveInvoice`: "Sayfalama isteği zorunludur").
- `GetTaxPayer` mükellef olmayan VKN için **hata döner** ("kullanıcı bulunamadı"); modül bunu e-Arşiv kararına çevirir.
- Test hesaplarında onlarca posta kutusu vardır; `defaultpk@` içereni seçilir, cari kartından sabitlenebilir.
- `GetDocumentViewerLink` `http://…:80` döndürür ama yalnız https yanıt verir (rewrite edilir).
- `SearchInvoice` boş `Ettn` ile hesaptaki ilk faturayı döndürür — boş filtreyle çağırmayın.
- Enum değerleri büyük harf: `KABUL`, `RED`, `IADE_EDILDI`; senaryolar `TEMELFATURA`, `TICARIFATURA`, `IHRACAT`, `KAMU`; tipler `SATIS`, `IADE`, `ISTISNA`, `TEVKIFAT`, `TEVKIFATIADE`, `OZELMATRAH`, `IHRACKAYITLI`.

## Adres ve vergi dairesi

İşNet UBL'deki alıcı adresini **inline isimlerden değil kodlardan** üretir: `CityCode` (plaka), `TownCode`, `TaxOfficeCode`. İsimler gönderilirse `CitySubdivisionName`/`CityName`/`TaxScheme/Name` boş kalır. Modül `GetCityList` / `GetTownList` / `GetTaxOfficeList` listelerini `llx_isnetefatura_code` tablosuna indirir ve `IsnetCodeResolver` ile isimleri Türkçe-duyarsız normalize edip koda çevirir ("Mecidiyeköy VD" → 34274). İl için Dolibarr state kodu (TR-34 → 34) kullanılır, isim bozuk olsa da çalışır.

İhracat alıcısı (`ExportReceiver`) için ise inline alanlar kullanılır; İşNet adres defteri (`SaveAddressBookEntry`) gönderimde **kullanılmaz** (test edildi).

## e-Arşiv

- `ArchiveInvoice.InvoiceDetails` eleman adı `ArchiveInvoiceDetail` (e-Fatura'da `InvoiceDetail`).
- Belge oluştuğunda `Fatura_Olusturuldu`; GİB'e günlük raporla gider (`Gibe_Otomatik_Raporlanacaktir` → `Gib_Raporu_Kabul_Etti`). PDF `Fatura_Olusturuldu` anında hazırdır.
- İnternet satışı: `WebSellingInfo{PaymentType, PaymentDate, WebAddress, SendingDate, Carrier{CarrierName, VknTckn}, PaymentMediatorName}`; İşNet bu belgelere ayrı seri verir.
- İptal: `CancelArchiveInvoice{ArchiveInvoiceList[{ETTN, CancellationReason}]}` → durum `Silindi`. E-posta: `SendArchiveInvoiceMail{Ettn, Email}`.

## e-Fatura

- ISTISNA tipinde satır kodu yetmez; fatura seviyesinde `Exemptions[Exemption{TaxExemptionReasonCode, TaxExemptionReasonName}]` de gerekir.
- İhracat: alıcı adı **birebir** "Ticaret Bakanlığı- Bilgi Teknolojileri Genel Müdürlüğü", `ReceiverInboxTag urn:mail:ihracatpk@gtb.gov.tr`, satır başına `DeliveryList[Delivery{DeliveryAddress, DeliveryTermCode, Shipment{GtipNoList, ShipmentPackageList, ShipmentStageList}}]`. UBL-TR'de `CitySubdivisionName` zorunlu → ihracat alıcısında `TownName` her zaman dolu.
- Tevkifat: satırda `WitholdingTaxes[Tax{TaxCode, TaxRate, TaxAmount}]`, `TotalPayableAmount` tevkifat düşülmüş.
- İade: satıcı kendi e-Faturasına iade kesemez (`ReturnInvoiceETTN` *gelen* faturayı arar). İade belgeleri yalnız `TEMELFATURA`.
- **Başarısız belge aynı `ExternalInvoiceCode` ile tekrar gönderilince İşNet "günceller" ama alıcı bloğunu yeniden oluşturmaz.** Modül zorla gönderimde `REF-R2`, `-R3` dış kodu kullanır. İşlemdeki belge "daha önce kayıt edildiğinden tekrar kayıt edilemez" der.
- Gelen faturada karşı taraf `Receiver` alanındadır.

## e-İrsaliye

- `Person` tipi WSDL'de iki kez tanımlı; irsaliyede `NationalityId` (küçük d). Yanlış ad sessizce düşer → şematron "NationalityID dolu olmalı".
- `SearchDespatchAdvice` tarih aralığıyla satır detaylarını döndürmez; ETTN ile tekil sorgu döndürür.
- İrsaliye yanıtında `Product.ExternalProductCode` zorunlu.
- Alıcı e-İrsaliye mükellefliği ayrı kayıt (`GetDespatchTaxPayer`).

## Kapsam dışı bırakılanlar ve gerekçesi

| Operasyon | Gerekçe |
|---|---|
| `SendInvoiceXml`, `…WithoutInvoiceNumber`, XML irsaliye/arşiv varyantları | UBL-TR ve imzayı İşNet üretiyor; XML üretmek gereksiz karmaşıklık |
| `DirectInvoice`, `ArchiveInvoice`/`DeArchiveInvoice`, `SearchAllInvoice`, `SearchExternalInvoice`, `GetEttnList` | İşNet portal/arşiv yönetimi; ERP akışında yeri yok |
| `ContestArchiveInvoice`, `SendArchiveInvoiceReport`, `SearchArchiveInvoiceReport` | GİB raporunu İşNet otomatik gönderir; itiraz portaldan |
| `SaveAddressBookEntry`, `DeleteAddressBookEntry`, `GetAddressBook`, `GetReceiverInboxTags`, `GetSenderUnitTags` | Gönderimde kullanılmıyor; etiketler test hesabında boş |
| e-SMM (`SendESMM`, `CancelESMM`, `SearchESMM`) | Serbest meslek makbuzu — şirketler düzenlemez |
| e-Döviz (`SendCurrencyInvoice`) | Yetkili döviz büroları için |

## Referans dosyalar

WSDL/XSD kopyaları ve `SoapClient::__getTypes()` çıktıları geliştirme deposunda `docs/isnet/wsdl/` altında tutulur (bu pakete dahil değildir).
