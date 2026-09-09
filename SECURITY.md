# Politique de sécurité — EcoShield Gateway

Une passerelle est, par construction, le premier composant exposé d'une architecture : tout le
trafic entrant la traverse avant d'atteindre l'application protégée. Sa surface d'attaque est donc
la surface d'attaque de ce qu'elle protège. Ce document décrit ce qu'elle **applique**, ce qu'elle
ne couvre pas, et comment signaler une faille.

Le vocabulaire est délibéré : ce sont des mesures techniques implémentées et testées, pas des
garanties contractuelles. La licence MIT exclut toute garantie, et ce document ne la contredit pas.

---

## 📮 Signaler une vulnérabilité

**N'ouvrez pas d'issue publique pour une faille de sécurité.**

Utilisez les [avis de sécurité GitHub](https://github.com/waffle-commons/ecoshield-gateway/security/advisories/new)
(canal privé), ou à défaut écrivez à **contact@waffle-commons.cloud** avec `[SECURITY]` en objet.

Merci d'inclure, dans la mesure du possible : la version concernée, les étapes de reproduction,
l'impact estimé, et toute suggestion de correction.

**Engagement de traitement :**

| Étape | Délai visé |
|---|---|
| Accusé de réception | 72 heures |
| Évaluation initiale et qualification | 7 jours |
| Correctif ou plan de contournement (faille critique) | 30 jours |
| Publication coordonnée | après correctif, en accord avec le rapporteur |

Le crédit est donné au rapporteur dans l'avis publié, sauf demande contraire.

---

## 🔖 Versions supportées

Ce projet est une **preuve de concept**. Seule la branche courante reçoit des correctifs ; il n'y a
ni version LTS ni rétroportage.

| Version | Supportée |
|---|---|
| `0.1.0-beta6` (courante) | ✅ |
| Antérieures | ❌ |

Le framework sous-jacent suit son propre cycle : voir la politique de
[`waffle-commons`](https://github.com/waffle-commons).

---

## 🛡️ Ce que la passerelle applique

Ces propriétés sont implémentées et couvertes par des tests. Elles ne sont pas des intentions.

### Traitement des en-têtes saut par saut

Les en-têtes qui décrivent la *connexion* et non le *message* sont retirés dans les deux sens :
l'ensemble fixe des RFC 9110/9112, **et tout champ nommé par l'en-tête `Connection` du message
lui-même**. C'est ce second point qui est habituellement manqué : une requête portant
`Connection: X-Internal-Auth` s'attend à ce que cet en-tête meure au saut, et un proxy qui le relaie
le fuite vers l'amont.

### Refus des messages à cadrage ambigu

Un message portant à la fois `Content-Length` et `Transfer-Encoding`, ou deux `Content-Length`
contradictoires, peut être lu de deux façons. Quand la passerelle et l'amont le lisent différemment,
**une requête en devient deux** — c'est la matière première du *request smuggling*. La passerelle
**refuse (400)** plutôt que de deviner.

### Maîtrise de la destination

L'amont est fixé par l'exploitant (`UPSTREAM_URL`). Du message entrant, la passerelle ne reprend
**que le chemin et la chaîne de requête** ; le schéma, l'hôte et le port viennent toujours de la
configuration. Un client ne peut pas rediriger le trafic.

Les tentatives de traversée (`..`, y compris ré-encodées `%2e%2e` ou `%252e%252e`) sont refusées
après décodage jusqu'à point fixe, et comparées **segment par segment** — un fichier nommé
`notes..bak` reste légitime, `..` seul ne l'est pas.

### En-têtes de transfert non falsifiables

`X-Forwarded-For`, `-Proto` et `-Host` sont **ajoutés, jamais repris tels quels**. Un client qui
envoie sa propre chaîne de transfert tente de forger son origine ; le pair réellement observé est
ajouté en dernier, là où un consommateur correct le lit.

### Cache fail-safe

Le bouclier **refuse de mutualiser bien plus qu'il n'accepte** — c'est délibéré, car servir la
réponse privée d'un client à un autre est la fuite classique des caches de passerelle :

- toute méthode autre que `GET`/`HEAD` ;
- **toute requête portant `Authorization` ou `Cookie`** ;
- toute réponse autre que `200`, ou portant `Set-Cookie`, `Cache-Control: no-store` ou `private`.

### Absence de fuite d'état entre requêtes

Le mode worker garde les services en mémoire d'une requête à l'autre : un état résiduel devient une
fuite de données entre clients. L'audit `igor-php` vérifie cette propriété et **bloque la
publication à la moindre alerte** (actuellement 0 KO, 10 services sur 10 sans état).

### Erreurs sans divulgation

Une panne amont devient un `502` dont le message ne nomme jamais la topologie interne. Le détail
diagnostique reste dans les journaux.

---

## ⚠️ Ce que la passerelle ne couvre PAS

Ces limites sont des choix documentés, pas des oublis. Les ignorer serait plus dangereux que de les
énoncer.

### Aucune authentification de bord

EcoShield **ne vérifie aucune identité**. Elle relaie les en-têtes d'authentification vers l'amont,
qui reste seul juge. Le `SecurityContext` de la passerelle est explicitement anonyme.

**Conséquence :** la passerelle ne remplace ni un WAF, ni un fournisseur d'identité, ni un contrôle
d'accès applicatif. L'authentification de bord relève du framework (`waffle-commons/auth`, beta7).

### Aucune garde SSRF sur le client amont — délibérément

Une garde SSRF rejetterait l'adressage interne (`http://legacy:80`), qui est précisément la raison
d'être d'une passerelle. La protection ne vient pas d'un filtre mais de la conception : **la
destination n'est jamais dérivée d'une donnée fournie par le client.**

**Conséquence :** rendre `UPSTREAM_URL` modifiable par une entrée utilisateur transformerait la
passerelle en relais SSRF. Cette variable est une donnée d'exploitation, jamais applicative.

### Aucune limitation de débit

Ni quota, ni coupe-circuit, ni protection contre le déni de service. Une passerelle sans limiteur
relaie fidèlement une attaque volumétrique vers l'amont.

**Conséquence :** placer un limiteur en amont (CDN, WAF, ingress) tant que `resilience-net`
(beta7 `NET-01`) n'est pas intégré.

### Aucun chiffrement de bout en bout imposé

La liaison passerelle → amont suit `UPSTREAM_URL`. En `http://`, le trafic circule en clair sur le
réseau interne.

**Conséquence :** dans un réseau non maîtrisé, utiliser `https://` ou un maillage chiffré.

---

## 🔍 Posture de vérification

| Contrôle | Statut |
|---|---|
| Analyse statique (Mago : lint, analyse, périmètre) | 0 diagnostic, aucune ligne de base tolérée |
| Tests | 42 tests, **99.15 %** de couverture d'instructions |
| Sûreté en mode worker (`igor-php`) | **0 KO**, 10/10 sans état |
| Audit des dépendances (`composer audit`) | intégré à la CI |
| Recherche de secrets (TruffleHog) | intégrée à la CI |
| SBOM (CycloneDX) | produit à chaque exécution de CI |

La version de l'auditeur `igor-php` est **épinglée et vérifiée** dans la CI : le paquet Composer
n'est qu'un amorceur qui télécharge la dernière version publiée, si bien qu'une publication amont
pourrait sinon changer silencieusement ce que cette porte contrôle.
