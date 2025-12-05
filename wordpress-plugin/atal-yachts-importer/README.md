# Atal Yachts Importer

Plugin za uvoz jaht iz centralnega CMS sistema z podporo za Polylang.

## Zahteve

- WordPress 5.0+
- PHP 7.4+
- Polylang plugin (nameščen in aktiviran)
- Advanced Custom Fields (ACF) - opcijsko, vendar priporočeno

## Namestitev

1. **Naložite plugin** v mapo `wp-content/plugins/atal-yachts-importer/`
2. **Aktivirajte plugin** v WordPress adminu (Plugins → Installed Plugins)
3. **Preverite, da je Polylang aktiviran** in konfiguriran z jeziki (npr. `en`, `sl`)

## Konfiguracija

### 1. Nastavitve uvoza

1. Pojdite v **Yachts Import** v WordPress admin meniju
2. Nastavite naslednje parametre:
   - **Base API URL**: URL do REST API endpointa na glavni strani (npr. `https://glavna-stran.si/wp-json/atal-sync/v1/export`)
   - **Filter po brandu**: Opcijsko - če želite filtrirati rabljene jahte po brandu (npr. `Beneteau`)
   - **Jeziki**: Seznam jezikov, ločenih z vejico (npr. `en,sl`)
   - **En post za vse jezike**: Če je omogočeno, se ustvari samo en post z vsemi jezikovnimi ACF polji (npr. `title_en`, `title_sl`). Če je onemogočeno, se ustvari ločen post za vsak jezik in poveže v Polylang.

3. Kliknite **Shrani nastavitve**

**Pomembno**: 
- **Ločeni posti** (privzeto): Ustvari ločen post za vsak jezik in jih poveže v Polylang. Jezik posta bo vidno nastavljen v Polylang.
- **En post**: Ustvari samo en post z vsemi jezikovnimi polji. Jezik posta bo nastavljen na prvi jezik iz seznama.

### 2. Struktura podatkov na strani 1

Plugin pričakuje, da API vrača podatke v naslednji strukturi:

```json
[
  {
    "id": 123,
    "type": "new_yachts",
    "title": {
      "rendered": "Yacht Title"
    },
    "acf": {
      "title_en": "Yacht Title EN",
      "title_sl": "Naslov jahte SL",
      "text_en": "Description in English",
      "text_sl": "Opis v slovenščini",
      "image": {...},
      "brand": "Beneteau",
      ...
    }
  }
]
```

**Pomembno**: ACF polja morajo imeti jezikovne sufikse (`_en`, `_sl`, itd.) za vsak jezik.

## Uporaba

### Ročni uvoz

1. Pojdite v **Yachts Import** v WordPress admin meniju
2. Kliknite **Import Yachts Now**
3. Počakajte, da se uvoz zaključi (preverite log spodaj)

### Avtomatski uvoz preko REST API

Plugin izpostavi REST endpoint za avtomatski uvoz:

```
GET /wp-json/atal-import/v1/run?key=API_KEY
```

**Primer:**
```
https://vasa-stran.si/wp-json/atal-import/v1/run?key=a8f3e29c7b1d45fa9831c442d2e5bbf3
```

### Debug endpointi

Plugin izpostavi naslednje debug endpointe:

#### Preverjanje podatkov iz API-ja
```
GET /wp-json/atal-import/v1/debug-api?lang=en
```

Vrne strukturo podatkov, ki jih vrača API za določen jezik.

## Kako deluje

### Polylang integracija

1. **Ločeni posti za vsak jezik**: Plugin ustvari ločen post za vsak jezik (npr. en post za angleščino, en za slovenščino)
2. **Povezovanje postov**: Posti so povezani preko Polylang sistema (`pll_translations` meta polje)
3. **ACF polja z jezikovnimi sufiksi**: Plugin prebere ACF polja z jezikovnimi sufiksi (`title_en`, `title_sl`, itd.) in jih shrani v pravilne poste

### Post type-i

Plugin registrira naslednje custom post type-e:
- `new_yachts` - Nove jahte
- `used_yachts` - Rabljene jahte

### ACF Field Groups sinhronizacija

Če je ACF nameščen, lahko sinhronizirate ACF field groups iz glavne strani:

1. V **Yachts Import** strani kliknite **Sinhroniziraj ACF Field Groups**
2. Plugin bo avtomatsko ustvaril field groups iz glavne strani

