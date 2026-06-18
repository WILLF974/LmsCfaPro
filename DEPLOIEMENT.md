# LMS CFA Pro — Guide de déploiement Hostinger

## Prérequis
- PHP 8.0+
- MySQL 5.7+ ou MariaDB 10.3+
- Apache avec mod_rewrite activé
- Extension PHP : PDO, PDO_MySQL, fileinfo, mbstring, json

---

## 1. Configuration locale (XAMPP/MAMP/Laragon)

### Installation locale
1. Copier le dossier `LmsCFApro` dans `htdocs` (XAMPP) ou équivalent
2. Créer la base de données :
   ```
   mysql -u root -p < database/schema.sql
   mysql -u root -p lmscfapro < database/seeds.sql
   ```
3. Modifier `config/database.php` :
   ```php
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
4. Modifier `config/config.php` :
   ```php
   define('BASE_URL', 'http://localhost/LmsCFApro');
   ```
5. Accéder à `http://localhost/LmsCFApro`

---

## 2. Déploiement sur Hostinger (FTP)

### Étape 1 — Créer la base de données sur Hostinger
1. Se connecter au **hPanel Hostinger**
2. Aller dans **Bases de données > MySQL**
3. Créer une nouvelle base : `u123456_lmscfapro`
4. Créer un utilisateur et noter les identifiants
5. Importer `database/schema.sql` via phpMyAdmin
6. Importer `database/seeds.sql` via phpMyAdmin

### Étape 2 — Modifier la configuration
Avant d'uploader, modifier ces fichiers :

**`config/database.php`** :
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456_lmscfapro');   // Votre nom de BDD
define('DB_USER', 'u123456_lmsuser');      // Votre utilisateur BDD
define('DB_PASS', 'VotreMotDePasse');       // Votre mot de passe BDD
```

**`config/config.php`** :
```php
define('ENV', 'production');
define('BASE_URL', 'https://votredomaine.com');  // Votre domaine
// Changer la clé secrète :
define('SECRET_KEY', 'clé_secrète_aléatoire_de_32_caractères');
```

### Étape 3 — Upload FTP
**Logiciel recommandé** : FileZilla

**Paramètres FTP Hostinger** :
- Hôte : `ftp.votredomaine.com`
- Utilisateur : votre identifiant FTP Hostinger
- Mot de passe : votre mot de passe FTP
- Port : 21

**Structure d'upload** :
```
public_html/
├── .htaccess
├── index.php
├── register.php
├── logout.php
├── config/
├── includes/
├── assets/
├── uploads/      ← doit avoir chmod 755
├── admin/
├── teacher/
├── student/
├── pedagogy/
└── api/
```

> ⚠️ **NE PAS uploader** le dossier `database/` (contient les SQL déjà importés)

### Étape 4 — Permissions dossiers
Via FTP ou hPanel, donner les permissions :
```
uploads/          → 755
uploads/*         → 755
```

### Étape 5 — Vérification
1. Accéder à `https://votredomaine.com`
2. Tester la connexion avec les comptes de démonstration :
   - `admin@lmscfapro.fr` / `password`
   - `teacher@lmscfapro.fr` / `password`
   - `student@lmscfapro.fr` / `password`

---

## 3. Configuration post-déploiement

### Changer les mots de passe de démo
**IMPORTANT** : Aller dans Admin > Utilisateurs et changer tous les mots de passe par défaut.

### Configuration email (optionnel)
Dans Admin > Paramètres > Email, configurer :
- Serveur SMTP Hostinger ou service externe (SendGrid, Mailgun)
- Les paramètres SMTP sont disponibles dans hPanel > Email

### SSL/HTTPS
Hostinger fournit SSL gratuit (Let's Encrypt).
Décommenter dans `.htaccess` :
```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## 4. Structure de la base de données

| Table | Description |
|-------|-------------|
| `users` | Tous les utilisateurs (admin, pédagogie, enseignant, étudiant) |
| `rncp_titles` | Titres professionnels RNCP |
| `activity_types` | Blocs de compétences |
| `competencies` | Compétences par bloc |
| `formations` | Programmes de formation |
| `modules` | Modules au sein des formations |
| `lessons` | Capsules de cours (vidéo, PDF, quiz...) |
| `lesson_resources` | Ressources attachées aux capsules |
| `enrollments` | Inscriptions étudiants |
| `lesson_progress` | Suivi progression |
| `quizzes` | Quiz et évaluations |
| `quiz_questions` | Questions de quiz |
| `quiz_options` | Réponses possibles |
| `quiz_attempts` | Tentatives des étudiants |
| `badges` | Définition des badges gamification |
| `user_badges` | Badges gagnés |
| `xp_transactions` | Historique XP |
| `evaluations` | Évaluations formatives/sommatives |
| `evaluation_submissions` | Soumissions étudiants |
| `formation_sessions` | Sessions présentiel/distanciel |
| `attendance` | Feuilles de présence |
| `qualiopi_criteria` | 7 critères Qualiopi |
| `qualiopi_evidences` | Preuves de conformité |
| `surveys` | Enquêtes de satisfaction |
| `notifications` | Notifications in-app |
| `audit_log` | Journal d'audit (conformité) |
| `messages` | Messagerie interne |
| `settings` | Paramètres globaux |

---

## 5. Comptes par défaut (seeds)

| Email | Rôle | Mot de passe |
|-------|------|-------------|
| admin@lmscfapro.fr | Administrateur | password |
| pedagogy@lmscfapro.fr | Pédagogie | password |
| teacher@lmscfapro.fr | Enseignant | password |
| student@lmscfapro.fr | Étudiant | password |

---

## 6. Critères Qualiopi couverts

| Critère | Indicateurs | Support LMS |
|---------|------------|-------------|
| 1 - Information | 1.1 à 1.3 | Page formation publique, objectifs, résultats |
| 2 - Compétences | 2.1 à 2.3 | Positionnement, besoins, adaptation |
| 3 - Moyens | 3.1 à 3.2 | Ressources pédagogiques, profils formateurs |
| 4 - Suivi | 4.1 à 4.2 | Présences, progression, évaluations |
| 5 - Amélioration | 5.1 | Audit log, indicateurs, enquêtes |
| 6 - Réclamations | 6.1 | Formulaire réclamations |
| 7 - Résultats | 7.1 | Statistiques de certification |

---

## 7. Maintenance

### Sauvegardes
- Effectuer des sauvegardes régulières via hPanel
- Exporter la BDD : `mysqldump -u user -p lmscfapro > backup.sql`

### Mises à jour
- Tester en local avant tout déploiement
- Versionner avec Git si possible
