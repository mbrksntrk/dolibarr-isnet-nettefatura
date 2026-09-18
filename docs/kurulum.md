# Kurulum

## Gereksinimler

- Dolibarr 20.0 veya üstü (24.0 üzerinde test edildi)
- PHP 8.1+ ve `soap`, `curl`, `dom`, `mbstring`, `openssl` eklentileri (`php -m` ile kontrol edin)
- Aktif Dolibarr modülleri: Faturalar, Cariler; ihracat için Incoterm ve Çoklu para birimi; e-İrsaliye için Sevkiyatlar; gelen faturalar için Tedarikçi faturaları; denetim kayıtları için Ajanda
- İşNet e-Fatura sözleşmesi ve test ortamı erişimi (İşNet, IP + VKN ile kimlik doğrular; kullanıcı/şifre yoktur)
- Sunucunuzun **sabit çıkış IP'si** (canlı ortamda İşNet firewall'una tanımlanır)

## Adımlar

### 1. Dosyalar

```
htdocs/custom/isnetefatura/
```
klasörüne kopyalayın. `conf/conf.php` içinde `$dolibarr_main_url_root_alt='/custom'` ve `$dolibarr_main_document_root_alt` tanımlı olmalı (standart kurulumda vardır).

### 2. Modülü aktif edin

Kurulum → Modüller/Uygulamalar → Finans grubunda **İşNet e-Fatura** → aç.

Aktivasyonda otomatik oluşanlar:
- Tablolar: `llx_isnetefatura_document` (gönderim denemeleri), `llx_isnetefatura_incoming` (gelen belgeler), `llx_isnetefatura_code` (GİB kod listeleri)
- Ek alanlar: cari (e-Fatura durumu, posta kutusu, e-İrsaliye posta kutusu), fatura (senaryo, tip, istisna, tevkifat, ihracat, internet satışı, irsaliye no, e-belge no/durumu), ürün (GTİP, menşei), sevkiyat (plaka, sürücü, taşıyıcı)
- Cron görevleri: `IsnetCronRefreshStatuses` (saatlik), `IsnetCronSyncIncoming` (saatlik)
- Fatura ve sevkiyat kartlarına sekme, Faturalama menüsüne **e-Faturalar** bölümü (Gönderilen/Gelen e-Faturalar, Gönderilen/Gelen e-İrsaliyeler)
- Çeviri override: Prof ID 1–4 etiketleri Türkçe (kapatılabilir)

### 3. Şirket bilgileri

Kurulum → Şirket/Kuruluş:

| Alan | Değer |
|---|---|
| Prof ID 1 | Vergi No (10 hane) |
| Prof ID 2 | Vergi dairesi adı (örn. MECİDİYEKÖY VERGİ DAİRESİ) |
| Prof ID 3 | MERSİS no (opsiyonel) |
| Prof ID 4 | Ticaret sicil no (opsiyonel) |
| İl / İlçe | Sözlükten il, ilçe metin olarak |

Modül VKN'yi buradan okur; modül ayarındaki "Şirket VKN" yalnızca farklı bir VKN ile çalışılacaksa doldurulur.

### 4. Cariler

Her müşteri/tedarikçi kartında: Prof ID 1 = VKN veya TCKN, Prof ID 2 = vergi dairesi (VKN'li alıcılarda zorunlu, ayarla gevşetilebilir), ülke, il (state — seçim), ilçe (town — metin). Vergi dairesi ve ilçe adları GİB listesiyle eşleştirilir; "Mecidiyeköy VD" gibi kısaltmalar tanınır.

### 5. Zamanlanmış görevler

Dolibarr'ın cron altyapısı çalışıyor olmalı (Kurulum → Zamanlanmış görevler → son çalışma tarihleri dolu mu?). Klasik kurulumda sistem cron'u:

```
*/5 * * * * www-data /var/www/html/scripts/cron/cron_run_jobs.php <CRON_KEY> admin
```

**Docker (dolibarr/dolibarr imajı):** cron container'da yoktur. `docker-compose.yml`'e aynı imaj ve volume'lerle bir servis ekleyin:

```yaml
    cron:
        image: dolibarr/dolibarr:24.0.0
        restart: always
        depends_on: [mariadb, web]
        env_file: [.env]
        environment:
            DOLI_DB_HOST: mariadb
            DOLI_CRON: 1
        volumes:
            - dolibarr_documents:/var/www/documents
            - dolibarr_custom:/var/www/html/custom
```
`.env` içine `DOLI_CRON_KEY=<Kurulum → Zamanlanmış görevler → güvenlik anahtarı>` ve `DOLI_CRON_USER=admin`. Anahtar boşsa Dolibarr'da `CRON_KEY` sabitini tanımlayın.

### 6. Modül ayarları

Kurulum → Modüller → İşNet e-Fatura ⚙. İlk kurulumda:
1. Ortam **Test**, "Test ortamı VKN" = İşNet'in verdiği test firması VKN'si
2. **Bağlantı testi** → HealthCheck OK, kontör bakiyesi, şube listesi; bir test VKN'si sorgulayın
3. "GİB kod listeleri" → **Listeleri şimdi indir** (ilk gönderimde otomatik de iner)
4. Diğer bölümler: [ayarlar.md](ayarlar.md)

### 7. Test

Test firmasına (İşNet e-postasındaki VKN'ler) bir fatura kesip onaylayın; e-Fatura sekmesinde İşNet no ve ETTN görünmeli, birkaç dakika içinde durum "Oluşturuldu"ya geçmeli. Mükellef olmayan bir TCKN ile e-Arşiv, yurtdışı cari ile ihracat, sevkiyat ile e-İrsaliye deneyin.

### 8. Canlıya geçiş

1. Test ortamında **Başarıyla Tamamlandı** durumuna ulaşmış bir belgenin XML'ini (sekmedeki belge linkinden) ve sunucu çıkış IP'nizi İşNet destek adresine gönderin.
2. İşNet canlı URL'lerini ve firma tanımını yapar; URL'leri "InvoiceService URL (prod)" / "AddressBookService URL (prod)" alanlarına girin.
3. Firma logolu görüntü şablonunu İşNet'e tanımlatın ya da XSLT olarak modüle yükleyin.
4. Ortamı **Prod** yapın, "Test ortamı VKN"yi boşaltın. Test carilerini ve faturalarını temizleyin.
5. İlk gerçek faturaları muhasebe ekibiyle birlikte izleyin (Olaylar sekmesi + fatura listesi e-belge durumu).

## Güncelleme

Yeni sürümü aynı klasöre kopyalayın, modülü **kapatıp açın** (yeni ayar/ek alan/tablo değişiklikleri aktivasyonda uygulanır; `sql/update_*.sql` çalışır). Kullanıcı izinlerini kontrol edin (kapat-aç sırasında modül izinleri sıfırlanabilir).

## Kaldırma

Modülü kapatmak tabloları, ek alanları ve ayarları **silmez** (yeniden açınca veri korunur). Tamamen kaldırmak için tabloları ve `ISNETEFATURA_%` sabitlerini elle silin.
