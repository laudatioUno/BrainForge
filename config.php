<?php
// ------------------------------------------------------------
// Einstellungen BrainForge
// ------------------------------------------------------------

// Anzahl Flaggen im Modus "Kurz"
const ANZAHL_KURZ = 15;

// Tippfehler und gleich klingende Schreibweisen als richtig zählen?
// (z. B. "Lichtenstein", "Warshau", "Kopenhagn"). Die korrekte Schreibweise wird
// trotzdem angezeigt. false = Schreibweise muss stimmen
// (Umlaute, Akzente, Leerzeichen und Gross-/Kleinschreibung sind immer egal).
const TIPPFEHLER_TOLERANZ = true;

// Speicherort der SQLite-Datenbank (Ordner muss für PHP beschreibbar sein)
const DB_DATEI = __DIR__ . '/data/resultate.sqlite';

// Sprache, wenn weder der Browser noch eine frühere Wahl etwas anderes sagt: 'de' oder 'fr'
const STANDARD_SPRACHE = 'de';
