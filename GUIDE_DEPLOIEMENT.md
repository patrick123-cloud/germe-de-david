# Guide : aligner GitHub, TiDB Cloud et Render sur le logiciel local

Ordre conseillé : 0 sauvegardes → 1 base locale → 2 GitHub → 3 TiDB Cloud → 4 Render → 5 vérifications.

## 0. Sauvegardes (obligatoire)
- Logiciel en ligne : connectez-vous en administrateur → « Sauvegarde / restauration » → « Télécharger la sauvegarde ». Gardez le fichier .sql.
- Logiciel local : même chose (ou phpMyAdmin → Exporter).

## 1. Base locale (XAMPP / phpMyAdmin)
Sélectionnez la base `eglise_germe_de_david` → onglet SQL, vérifiez : `SHOW INDEX FROM transactions;`
Si `idx_account_date` est absent, exécutez : `ALTER TABLE transactions ADD INDEX idx_account_date (account_id, tx_date);`
Remplacez ensuite les fichiers du dossier local par ceux de cette version.

## 2. GitHub (dépôt patrick123-cloud/germe-de-david)
Dans le dossier du projet (Git Bash ou terminal) :
```
git status
```
Vérifiez que `.gitignore` est présent (il empêche l'envoi de uploads/, .env et des sauvegardes .sql). Puis :
```
git add -A
git status          # contrôler : aucun .env, aucune sauvegarde, aucune photo dans uploads/
git commit -m "Offrandes par mois/libellé, performances, correction solde cumulé"
git push origin main
```
Fichiers nouveaux ou modifiés : config.php, dashboard.php, reports.php, transactions.php, offrandes.php (nouveau), health.php (nouveau), partials/header.php, partials/footer.php, index.php, assets/style.css, Dockerfile, docker/ (opcache.ini, apache-perf.conf), render.yaml, .dockerignore, install.sql, install_cloud.sql, tidb_upgrade_v2.sql, README.md.

## 3. TiDB Cloud
Console TiDB Cloud → votre cluster → « SQL Editor » (ou « Connect » + client MySQL). Choisissez la base `eglise_germe_de_david`.
1. `SHOW INDEX FROM transactions;`
2. Si `idx_account_date` est absent : `ALTER TABLE transactions ADD INDEX idx_account_date (account_id, tx_date);`
3. Comparer à la base locale : exécutez `tidb_preflight.sql` des deux côtés ; les `SHOW CREATE TABLE` et les compteurs (comptes, catégories, utilisateurs, transactions) doivent correspondre.
4. Si les DONNÉES locales doivent devenir celles de l'en ligne : sur le logiciel local, téléchargez la sauvegarde (étape 0), puis sur le logiciel en ligne → « Sauvegarde / restauration » → « Restaurer ». Cela REMPLACE toutes les tables (utilisateurs compris) : la sauvegarde en ligne de l'étape 0 sert de filet de sécurité. Limite 15 Mo.
5. Ne touchez pas à DB_SSL : TLS avec vérification du certificat doit rester actif. Vérifiez l'IP allowlist (ou l'accès public) du cluster.

## 4. Render
1. Dashboard Render → service `germe-de-david` → Settings : dépôt = GitHub, branche `main`, Auto-Deploy = Yes, Health Check Path = `/health.php`.
2. Environment : DB_HOST, DB_PORT (4000), DB_NAME, DB_USER, DB_PASS, DB_SSL=1, DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt.
3. Manual Deploy → « Clear build cache & deploy ». Consultez les Logs jusqu'à « Live ».
4. Région : choisissez celle la plus proche du cluster TiDB (ex. Frankfurt si TiDB est à Francfort). La région ne se change pas après création : il faut recréer le service. Une région éloignée ralentit chaque requête SQL.
5. Offre gratuite : le service s'endort après ~15 min sans visite (premier chargement 30–60 s). Pour l'éviter, créez un moniteur gratuit (UptimeRobot ou cron-job.org) qui appelle `https://VOTRE-APP.onrender.com/health.php` toutes les 10 minutes (cette page ne sollicite pas la base).
6. Le disque Render gratuit est éphémère : logo et photo envoyés depuis « Paramètres » disparaissent à chaque redéploiement (le logiciel affiche alors le logo par défaut). Renvoyez-les après un déploiement, ou utilisez un stockage d'images externe.

## 5. Vérifications
- Connexion, Tableau de bord (chargement rapide), menu « Offrandes par mois ».
- Choisir un mois, comparer les totaux par libellé avec le logiciel local (mêmes chiffres attendus).
- Rapports : « Offrandes ordinaires — modèle mensuel » : le solde cumulé de décembre = solde final de l'année.
- Saisir puis supprimer une opération de test (mois non clôturé).
