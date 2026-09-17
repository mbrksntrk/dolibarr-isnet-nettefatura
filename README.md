# Dolibarr İşNet e-Fatura / e-Arşiv / e-İrsaliye Modülü

Dolibarr ERP/CRM için **İşNet (Nette Fatura)** özel entegratörü üzerinden Türkiye GİB e-belge entegrasyonu.
Topluluk projesidir; İşNet veya GİB ile resmi bir bağı yoktur.

| | |
|---|---|
| Dolibarr | 20.0+ (24.0 üzerinde geliştirildi ve test edildi) |
| PHP | 8.1+, `soap`, `curl`, `mbstring` eklentileri |
| Lisans | GPL-3.0-or-later + yazar atfı şartı ([ATTRIBUTION.md](ATTRIBUTION.md)) |
| Sürüm | 0.4.0 |

## Neler yapıyor

**Giden belgeler**
- Müşteri faturası onaylanınca otomatik **e-Fatura** veya **e-Arşiv** (alıcı GİB mükellef listesinde sorgulanır; karar otomatik)
- Temel / ticari senaryo, KAMU senaryosu, **ihracat** (mal → Ticaret Bakanlığı e-Faturası, hizmet → yurtdışı e-Arşiv), **tevkifat** (601–627 kod sözlüğü), e-Arşiv **iade**, **internet satışı** bilgileri, döviz (kur + TL karşılığı), irsaliye referansı, IBAN, Dolibarr PDF eki, firma XSLT/logo şablonu
- Sevkiyat onaylanınca **e-İrsaliye** (sürücü, plaka, taşıyıcı; e-İrsaliye mükellef kontrolü)
- Durum takibi (saatlik cron), imzalı PDF'in Dolibarr belgelerine kaydı, e-Arşiv iptali ve e-posta tekrar gönderimi
- Fatura/sevkiyat kartında ayrı sekme; fatura listesinde e-belge no ve durumu

**Gelen belgeler**
- Tedarikçi **e-Faturaları** listelenir, tek tıkla taslak tedarikçi faturasına aktarılır (cari otomatik açılır, PDF eklenir), ticari faturalara **kabul/red** yanıtı
- Gelen **e-İrsaliyeler** listelenir, **alındı yanıtı** gönderilir

**Altyapı**
- GİB il / ilçe / vergi dairesi kod listeleri yerel önbellekte; cari kartındaki serbest metinden kod çözümleme
- Türkçe Prof ID etiketleri (Vergi No / Vergi Dairesi / MERSİS / Ticaret Sicil)
- Her işlem için **denetim kaydı**: belge olayları Dolibarr Olaylar sekmesinde, ayar değişiklikleri güvenlik denetim günlüğünde
- Tüm davranışlar **ayarlardan** yönetilir; kodda şirkete özgü hiçbir değer yoktur

## Hızlı kurulum

