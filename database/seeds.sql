-- ============================================================
-- LMS CFA Pro - Données initiales (seeds)
-- ============================================================

-- Admin par défaut (password: Admin@2024!)
INSERT INTO `users` (`uuid`, `email`, `password`, `role`, `first_name`, `last_name`, `status`, `email_verified_at`) VALUES
('a1b2c3d4-0000-0000-0000-000000000001', 'admin@lmscfapro.fr', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Super', 'Admin', 'active', NOW()),
('a1b2c3d4-0000-0000-0000-000000000002', 'pedagogy@lmscfapro.fr', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pedagogy', 'Marie', 'Dupont', 'active', NOW()),
('a1b2c3d4-0000-0000-0000-000000000003', 'teacher@lmscfapro.fr', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'Pierre', 'Martin', 'active', NOW()),
('a1b2c3d4-0000-0000-0000-000000000004', 'student@lmscfapro.fr', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Alice', 'Bernard', 'active', NOW());

-- Badges prédéfinis
INSERT INTO `badges` (`name`, `description`, `icon`, `color`, `badge_type`, `criteria_type`, `criteria_value`, `xp_reward`, `rarity`) VALUES
('Premier Pas', 'Bienvenue ! Vous venez de vous connecter pour la première fois.', 'door-open', '#10b981', 'activity', 'first_login', 1, 10, 'common'),
('Profil Complet', 'Vous avez complété votre profil à 100%.', 'user-check', '#6366f1', 'activity', 'profile_complete', 1, 25, 'common'),
('Première Capsule', 'Vous avez terminé votre première capsule de cours.', 'play-circle', '#0ea5e9', 'completion', 'lessons_completed', 1, 30, 'common'),
('Apprenti', 'Vous avez terminé 5 capsules de cours.', 'book-open', '#8b5cf6', 'completion', 'lessons_completed', 5, 50, 'common'),
('Studieux', 'Vous avez terminé 20 capsules de cours.', 'graduation-cap', '#f59e0b', 'completion', 'lessons_completed', 20, 100, 'uncommon'),
('Expert', 'Vous avez terminé 50 capsules de cours.', 'star', '#ef4444', 'completion', 'lessons_completed', 50, 200, 'rare'),
('Premier Quiz', 'Vous avez réussi votre premier quiz.', 'check-circle', '#22c55e', 'score', 'quiz_score', 50, 40, 'common'),
('As du Quiz', 'Vous avez obtenu 100% à un quiz.', 'trophy', '#f59e0b', 'score', 'quiz_score', 100, 150, 'epic'),
('Série de 3', 'Vous vous êtes connecté 3 jours de suite.', 'fire', '#f97316', 'streak', 'streak_days', 3, 30, 'common'),
('Série de 7', 'Vous vous êtes connecté 7 jours de suite.', 'flame', '#ef4444', 'streak', 'streak_days', 7, 75, 'uncommon'),
('Série de 30', 'Trente jours de connexion consécutifs !', 'lightning', '#7c3aed', 'streak', 'streak_days', 30, 300, 'legendary'),
('Premier Module', 'Vous avez terminé votre premier module.', 'layer-group', '#0ea5e9', 'completion', 'modules_completed', 1, 75, 'uncommon'),
('Formation Complète', 'Vous avez terminé une formation complète !', 'medal', '#f59e0b', 'completion', 'formations_completed', 1, 500, 'epic');

-- Paramètres par défaut
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `label`) VALUES
('site_name', 'LMS CFA Pro', 'general', 'Nom du site'),
('site_tagline', 'La plateforme de formation professionnelle', 'general', 'Slogan'),
('site_email', 'contact@lmscfapro.fr', 'general', 'Email de contact'),
('site_phone', '+33 1 00 00 00 00', 'general', 'Téléphone'),
('site_address', '1 rue de la Formation, 75001 Paris', 'general', 'Adresse'),
('site_logo', '', 'general', 'Logo'),
('site_favicon', '', 'general', 'Favicon'),
('registration_open', '1', 'users', 'Inscription ouverte'),
('registration_requires_approval', '1', 'users', 'Validation manuelle des inscriptions'),
('default_avatar', 'default-avatar.png', 'users', 'Avatar par défaut'),
('xp_level_multiplier', '100', 'gamification', 'XP requis par niveau'),
('max_upload_size_mb', '50', 'files', 'Taille max upload (Mo)'),
('allowed_video_formats', 'mp4,webm,ogg', 'files', 'Formats vidéo autorisés'),
('smtp_host', '', 'email', 'Serveur SMTP'),
('smtp_port', '587', 'email', 'Port SMTP'),
('smtp_user', '', 'email', 'Utilisateur SMTP'),
('smtp_pass', '', 'email', 'Mot de passe SMTP'),
('smtp_from_name', 'LMS CFA Pro', 'email', 'Nom expéditeur'),
('qualiopi_certified', '1', 'qualiopi', 'Certification Qualiopi active'),
('qualiopi_audit_date', '', 'qualiopi', 'Date dernier audit'),
('maintenance_mode', '0', 'general', 'Mode maintenance');

-- Indicateurs Qualiopi (7 critères principaux)
INSERT INTO `qualiopi_criteria` (`criterion_number`, `indicator_number`, `title`, `description`, `category`) VALUES
(1, '1.1', 'Information sur les prestations', 'Les prestataires de formation publient des informations sur leurs prestations.', 'information'),
(1, '1.2', 'Communication des indicateurs résultats', 'Les prestataires communiquent les indicateurs de résultats pertinents.', 'information'),
(1, '1.3', 'Identification des objectifs', 'Les prestataires identifient précisément les objectifs de leurs prestations.', 'information'),
(2, '2.1', 'Adaptation des prestations', 'Les prestataires adaptent les prestations aux publics bénéficiaires.', 'competencies'),
(2, '2.2', 'Recueil des besoins', 'Les prestataires recueillent les besoins et attentes des parties prenantes.', 'competencies'),
(2, '2.3', 'Positionnement des apprenants', 'Les prestataires positionnent les bénéficiaires au regard des objectifs visés.', 'competencies'),
(3, '3.1', 'Moyens techniques et pédagogiques', 'Les prestataires disposent des moyens techniques et pédagogiques adaptés.', 'means'),
(3, '3.2', 'Qualifications des intervenants', 'Les prestataires s assurent de l adéquation des qualifications des formateurs.', 'means'),
(4, '4.1', 'Suivi de l exécution', 'Les prestataires disposent de modalités de suivi de l exécution de la prestation.', 'adaptation'),
(4, '4.2', 'Évaluation des acquis', 'Les prestataires mettent en œuvre les évaluations prévues.', 'adaptation'),
(5, '5.1', 'Amélioration continue', 'Les prestataires s inscrivent dans une démarche d amélioration continue.', 'innovation'),
(6, '6.1', 'Gestion des réclamations', 'Les prestataires recueillent et traitent les réclamations et aléas.', 'complaints'),
(7, '7.1', 'Résultats des apprenants', 'Les prestataires présentent les résultats des apprenants.', 'results');
