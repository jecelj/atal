#!/bin/bash

# Skripta za pregled Laravel logov na produkciji

LOG_DIR="storage/logs"

echo "=== Pregled Laravel logov ==="
echo ""

# Preveri, ali obstaja log datoteka
if [ -f "$LOG_DIR/laravel.log" ]; then
    echo "📄 Najdena datoteka: $LOG_DIR/laravel.log"
    echo ""
    echo "=== Zadnjih 50 vnosov ==="
    tail -n 50 "$LOG_DIR/laravel.log"
    echo ""
    echo "=== Zadnja napaka (ERROR) ==="
    grep -i "ERROR" "$LOG_DIR/laravel.log" | tail -n 20
    echo ""
    echo "=== Zadnja izjema (Exception) ==="
    grep -i "exception" "$LOG_DIR/laravel.log" | tail -n 20
else
    echo "⚠️  Log datoteka ni najdena: $LOG_DIR/laravel.log"
    echo ""
    echo "Preverjam daily log datoteke..."
    ls -lah "$LOG_DIR"/laravel-*.log 2>/dev/null | tail -n 5
fi

echo ""
echo "=== Velikost log datotek ==="
du -h "$LOG_DIR"/*.log 2>/dev/null | tail -n 10

