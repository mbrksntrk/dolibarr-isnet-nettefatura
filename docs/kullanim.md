# Günlük Kullanım

## Müşteri faturası (e-Fatura / e-Arşiv)

1. Faturayı her zamanki gibi oluşturun. Gerekirse ek alanları doldurun (çoğu zaman gerekmez):
   - **KDV %0 satır** varsa istisna kodu (ya da modül varsayılanı)
   - **Tevkifatlı** iş: tevkifat kodu seçin (oran otomatik, gerekirse ezin)
   - **Kâğıt irsaliye** referansı: irsaliye no/tarihi (Dolibarr sevkiyatları otomatik eklenir)
   - **İnternet satışı** (e-Arşiv): kutuyu işaretleyin, ödeme şekli/tarihi, kargo
   - **Kamu kurumu**: senaryo = KAMU
2. **Onayla.** Modül ön kontrol yapar; sorun varsa (VKN yok, vergi dairesi eşleşmiyor, GTİP eksik…) mesajla listeler. Ayara göre ya onay engellenir ya da fatura "Gönderilemedi" işaretlenir.
3. Fatura kartı → **e-Fatura** sekmesi:
   - alıcı özeti, senaryo/tip, ön kontrol uyarıları
   - deneme geçmişi: İşNet fatura no, ETTN, durum rozeti (**Oluşturuldu** = imzalandı ve iletildi, **Tamamlandı** = GİB/alıcı onayı, **İşleniyor**, **Başarısız**, **İptal edildi**, **Gönderilemedi**)
   - ↻ durumu yenile, 🔗 belgeyi İşNet görüntüleyicide aç, ⬇ PDF'i belgelere kaydet
   - e-Arşiv için ✉ e-postayı tekrar gönder, 🗑 iptal (GİB raporu kabul edilmeden önce)
   - **Tekrar gönder** yalnızca son deneme başarısızsa aktiftir (mükerrer belge koruması)
4. Fatura listesinde **e-Belge no** ve **e-Belge durumu** sütunlarını açın (liste alanları seçici).
5. **Olaylar** sekmesinde her gönderim, durum değişimi, PDF, iptal kaydı görünür.

Durum kodları hakkında: e-Arşiv belgeleri GİB'e günlük toplu raporla bildirilir; `Fatura_Olusturuldu / Gibe_Otomatik_Raporlanacaktir` başarılı düzenlenmiş belge demektir, ertesi gün `Gib_Raporu_Kabul_Etti`ye döner. e-Fatura'da `Gibe_Iletildi` GİB'e teslim edilmiştir; alıcının sistemi yanıt verince `Basariyla_Tamamlandi`.

## Alacak dekontu (iade)

- Alıcı **e-Fatura mükellefi** ise satıcı iade e-Faturası düzenleyemez (GİB kuralı: iadeyi alıcı keser). Dolibarr'daki alacak dekontu onaylanır ama gönderilmez; sekmede açıklama görünür.
- Alıcı **mükellef değilse** alacak dekontu e-Arşiv **İADE** olarak gider (kaynak faturanın İşNet numarasıyla).

## İhracat

- Yurtdışı cari + **ürün** satırları → mal ihracatı: e-Fatura, alıcı Ticaret Bakanlığı, gerçek müşteri "ihracat alıcısı" bloğunda. Gerekli: ürün kartında **GTİP** (12 hane) ve menşei, faturada **Incoterm**, taşıma şekli / kap cinsi (varsayılan var).
- Yurtdışı cari + **hizmet** satırları → yurtdışı e-Arşiv, istisna 302.
- Mal ve hizmet aynı faturada olamaz (ayrı faturalar).
- Tüm satırlar KDV %0.

## Sevkiyat (e-İrsaliye)

1. Sevkiyatı oluşturun; sevkiyat ek alanlarına plaka, sürücü (ad, soyad, TCKN), varsa taşıyıcı firma girin — boşsa modül varsayılanları.
2. **Onayla** → e-İrsaliye gönderilir (ayar). Sevkiyat kartı → **e-İrsaliye** sekmesi: aynı rozet/ikonlar.
3. Alıcı e-İrsaliye mükellefi değilse gönderilmez, uyarı verir (kâğıt irsaliye).

## Gelen belgeler (Faturalama → Gelen e-Faturalar)

- **Gelen e-Faturalar:** saatte bir senkronlanır ("Şimdi senkronize et" de var). Her satırda gönderici, tutarlar, İşNet durumu, yanıt, bağlı tedarikçi faturası.
  - **Aktar:** taslak tedarikçi faturası (satırlar, KDV, döviz, İşNet PDF'i ekli) → tedarikçi faturası kartına gider; ürün eşleme, muhasebe kodu, onay, ödeme Dolibarr'da devam eder. Gönderici VKN'si hiçbir caride yoksa tedarikçi otomatik açılır (ayar).
  - Ticari faturalarda ✓ **Kabul** / ✗ **Reddet** (gerekçeyle) — 8 gün içinde GİB uygulama yanıtı.
- **Gelen e-İrsaliyeler:** liste + **Alındı yanıtı gönder** (tüm miktarlar alındı).

## Kontrol listesi — gönderim engellendiyse

| Mesaj | Yapılacak |
|---|---|
| Alıcı VKN/TCKN geçersiz | Cari → Prof ID 1 (10/11 hane) |
| Vergi dairesi boş / GİB listesinde bulunamadı | Cari → Prof ID 2; resmi ad ("X VERGİ DAİRESİ") ya da kod |
| Alıcının ili belirlenemedi | Cari → İl (state) seçin |
| KDV %0 satır var ama istisna kodu yok | Faturaya istisna kodu ya da modül varsayılanı |
| Üründe GTİP yok / Incoterm yok | Ürün kartı GTİP; fatura Incoterm |
| Mal ve hizmet karışık (yurtdışı) | Ayrı faturalar |
| Alıcı e-İrsaliye mükellefi değil | Kâğıt irsaliye |
| Bu fatura zaten gönderilmiş | Normal; tekrar gönderim yalnız başarısızda |