**Pomembno**: API na glavni strani mora izpostaviti endpoint `/wp-json/atal-sync/v1/export-fields` za eksport field groups.

## REST API

Vsi meta podatki so avtomatsko izpostavljeni v REST API:

```
GET /wp-json/wp/v2/new_yachts/{id}
GET /wp-json/wp/v2/used_yachts/{id}
```

Meta polja so dostopna v `meta` objektu in `acf` objektu (za ACF kompatibilnost).

### Debug REST API

Plugin izpostavi debug endpoint za preverjanje polj v postu:

```
GET /wp-json/atal-yootheme/v1/debug/{post_id}
```

**Primer odgovora:**
```json
{
  "post_id": 267,
  "post_title": "Test 1",
  "language": "en",
  "allowed_langs": ["en", "sl"],
  "lang_fields": {
    "title_en": "Test 1",
    "text_en": "Description in English"
  },
  "non_lang_fields": {
    "image": "245",
    "brand": "Beneteau"
  }
}
```

## YooTheme Integracija

Plugin omogoča popolno integracijo z YooTheme Builder za večjezične strani.

### Kako deluje

1. **Filtriranje polj**: Plugin avtomatsko filtrira REST API podatke glede na jezik posta in nastavitve izbranih jezikov
2. **Jezikovna polja**: Polja z jezikovnimi sufiksi (npr. `title_en`, `title_sl`) se avtomatsko izluščijo v osnovna polja (npr. `title`) za trenutni jezik posta
3. **Optimizacija**: V REST API se prikažejo samo polja za jezike, ki ste jih nastavili v "Jeziki" nastavitvah

### Nastavitev večjezičnosti z Polylang in YooTheme (Multi-Template pristop)

**Priporočeni pristop:** Ustvarite ločeno template za vsak jezik.

#### Hitri vodič po korakih:

1. **Za angleščino:**
   - V YooTheme Builder dodajte List/Grid element
   - Dynamic Content → Custom New Yachts
   - V Title izberite: `title_en`
   - V Text izberite: `text_en`
   - V Image izberite: `image`

2. **Za slovenščino:**
   - Ustvarite novo stran ali duplicate obstoječe
   - V YooTheme Builder dodajte List/Grid element
   - Dynamic Content → Custom New Yachts
   - V Title izberite: `title_sl`
   - V Text izberite: `text_sl`
   - V Image izberite: `image`

3. **Povežite s Polylang:**
   - Pojdite v Pages → All Pages
   - Ustvarite "Yachts" stran (EN) in "Jahte" stran (SL)
   - V Polylang jih povežite kot prevoda

#### Podrobnejša navodila:

#### 1. Nastavite Polylang

1. Namestite in aktivirajte **Polylang** plugin
2. V WordPress admin meniju pojdite v **Languages → Languages**
3. Dodajte jezike, ki jih potrebujete (npr. angleščina `en`, slovenščina `sl`)
4. V **Languages → Settings** nastavite:
   - **URL modifications**: Uporabite "The language is set from the directory name in pretty permalinks" ali drugo opcijo po želji
   - **Hide default language in URL**: Po želji
   - **Synchronizations**: Omogočite sinhronizacijo, ki jo potrebujete (priporočamo samo "Taxonomies")

#### 2. Nastavite plugin Atal Yachts Importer

1. V **Yachts Import** nastavite **Jeziki** na jezike, ki jih uporabljate v YooTheme (npr. `en,sl`)
   - **Pomembno**: Nastavite SAMO jezike, ki jih potrebujete na strani 2 (ne vseh 6 jezikov iz strani 1)
   - Če uporabljate samo angleščino in slovenščino, nastavite `en,sl`
   - To bo filtriralo REST API in prikazalo samo polja za te jezike

2. Izberite način uvoza:
   - **Ločeni posti** (privzeto, **priporočeno za YooTheme**): Ustvari ločen post za vsak jezik
   - **En post**: Ustvari samo en post z vsemi jezikovnimi polji (manj priporočeno)

3. Zaženite **Import Yachts Now**

#### 3. Nastavite YooTheme Builder

##### A. Uporaba z Dynamic Content (priporočeno)

1. V YooTheme Builder odprite stran/predlogo za prikaz jaht
2. Dodajte element (npr. Headline, Text, Image)
3. Kliknite na ikono **Dynamic Content** (ƒx) pri polju
4. Izberite **Post** → **Custom Field**
5. Vnesite ime polja **brez** jezikovnega sufiksa (npr. `title`, `text`, `image`)
   - Plugin avtomatsko izlušči pravilno vrednost za trenutni jezik posta
