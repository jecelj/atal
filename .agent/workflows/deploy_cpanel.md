---
description: Popoln vodič za deploy na cPanel (za začetnike)
---

# Deployment na cPanel: Korak za korakom (Za začetnike)

Ker to delaš prvič, sem postopek razdelil na 4 glavne dele. Sledi jim natančno.

## DEL 1: Priprava Kode (Lokalno)

Najprej moramo tvojo kodo spraviti na "oblak" (GitHub, GitLab ali Bitbucket). Priporočam **GitHub**.

1.  **Ustvari račun na GitHubu** (če ga nimaš).
2.  **Ustvari nov repozitorij** (Repository):
    *   Klikni "+" zgoraj desno -> "New repository".
    *   Ime: `atal-admin`.
    *   Visibility: **Private** (pomembno, da koda ni javna!).
    *   Klikni "Create repository".
3.  **Poveži lokalno kodo z GitHubom**:
    Odpri terminal v VS Code in vpiši (zamenjaj URL s svojim):

    ```bash
    # Inicializacija (če še nisi)
    git init
    
    # Dodaj vse datoteke
    git add .
    
    # Shrani spremembe (commit)
    git commit -m "Prva verzija"
    
    # Poveži z GitHubom (kopiraj URL iz GitHuba!)
    git remote add origin https://github.com/tvoje-uporabnisko-ime/atal-admin.git
    
    # Pošlji kodo gor
    git push -u origin main
    ```

## DEL 2: Priprava Baze (cPanel)

Bazo moraš ustvariti ročno v cPanelu.

1.  Prijavi se v **cPanel**.
2.  Klikni na **MySQL® Database Wizard** (Čarovnik za baze).
3.  **Step 1: Create A Database**:
    *   Ime: npr. `atal_admin` (končno ime bo `uporabnik_atal_admin`).
    *   Klikni "Next Step".
4.  **Step 2: Create Database Users**:
    *   Username: npr. `atal_user`.
    *   Password: Generiraj močno geslo in si ga **SHRANI**!
    *   Klikni "Create User".
5.  **Step 3: Add User to Database**:
    *   Obkljukaj **ALL PRIVILEGES**.
    *   Klikni "Next Step".
6.  **Pomembno**: Zapisi si:
    *   Ime baze (npr. `mojafirma_atal_admin`)
    *   Uporabniško ime (npr. `mojafirma_atal_user`)
    *   Geslo

## DEL 3: Prvi Deploy (SSH)

Zdaj bomo kodo prenesli na strežnik.

1.  **Poveži se preko SSH**:
    ```bash
    ssh uporabnik@tvoja-domena.com -p 22
    # Vnesi geslo cPanela (ko tipkaš, se nič ne vidi - to je normalno)
    ```

2.  **Pojdi v mapo poddomene**:
    ```bash
    cd public_html/yachts.atal.at
    ```

3.  **Kloniraj kodo**:
    Ker je repozitorij zaseben (Private), te bo vprašal za uporabniško ime in geslo.
    *   Username: Tvoj GitHub email.
    *   Password: **Tukaj ne vpišeš gesla, ampak "Personal Access Token"!**
        *   (Na GitHubu: Settings -> Developer settings -> Personal access tokens -> Tokens (classic) -> Generate new token -> Obkljukaj 'repo' -> Kopiraj kodo).

    ```bash
    # Pika na koncu je pomembna! (kloniraj v trenutno mapo)
    git clone https://github.com/tvoje-uporabnisko-ime/atal-admin.git .
    ```

4.  **Namesti knjižnice**:
    ```bash
    composer install --no-dev --optimize-autoloader
    npm install && npm run build
    ```

5.  **Nastavi okolje (.env)**:
    ```bash
    cp .env.example .env
    nano .env
    ```
    Uredi naslednje vrstice (premikaj se s puščicami):
    *   `APP_ENV=production`
    *   `APP_DEBUG=false`
    *   `APP_URL=https://yachts.atal.at`
    *   `DB_DATABASE=...` (ime baze iz DEL 2)
    *   `DB_USERNAME=...` (uporabnik iz DEL 2)
    *   `DB_PASSWORD=...` (geslo iz DEL 2)
    
    Shrani: `Ctrl+O`, `Enter`, `Ctrl+X`.

6.  **Finalizacija**:
    ```bash
    php artisan key:generate
    php artisan migrate --force
    php artisan storage:link
    ```

## DEL 4: Varnost (.htaccess)

Ker smo v `public_html`, moramo nujno dodati `.htaccess` (če ga nisi že poslal z Gitom).

Preveri, če obstaja:
```bash
ls -la .htaccess
```
Če ga ni, ga ustvari (ali pa ga dodaj lokalno in naredi `git push` ter na strežniku `git pull`).

---

## Kako posodobim v prihodnje?

Ko boš lokalno naredil spremembe:
1.  Lokalno: `git add .`, `git commit`, `git push`.
2.  Na strežniku (SSH):
    ```bash
    cd public_html/yachts.atal.at
    git pull
    composer install --no-dev (samo če si dodal nove pakete)
    php artisan migrate --force (samo če si spreminjal bazo)
    ```
