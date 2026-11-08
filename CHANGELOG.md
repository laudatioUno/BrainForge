# Changelog

All notable changes to BrainForge (formerly CountryQuiz). Versions follow [semantic versioning](https://semver.org/).
The section of a version is used as the release notes on GitHub.

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
