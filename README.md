# Divotraily – web

Web cyklotrailov v lesoparku Brezina (Trenčín) – DIVO občasné združenie o.z.
Statický web (jeden `index.html`) + jednoduché PHP API (`api.php`) pre hosting Websupport.

## Štruktúra
- `index.html` – celý web (obrázky a hra Divočiak sú vložené priamo v súbore)
- `api.php` – brigády, evidencia brigádnikov a tričká, prihlášky členov, dopyt na merch,
  redakčný systém (obsah webu v `data/content.json`), nahrávanie súborov, rebríček hry
- `config.sample.php` – vzor tajných nastavení → na serveri skopíruj ako `config.php`
- `game/` – samostatná verzia hry (záloha; web používa hru vloženú v index.html)
- `img/`, `mapy/`, `pdf/` – obrázky, mapy a PDF na stiahnutie
- `data/` – dáta servera (zápisy, obsah z redakcie, prihlášky). V gite je len `.htaccess`.
- `uploads/` – súbory nahraté v redakcii. V gite je len `.htaccess`.

## Nasadenie
1. Nahraj obsah repozitára do koreňa webu (public_html).
2. Na serveri vytvor `config.php` podľa `config.sample.php` (heslá rolí, tajný kľúč, SMTP heslo).
3. Priečinky `data/` a `uploads/` musia byť zapisovateľné pre PHP (755/775).
4. Podrobný návod: `CITAJ-MA.txt`.

## Bezpečnosť
- `config.php`, obsah `data/` a `uploads/` sa do gitu nedávajú (pozri `.gitignore`) – sú v nich heslá a osobné údaje.
- `index.html` obsahuje dočasné heslá pre náhľad v Claude (artefakt). Na hostingu prihlásenie overuje server,
  preto v `config.php` použi INÉ heslá – tie v index.html potom na hostingu nefungujú.