6. Shranite spremembe

**Primer:** Če želite prikazati naslov jahte:
- Polje: `title` (NE `title_en` ali `title_sl`)
- Plugin bo avtomatsko prikazal `title_en` za angleški post in `title_sl` za slovenski post

##### B. Direkten dostop do jezikovnih polj

Če potrebujete direkten dostop do specifičnega jezikovnega polja:

1. V Dynamic Content vnesite polno ime polja z jezikovnim sufiksom (npr. `title_en`)
2. To bo vedno prikazalo vrednost za ta jezik, ne glede na jezik posta

#### 4. Prikaz prevoda v YooTheme

1. **Polylang Language Switcher**: Dodajte Polylang language switcher v navigacijo
   - V **Appearance → Menus** ustvarite nov meni
   - Dodajte jezik switcher widget v header preko YooTheme Builder widgets
   - Uporabite **Language Switcher** widget iz Polylang

2. **Prevod strani**: Polylang avtomatsko preusmeri na pravilno različico posta glede na izbrani jezik

### Primeri uporabe v YooTheme

#### Primer 1: Prikaži seznam jaht z naslovi in opisi

```
YooTheme Element: List
- Dynamic Content: Post Query → new_yachts (filter by current language)

Za vsak item:
- Headline → Dynamic Content → Custom Field: title
- Text → Dynamic Content → Custom Field: text
- Image → Dynamic Content → Custom Field: image
```

#### Primer 2: Prikaži podrobnosti jahte

```
YooTheme Single Post Template:
- Headline → Dynamic Content → Post Title (ali Custom Field: title)
- Text → Dynamic Content → Custom Field: text
- Image → Dynamic Content → Custom Field: image
- List → Dynamic Content → Custom Field: specifications
```

### Preverjanje delovanja

1. **Preverite jezike postov**: 
   - Pojdite v **New Yachts** ali **Used Yachts** v WordPress admin
   - Preverite stolpec "Language" - vsak post mora imeti nastavljen jezik (npr. EN, SL)
   - Če izberete jezik v filtru (header), se prikažejo samo posti za ta jezik

2. **Preverite REST API**:
   - Odprite `/wp-json/wp/v2/new-yachts/{post_id}` v brskalniku
   - Preverite, ali so v `acf` in `meta` objektu vidna samo polja za izbrane jezike
   - Primer: če ste nastavili `en,sl`, bi morali videti `title_en`, `title_sl`, `title`, `image`, itd.

3. **Debug endpoint**:
   - Odprite `/wp-json/atal-yootheme/v1/debug/{post_id}`
   - Preverite seznam vseh polj, jezikovnih polj in nastavitve

4. **YooTheme Builder**:
   - Odprite YooTheme Builder
   - Dodajte element z Dynamic Content → Custom Field
   - Vnesite `title` ali drugo polje
   - Predogled: preverite, ali se prikaže pravilna vrednost za trenutni jezik

### YooTheme Gallery Shortcode

Plugin vključuje **shortcode** za prikaz ACF Gallery polj v YooTheme Builder.

#### Zakaj shortcode?
- YooTheme ne dovoli PHP kode direktno v elementih
- Omogoča repeatable/grid funkcionalnost
- 100% BREZPLAČNO - deluje z ACF Gallery 4 (ne potrebujete ACF PRO)

#### Osnovna uporaba:

```
[atal_gallery field="gallery_exterior" columns="4" gap="small" lightbox="1"]
```

#### Uporaba v YooTheme Builder:

1. **Dodajte "Text" element** v YooTheme Builder
2. **Kliknite na element** → Content tab
3. **Vnesi shortcode:**
   ```
   [atal_gallery field="gallery_exterior" columns="4" gap="small" lightbox="1"]
   ```
4. **Save**

#### Parametri:

| Parameter | Opis | Vrednosti | Default |
|-----------|------|-----------|---------|
| `field` | Ime ACF gallery polja | `gallery_exterior`, `gallery_interior` | **OBVEZNO** |
| `columns` | Število stolpcev | `2`, `3`, `4`, `5`, `6` | `4` |
| `gap` | Razmik med slikami | `small`, `medium`, `large` | `small` |
| `lightbox` | Omogoči lightbox | `1` (da), `0` (ne) | `1` |

