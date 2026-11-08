# BrainForge

Learning games for school, made to run on any web host: the 46 countries of Europe with their flags, outlines and capitals (and, from version 2.0, the multiplication tables).
Made for exam preparation at school (7th grade): pupils practise on their phone or computer, one question at a time, with immediate feedback and the correction. The quiz is available in **German and French**; pupils switch the language on the start page or in the footer.

## Features

- **Flags** (*Flaggen*): see a flag, type the country and its capital
- **Outlines** (*Umrisse*) in three levels
  - *Leicht*: choose the country from 4 names
  - *Mittel*: choose the matching flag from 4 flags
  - *Schwer*: type the country
- Small states (Andorra, Liechtenstein, Monaco, San Marino, Vatican City) appear as a map excerpt with their position marked
- Answer options from neighbouring countries or similar flags, shuffled anew every time
- Tolerant answer checking: umlauts, case and small typos don't matter, words that sound the same count (Lichtenstein, Warshau), the correct spelling is always shown
- *Kurz* (15 random countries) or *Komplett* (all 46)
- Results stored in SQLite; evaluation at the end with the place in the ranking
- Public rankings per game type, level and length, top 5 on the start page, CSV export
- German and French, including country and capital names and the answer checking; the choice is remembered, the browser language decides on the first visit
- Dark mode: follows the device setting, or choose light/dark yourself
- App icon and favicon; can be added to the home screen of a phone like an app
- Profiles without passwords: nickname, e-mail and a random monster icon; a personal link logs a device in for good. *Link vergessen?* sends a new link and logs out all devices. Playing as a guest still works
- Admin area: profiles with last login and number of rounds, send a new link, rename, delete; mail settings (SMTP or PHP `mail()`) with a test mail
- No external services, no JavaScript framework

## Requirements

- PHP **8.0 or newer** with `pdo_sqlite` and `mbstring` (standard on almost every web host)
- A folder `data/` that PHP can write to
- Apache: the included `.htaccess` files protect the database. With nginx, set `DB_DATEI` in `config.php` to a path outside the web root.

## Installation

1. Download `brainforge-<version>.zip` from the [releases](../../releases).
2. Unpack it and upload the contents of the folder `brainforge/` to your web space (e.g. the web root of the domain).
3. Make sure `data/` is writable for PHP. The database is created with the first result.
4. Open the site in the browser.

Settings (number of countries in *Kurz*, tolerant checking on/off, default language, database path, admin password) are in `config.php`.

### Profiles and e-mail

1. Set your own `ADMIN_PASSWORT` in `config.php`. As long as it is `bitte-aendern`, the admin area stays locked.
2. Open `admin` (redirects to `admin.php`; needs `mod_rewrite`, otherwise use `admin.php` directly), log in and fill in the **Mail-Einstellungen** (your host's SMTP server, port, encryption, user, password, sender address). *Speichern und Testmail senden* checks them right away.
   They are stored in `data/einstellungen.php` (protected like the database).
3. If the site runs behind a proxy or under several addresses, enter the *Adresse der Seite*; the links in the mails use it.

Without mail settings, profiles still work: the personal link is shown on the screen after creating the profile.
A detailed guide in German is in [LIESMICH.txt](LIESMICH.txt).

## Update

1. Upload the files of the new release and overwrite the old ones. **Keep the folder `data/`** – it holds the results.
2. Open the site. If the database needs an update, the page «Update nötig» appears: click **GO**. A backup copy (`data/resultate-sicherung-<date>.sqlite`) is made first; all results are kept.

If you changed `config.php` (e.g. the admin password), apply your changes again after uploading. The mail settings are in `data/` and stay.

## Release

1. In a pull request: raise `APP_VERSION` in `version.php` and add a section for the new version to `CHANGELOG.md`.
2. Merge the pull request. The release is created **automatically**: the workflow sees the new version in `version.php`, runs the self test, builds `brainforge-<version>.zip` and publishes the release (tag `v<version>`) with the changelog section as release notes.
   A merge that does not change `version.php`, or changes it without a new version, creates no release.
3. Manually, if ever needed: **Actions › Release › Run workflow** with the version (e.g. `1.1.0`).

Every pull request gets a test ZIP: **Checks › CI › Summary › Artifacts**.

## Adding a language

1. Copy `lang/fr.php` to `lang/<code>.php` (e.g. `it`) and translate the texts (`texte`), the country and capital names (`laender`, the first name is the solution, further names count as correct as well) and the tolerated near-misses (`toleriert`).
2. Add the language to `SPRACHEN` at the top of `lib.php`.
3. Run `php tests/selftest.php`: it reports missing translations and names that would be accepted for the wrong country.

## Development

```sh
php -S localhost:8000          # run locally
php tests/selftest.php         # check data, answer checking and answer options
bin/build-release.sh           # build the release ZIP into build/
```

## Credits

- Flags: [svg-country-flags](https://github.com/hjnilsson/country-flags) (public domain)
- Outlines and maps: [Natural Earth](https://www.naturalearthdata.com/) (public domain), via [world-atlas](https://github.com/topojson/world-atlas)
