-- Amorçage de la base du banc, partagé par LES DEUX camps.
--
-- Monté dans /docker-entrypoint-initdb.d/ du service `bench-postgres` : exécuté
-- une fois par volume de données neuf (`docker compose down -v` puis `up`
-- ré-amorce de façon déterministe).
--
-- Le schéma et le mode de dérivation des identifiants sont repris À L'IDENTIQUE
-- du banc écosystème beta6 (`bench/sql/init.sql` du monorepo). Ce n'est pas de la
-- paresse : garder le même jeu de données rend les deux campagnes directement
-- comparables, et un lecteur qui connaît l'une lit l'autre sans réapprendre le
-- schéma.
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Jeu déterministe de 10 000 lignes pour la charge de lecture.
--
-- DÉRIVATION DES IDENTIFIANTS — les scripts k6 DOIVENT utiliser exactement la
-- même, sans quoi chaque lecture serait un « non trouvé » et l'on mesurerait le
-- coût d'un index qui ne renvoie rien :
--
--   h  = md5('bench-user-' || n)     -- n de 1 à 10000
--   id = h formaté en UUID           -- tranches 8-4-4-4-12 des 32 caractères hex
--
-- Côté k6 :
--   const h = crypto.md5(`bench-user-${n}`, 'hex');
--   const id = `${h.substr(0,8)}-${h.substr(8,4)}-${h.substr(12,4)}-${h.substr(16,4)}-${h.substr(20,12)}`;
--
-- Le banc écosystème consigne une erreur exactement là : une première version
-- dérivait `md5(String(n))`, si bien que TOUTE lecture manquait sa cible. Le
-- moteur qui répondait 200 {"found": false} masquait la panne, celui qui
-- répondait 404 la révélait. D'où la règle : après tout changement d'un côté,
-- vérifier qu'une lecture trouve bien sa ligne AVANT de publier quoi que ce soit.
TRUNCATE TABLE users;

INSERT INTO users (id, email, password_hash, created_at)
SELECT
    substr(t.h, 1, 8) || '-' || substr(t.h, 9, 4) || '-' || substr(t.h, 13, 4)
        || '-' || substr(t.h, 17, 4) || '-' || substr(t.h, 21, 12) AS id,
    'user' || t.n || '@bench.ecoshield.local' AS email,
    md5('bench-pass-' || t.n) AS password_hash,
    CURRENT_TIMESTAMP
FROM (SELECT n, md5('bench-user-' || n) AS h FROM generate_series(1, 10000) AS n) AS t
ON CONFLICT (id) DO NOTHING;
