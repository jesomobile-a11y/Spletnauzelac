# uzelac.si

Spletna stran odvetnice Anje Uzelac (en `index.html` + `contact.php`).
Vsak `push` na vejo `main` samodejno naloži datoteke na Domenco prek FTP (`.github/workflows/deploy.yml`).

Potrebne skrivnosti (Settings > Secrets and variables > Actions): `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