1. Modül klasörünü `htdocs/custom/isnetefatura/` altına kopyalayın (ya da ZIP'i Kurulum → Modüller → Harici modül yükle).
2. Kurulum → Modüller → **İşNet e-Fatura** → aktif edin. Tablolar, ek alanlar, cron görevleri ve çeviri override'ları otomatik kurulur.
3. Kurulum → Şirket: **Prof ID 1 = VKN**, **Prof ID 2 = Vergi Dairesi** (modül bu konvansiyonu bekler; etiketler otomatik Türkçeleşir).
4. Modül ayarları (⚙): ortam **Test**, İşNet'in verdiği test VKN'sini "Test ortamı VKN" alanına yazın, **Bağlantı testi** yapın.
5. Cari kartlarında VKN/TCKN (Prof ID 1), vergi dairesi (Prof ID 2), il (state) ve ilçe (town) dolu olsun.
6. Dolibarr zamanlanmış görevlerinin çalıştığından emin olun (`cron_run_jobs.php`); Docker kurulumları için [docs/kurulum.md](docs/kurulum.md).
7. Canlıya geçiş: sunucunuzun çıkış IP'sini İşNet'e bildirin, prod URL'lerini girin, ortamı **Prod** yapın.

Ayrıntılar: [Wiki](https://github.com/mbrksntrk/dolibarr-isnet-nettefatura/wiki) ve [docs/](docs/) klasörü — kurulum, ayarlar, günlük kullanım, ihracat/tevkifat/e-İrsaliye, İşNet API notları, SSS.

## Ekran akışı (özet)

```
Fatura taslağı → Onayla
   ├─ ön kontrol (VKN, vergi dairesi, il/ilçe kodu, istisna kodu, ihracat verileri…)
   ├─ GetTaxPayer → e-Fatura mı e-Arşiv mi?
   ├─ SendInvoice / SendArchiveInvoice → İşNet no + ETTN faturaya işlenir
   └─ Olaylar sekmesine "EFATURA gönderildi: UUU2026…" kaydı

Saatlik cron → SearchInvoice / SearchArchiveInvoice / SearchDespatchAdvice
   ├─ durum: Oluşturuldu / Tamamlandı / Başarısız / İptal
   └─ imzalı PDF → faturanın Belgeler alanına
```

## Kapsam dışı (bilinçli)

e-SMM (serbest meslek makbuzu), e-Döviz (döviz büroları), İşNet portal arşiv/şube yönlendirme işlemleri, XML-tabanlı gönderim varyantları (UBL'yi İşNet üretir). Gerekçeler [docs/isnet-api-notlari.md](docs/isnet-api-notlari.md) içinde.

## MCP araçları (Dolibarr 24 AI modülü)

Modül, AI modülünün MCP sunucusuna araçlar ekler (Kurulum → AI → araç yapılandırması; MCP kullanıcısının Dolibarr/modül yetkileriyle çalışır, yazma araçları *gönder* yetkisi ister ve asistan önce onay sorar):

| Araç | Ne yapar |
|---|---|
| `isnet_invoice_status` | Faturanın e-belge durumu: alıcı kararı, ön kontrol sorunları, tüm denemeler (no, ETTN, durum, hata) |
| `isnet_send_invoice` | Onaylı faturayı gönder (force ile yeni belge) |
| `isnet_refresh_status` | İşNet'ten durumu yenile, PDF indir |
| `isnet_list_documents` | Sonuca göre belge listesi: başarısız / bekleyen / tamamlanan / iptal |
| `isnet_check_taxpayer` | VKN/TCKN e-Fatura & e-İrsaliye mükellefi mi, posta kutuları |
| `isnet_list_incoming` | Gelen e-Fatura / e-İrsaliye listesi (aktarılmamışlar filtresi) |
| `isnet_sync_incoming` | Gelen belgeleri şimdi senkronize et |
| `isnet_import_incoming` | Gelen e-Faturadan taslak tedarikçi faturası oluştur |
| `isnet_reply_incoming` | Ticari e-Faturaya KABUL / RED yanıtı |
| `isnet_balance` | Ortam, gönderici VKN, kontör, sağlık kontrolü |

Örnekler: "Bu ay İşNet'e gidemeyen faturalar hangileri?", "IN2609-0016 GİB'e ulaştı mı?", "4810173324 e-Fatura mükellefi mi?", "Aktarılmamış gelen faturaları listele ve 9 numaralıyı aktar."

## Kurulum paketi (DoliStore / harici modül)

Sürüm ZIP'leri [Releases](https://github.com/mbrksntrk/dolibarr-isnet-nettefatura/releases) sayfasında (`module_isnetefatura-x.y.z.zip`). Kurulum → Modüller → **Harici modül yükle** ile kurulur; `htdocs/custom/isnetefatura/` altına açılır.

## Katkı

Sorun ve öneriler için [GitHub Issues](https://github.com/mbrksntrk/dolibarr-isnet-nettefatura/issues). Kod stili Dolibarr standardı (tab girinti, `dol_*` yardımcıları, çekirdeğe dokunmadan hook/trigger/extrafield). Geliştirme akışı ve test verileri [docs/gelistirme.md](docs/gelistirme.md).

## Lisans

GPL-3.0-or-later ([COPYING](COPYING)). GPLv3 7(b) maddesi kapsamında ek şart: yazar atfı korunmalıdır — ayrıntı [ATTRIBUTION.md](ATTRIBUTION.md). Ücretsiz ve ticari kullanım, değiştirme ve dağıtım serbesttir.

## Yazar

**M. Burak Şentürk**
- Web: [buraksenturk.net](https://buraksenturk.net)
- E-posta: mburaksenturk@gmail.com
- GitHub: [@mbrksntrk](https://github.com/mbrksntrk)
- Proje: [github.com/mbrksntrk/dolibarr-isnet-nettefatura](https://github.com/mbrksntrk/dolibarr-isnet-nettefatura)

Bu proje İşNet, Nette Fatura veya GİB ile bağlantılı değildir; markalar sahiplerine aittir.

---

**English:** Dolibarr module for Turkish GİB e-invoicing (e-Fatura, e-Arşiv, e-İrsaliye) through the İşNet integrator. Outgoing documents on invoice/shipment validation with automatic e-Fatura/e-Arşiv decision, export/withholding/return/online-sale scenarios, status tracking and signed PDF storage; incoming supplier invoices and despatch advices with import and reply; MCP tools for the Dolibarr 24 AI module. Everything is configurable from the module setup page; every operation is audited. Documentation is in Turkish under `docs/` and the wiki. Author: M. Burak Şentürk — https://buraksenturk.net. License: GPL-3.0-or-later with an attribution-preservation term (see ATTRIBUTION.md).