#### Primeri:

```
[atal_gallery field="gallery_exterior" columns="4" gap="small" lightbox="1"]
[atal_gallery field="gallery_interior" columns="3" gap="medium" lightbox="1"]
[atal_gallery_image field="gallery_exterior" index="0" size="large"]
```

**📋 Za podrobna navodila glejte:** `YOOTHEME-SHORTCODE-NAVODILA.md`

### Pogosta vprašanja

**Q: Zakaj v REST API vidim samo `image`, ne pa tudi `title_en`, `title_sl`, itd.?**

A: To pomeni, da polja še niso shranjena v postu. Rešitev:
1. Preverite, ali plugin pravilno uvaža podatke z debug endpointom `/wp-json/atal-yootheme/v1/debug/{post_id}`
2. Zaženite uvoz znova (**Import Yachts Now**)
3. Po ponovnem uvozu bi morala biti polja vidna

**Q: Kako YooTheme ve, katero jezikovno polje uporabiti?**

A: Plugin avtomatsko filtrira REST API podatke in izlušči polja za trenutni jezik posta. Če je post v angleščini (`en`), bo `title` vseboval vrednost iz `title_en`.

**Q: Ali moram v YooTheme uporabljati `title_en` ali `title`?**

A: Priporočamo uporabo `title` (brez sufiksa), ker plugin avtomatsko izlušči pravilno vrednost. Če uporabljate `title_en`, bo vedno prikazal angleško vrednost, tudi na slovenski strani.

**Q: Ali lahko prikažem prevod na isti strani (npr. EN in SL naslov skupaj)?**

A: Ne direktno. Če želite prikazati več jezikov hkrati, morate uporabiti "En post za vse jezike" način in nato v YooTheme dostopati do obeh polj (`title_en` in `title_sl`).

**Q: Zakaj so ustvarjeni 2 posta za vsako jahto?**

A: To je normalno vedenje za Polylang. Vsak jezik ima svoj post, ki ga Polylang poveže. Če želite samo en post, omogočite "En post za vse jezike" v nastavitvah.

**Q: Ali deluje s Polylang Pro?**

A: Da, plugin je kompatibilen z Polylang in Polylang Pro.

## Troubleshooting

### Uvoz ne deluje

1. Preverite, ali je API URL pravilno nastavljen
2. Preverite, ali API vrača podatke (uporabite `/debug-api` endpoint)
3. Preverite error log v WordPressu (`wp-content/debug.log`)

### Jeziki niso vidni v Polylang

1. **Preverite, ali je Polylang aktiviran**: Plugin mora biti aktiviran in konfiguriran
2. **Preverite jezike v Polylang**: 
   - Pojdite v Languages → Languages v WordPress adminu
   - Preverite, ali so jeziki (npr. `en`, `sl`) pravilno nastavljeni
   - Jezikovne kode morajo biti enake kot v nastavitvah uvoza
3. **Preverite error log**: Preverite, ali plugin pravilno nastavlja jezike postov
4. **Preverite admin stran**: V Yachts Import strani boste videli razpoložljive jezike v Polylang

### Posti niso povezani v Polylang

1. Preverite, ali je Polylang aktiviran
2. Preverite, ali so jeziki pravilno nastavljeni v Polylang nastavitvah
3. Preverite, ali so jeziki v nastavitvah uvoza enaki kot v Polylang
4. **Pomembno**: Če uporabljate "En post za vse jezike", posti se ne bodo povezali, ker je samo en post

### ACF polja niso vidna

1. Preverite, ali je ACF nameščen
2. Sinhronizirajte ACF field groups (gumb v admin strani)
3. Preverite, ali so polja pravilno registrirana v ACF

### Dva posta namesto enega

Če vidite dva posta namesto enega:
1. Preverite, ali je omogočen "En post za vse jezike" v nastavitvah
2. Če je omogočen, se mora ustvariti samo en post
3. Če ni omogočen, je to normalno - Polylang zahteva ločene poste za vsak jezik

## API ključ

API ključ je definiran v `atal-yachts-importer.php`:

```php
define('ATAL_IMPORT_API_KEY', 'a8f3e29c7b1d45fa9831c442d2e5bbf3');
```

**Varnost**: Spremenite ta ključ v produkciji!

## Podpora

Za vprašanja ali težave kontaktirajte Atal System.

