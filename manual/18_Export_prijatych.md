# 18. Export přijatých faktur (naše PDF / ISDOC / Pohoda)

Přijaté faktury (viz [Přijaté faktury](17_Prijate_faktury.md)) lze předat účetní
ve třech formátech — per doklad i hromadně za měsíc nebo celé čtvrtletí.

V detailu přijaté faktury najdeš tlačítko **„Exporty"** s dropdown menu:

### Naše PDF (rekonstrukce)
Vygeneruje naši vlastní PDF kopii ze strukturovaných dat. Užitečné když:
- Importovaly se jen metadata (z iDokladu/Fakturoidu API, ne originální PDF)
- Originál není dostupný (přijatá faktura zadaná ručně)
- Potřebuješ čitelný PDF pro účetní archiv

PDF obsahuje hlavičku s dodavatelem, položky, totals, poznámky. Footer poznámka:
*„Naše rekonstrukce přijaté faktury z dat v MyInvoice.cz. Originál od dodavatele je
referenční dokument."*

### ISDOC XML
Export do ISDOC 6.0 standardu — kompatibilní s Pohoda, Money S3, iDoklad a dalšími.
Strategie: **role inversion** — v ISDOC pro přijatou fakturu je *dodavatel* =
původní vendor, *zákazník* = naše firma (opak vystavené).

### Pohoda XML
Pohoda dataPack XML pro import do účetního software Pohoda. Direction =
purchase (`<pur:purchase>` místo `<inv:invoice>`).

### Hromadný export za období

V hlavním menu **Přijaté faktury → Exporty** vyber období + formát:

- **PDF ZIP** — preferuje archivovaný **originál** od dodavatele (`Prijata-{vs}-{vendor}.pdf`); pokud originál chybí, doplní se **naše rekonstrukce** z dat faktury (`…-rekonstrukce.pdf`, ať ji účetní pozná). Faktura se přeskočí jen když selže i rekonstrukce.
- **ISDOC ZIP** — jeden `.isdoc` XML soubor za fakturu, sbaleno do ZIP.
- **Pohoda XML** — sloučený `<dataPack>` se všemi fakturami za zvolené období (přímý import do Pohody, direction = purchase).

Období může být jeden měsíc nebo celé čtvrtletí (`Q1` až `Q4`). „Datum dle"
volí, podle kterého data se faktura zařadí do zvoleného období: DUZP (tax),
datum vystavení (issue, default) nebo datum přijetí (received).

> [!TIP]
> Export **vystavených** faktur (PDF ZIP / ISDOC / Pohoda) řeší kapitola
> [Exporty](15_Exporty.md) v sekci Prodej. Kompletní měsíční balíček vystavených
> i přijatých faktur naráz nabízí [Měsíční export](32_Mesicni_export.md) v sekci Daně.
