-- Migration 003 : élargir lessons.file_path de VARCHAR(255) à TEXT
-- Nécessaire pour stocker le JSON de plusieurs PDFs avec de longs noms de fichier
-- Appliquée en production le 2026-06-21
ALTER TABLE lessons MODIFY COLUMN file_path TEXT NULL;
