# Changelog

All notable changes to BrainForge (formerly CountryQuiz). Versions follow [semantic versioning](https://semver.org/).
The section of a version is used as the release notes on GitHub.

## [1.5.0] – 2026-11

### Added
- **1×1** as a third game: multiplication up to 10, 12 or 15 (slider on the start page).
  - *Kurz*: 20 tasks, ranked by percentage, then time. *Blitz*: as many as possible in 60 seconds with a countdown, ranked by the number of right answers, then the rate.
  - Type the answer and press Enter; the next task comes at once with the feedback for the last one (with the solution when wrong).
  - A wrong task comes back three tasks later («Nochmals», at most twice). In *Kurz* repeats don't count for the result.
  - With a profile, every task has a learning state (box 0–5: right = one up, wrong = back to 0). The next rounds draw wrong and unsure tasks more often and mastered ones less; tasks answered wrong last time always come again. ×1 and ×10 come less often. A guest round taken over into a new profile counts for the learning state.
  - Own rankings per range and mode; on the start page and under *Ergebnisse* (with CSV export).
- **1×1 statistics** per profile (*Meine 1×1-Statistik*, from the profile page and the result page): the table as a colour map (mastered / unsure / to practise / never done), 17 badges (first round, 10 and 50 rounds, no mistakes, Blitz 25 and 40, streaks of 3 and 7 days, the 2 to 9 times tables, all of the small table), the current and longest streak, and the history of the last 10 rounds with rate and time per task.
- Streak 🔥 (days in a row with at least one round, any game) next to the profile button from 2 days.

### Changed
- Start page: title «Üben mit BrainForge», three games to choose from; the options of the chosen game are shown.

### Database
- Version 4: table `rechnungen` (learning state per profile and task). Existing installations are updated via `update.php` with a backup, as before.

## [1.4.0] – 2026-11

### Added
- **Profiles** – anyone can create one: nickname, e-mail address (e.g. of a parent) and a funny monster icon drawn at random (*Neu würfeln* rolls a new one). No password: each profile has a **personal link**, shown once on the screen with instructions for the home screen (iPhone and Android) and sent by e-mail. Opening the link logs the device in, and it stays logged in.
- The profile counts for all games: flags and outlines are saved with the profile, and the rankings show the profile icon. Rounds without a profile still count and are marked *Gast*.
- After a guest round: **Profil erstellen und Runde übernehmen** – the new profile takes over the round just played.
- *Link vergessen?*: enter the e-mail address and get a new link for every profile with that address. The old link stops working and all devices are logged out. The answer never reveals whether an address exists.
- Profile page: last rounds, number of logged-in devices, new icon, new link (keeps this device, logs out the others), log out.
- **Admin area** (`admin.php`, also reachable as `/admin` via `.htaccess`, password `ADMIN_PASSWORT` in `config.php`; locked as long as it is `bitte-aendern`): all profiles with e-mail, created, last login, rounds and devices; send a new link, rename, delete (with its rounds).
- **Mail settings in the admin area**: SMTP (STARTTLS, SSL or none, with login) or PHP `mail()`, sender, address of the site for the links, test mail. Stored in `data/einstellungen.php`, not in the code, so an update does not overwrite them. Mails are sent as plain text in quoted-printable (readable for spam filters, which mark base64-encoded text as suspicious); the test mail explains what it is and when it was sent.

### Security
- Only SHA-256 hashes of personal links and device keys are stored. Device cookie: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS.
- Limits against misuse: creating profiles, requesting links, opening links and admin logins are rate-limited; all forms carry a CSRF token.

### Changed
- The database update (*Update nötig* › GO) adds the tables for profiles and devices; all results are kept.

## [1.3.0] – 2026-11

### Changed
- New name: **BrainForge** (formerly CountryQuiz). The page title, header, footer, release ZIP (`brainforge-<version>.zip`) and documentation use the new name.
- Header on every page with the BrainForge logo (link to the start page) and the language and theme switches; the footer only shows the version.

### Added
- App icon: favicon (SVG and PNG), icon for the home screen on iPhone and Android, web app manifest. The files are in `appicon/`, not `icons/`: many Apache servers reserve `/icons/` for their own symbols, so files there are not found. Added to the home screen, BrainForge opens in the normal browser, so a login stays valid (preparation for profiles).

## [1.2.0] – 2026-10

### Added
- Dark mode: follows the device setting automatically; switch *Automatisch / Hell / Dunkel* (*Automatique / Clair / Sombre*) next to the language switch at the top of the start page and in the footer. The choice is remembered (cookie) and applied by the server, so pages don't flash when they load.
- All colours are defined once as variables; the dark scheme meets the WCAG contrast ratio of 4.5:1 for text.

### Changed
- Outline thumbnails in the evaluation get the same light background as in the quiz, so they stay visible on dark backgrounds.

## [1.1.0] – 2026-10

### Added
- French: the whole quiz is available in French (Suisse romande) – all texts, country and capital names, answer checking with French alternative names (Bélarus, République tchèque, Grande-Bretagne, Kyiv …) and tolerated near-misses (Bosnie, Hollande, Macédoine).
- Language switch Deutsch / Français at the top of the start page and in the footer of every page. The choice is remembered (cookie); on the first visit the browser language decides. Default language in `config.php` (`STANDARD_SPRACHE`).
- Self test checks every language: names, answer checking against all other countries, and that every text has a translation.

### Changed
- Words that sound the same (e.g. «Lichtenstein») are only matched phonetically in German, because the rules are German; in French, typos are still tolerated.
- Rankings are shared by both languages.

## [1.0.0] – 2026-10

First version: an online exercise for the 46 countries of Europe – flags, capitals and outlines.

### Added
- **Flags**: see a flag, type the country and its capital. One question at a time with immediate feedback and the correction.
- **Outlines** in three levels:
  - *Leicht*: choose the country from 4 names
  - *Mittel*: choose the matching flag from 4 flags
  - *Schwer*: type the country
- Small states (Andorra, Liechtenstein, Monaco, San Marino, Vatican City) are shown as a map excerpt with the position marked, because their outline is too small to recognise.
- Answer options are drawn at random from neighbouring countries or similar-looking flags, in a new order every time; the same set is never shown twice in a row for a country.
- Tolerant answer checking: case, umlauts, accents, spaces and hyphens are ignored; common alternative names count (Weissrussland, Grossbritannien, Kyjiw …); typos and words that sound the same count, with the correct spelling shown (Lichtenstein, Warshau …). The name of another country never counts.
- Choice of *Kurz* (15 random countries) or *Komplett* (all 46).
- Results are stored in SQLite with time, points and duration. Evaluation at the end with every answer and the place in the ranking.
- Rankings without password, one per combination of game type, level and length; top 5 on the start page; search by name and CSV export.
- Database update page: an existing database from an earlier installation is updated with one click («Update nötig» › GO), with an automatic backup copy first.
- Version shown in the page footer.
- GitHub Actions: CI with lint, self test and a test ZIP for every pull request; release workflow that builds the ZIP and publishes it with these notes – automatically when a merge into main raises the version in `version.php`, or manually.

### Database
- Table `resultate` with the columns `spielart` (flaggen/umrisse) and `stufe` (leicht/mittel/schwer); schema version 2 (`PRAGMA user_version`).
