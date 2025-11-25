# Preverjanje Uvoza Slik

## SQL Query - Preveri Število Slik

```sql
-- Preveri število medijev za določen yacht
SELECT 
    collection_name, 
    COUNT(*) as count 
FROM media 
WHERE model_type = 'App\\Models\\NewYacht' 
  AND model_id = YOUR_YACHT_ID 
GROUP BY collection_name 
ORDER BY collection_name;
```

## SQL Query - Preveri Datoteke

```sql
-- Preveri dejanske datoteke
SELECT 
    id,
    collection_name, 
    file_name,
    size,
    created_at
FROM media 
WHERE model_type = 'App\\Models\\NewYacht' 
  AND model_id = YOUR_YACHT_ID 
ORDER BY collection_name, id;
```

## Preveri Fizične Datoteke

```bash
# Preveri število datotek v storage
ls -la storage/app/public/media/

# Preveri velikost storage direktorija
du -sh storage/app/public/media/
```

## Preveri Laravel Log

```bash
# Zadnjih 100 vrstic loga
tail -100 storage/logs/laravel.log | grep "Media uploaded"

# Preveri download napake
tail -100 storage/logs/laravel.log | grep "Failed to download"
```

## Pričakovani Rezultati za 800 FLY

- **gallery_exterior**: 12 slik
- **gallery_interrior**: 36 slik
- **gallery_cockpit**: 18 slik
- **gallery_layout**: 4 slike
- **cover_image**: 1 slika
- **grid_image**: 1 slika
- **grid_image_hover**: 1 slika
- **pdf_brochure**: 1 PDF

**Skupaj: 74 datotek**
