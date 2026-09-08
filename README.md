# 🛡️ EcoShield Gateway

**High-Performance, FinOps-driven API Gateway for Legacy PHP Monoliths.**

EcoShield Gateway est un Proof of Concept (POC) d'infrastructure démontrant comment moderniser, sécuriser et réduire drastiquement les coûts d'hébergement d'une application PHP Legacy (ex: Symfony/FPM) **sans avoir à la réécrire**.

## 🎯 La Problématique (Le Mur de la Dette)

Les architectures PHP traditionnelles (Nginx + PHP-FPM) instancient le framework à chaque requête HTTP. Sous une forte charge (Black Friday, pics de trafic), la consommation de mémoire vive (RAM) explose, forçant les entreprises à surdimensionner leurs serveurs Cloud (Scale-Up coûteux). La réécriture totale de ces applications vers des langages asynchrones prend des années et comporte un risque d'échec massif.

## 💡 La Solution : Le Pattern "Strangler Fig"

**EcoShield** se déploie comme un bouclier en amont de votre application legacy. Construit sur le **Waffle Framework** (un micro-framework PHP 8.5 ultra-strict tournant en mode "Resident Memory" via FrankenPHP), EcoShield intercepte le trafic entrant :

1. **Interception (Rescue) :** Les routes critiques ou extrêmement lourdes sont traitées nativement par EcoShield à une vitesse sub-milliseconde.
    
2. **Proxying (Transparent) :** Le reste du trafic non géré est proxyfié de manière asynchrone vers le monolithe legacy.
    
3. **Mise en Cache (Shield) :** Les réponses du legacy sont mises en cache pour décharger la base de données sous-jacente.
    

## 📊 Métriques FinOps (Green IT) — mesurées

En maintenant l'application en mémoire vive (Worker Mode) et en éliminant l'overhead de démarrage (Bootstrap), EcoShield obtient, face à PHP-FPM, les résultats suivants. Ce ne sont pas des objectifs : ils sortent du banc versionné dans [`bench/`](./bench), et le protocole complet — y compris ce qu'il ne permet PAS de conclure — est dans [`bench/BENCH-RESULT.md`](./bench/BENCH-RESULT.md).

- ⚡ **Latence : ÷13.8 sur les routes interceptées** (1.42 ms contre 19.62 ms au repos), ÷11.3 sur une réponse servie par le cache. L'objectif initial était ÷5 ; le coût de démarrage qui disparaît vaut mieux que cela.
    
- 📉 **Mémoire : empreinte constante.** La passerelle croît de **0.18 Mio par requête concurrente**, PHP-FPM de **4.14 Mio** — une croissance **23× plus lente**. L'économie devient réelle **au-delà d'une vingtaine de requêtes simultanées** : 49 % à 64 concurrentes. En dessous du croisement, un worker résident coûte au contraire *plus* cher qu'un FPM au repos — c'est la contrepartie honnête du modèle, et elle décide de la pertinence du bouclier pour un trafic donné.
    
- ♾️ **Stabilité dans le temps : +0.23 Mio/h** sur 30 min de charge mixte, sous le bruit de l'allocateur. Un worker résident ne tient sa promesse que si son empreinte est plate *dans la durée* autant qu'en concurrence — une fuite de 1 Mio/h est invisible dix minutes et fatale en une semaine.
    
- 🌱 **Green IT :** moins de CPU cyclé sur les routes reprises et sur les hits de cache, et une empreinte qui ne suit pas les pics de trafic — donc moins de serveurs provisionnés pour le pic.
    

> ⚠️ **Ce banc ne mesure pas la capacité.** Le générateur de charge partage les 12 vCPU de la VM avec la passerelle et le monolithe : au-delà de 16 requêtes concurrentes, les chiffres décrivent la contention de l'hôte, pas l'architecture. Un chiffre de débit exigerait un générateur sur une machine séparée. Le détail est dans le rapport.

## 🏗️ Architecture Technique

Ce projet est une application "consommatrice" de l'écosystème Open Source Waffle.

- **Moteur HTTP :** FrankenPHP (Caddy Server).
    
- **Framework :** [Waffle-Commons](https://github.com/waffle-commons "null") (`0.1.0-beta6`).
    
- **Standards :** 100% PSR-Compliant (PSR-7, PSR-15, PSR-18).
    
- **Qualité :** Architecture "Zero-Debt" certifiée Mago (0 erreurs d'analyse statique).
    

## 🚀 Installation & Démonstration

### Pré-requis

- Docker & Docker Compose
    
- PHP 8.5 CLI (pour le développement local)
    

```
# Clone the repository
git clone https://github.com/waffle-commons/ecoshield-gateway.git
cd ecoshield-gateway

# Install Waffle dependencies
composer install

# Start the Gateway & the Legacy Dummy Backend
docker compose up -d

# La passerelle répond sur :8099, le monolithe legacy sur :8098
curl localhost:8099/__ecoshield/health      # sonde : la passerelle SEULE
curl localhost:8099/api/products/42         # route reprise, servie par le worker
curl -i localhost:8099/api/catalogue        # proxyfiée + cache (X-EcoShield-Cache)
```

### Rejouer les mesures

Le banc tourne sur l'image de **production** (opcache figé, code dans l'image) :
mesurer l'image de développement reviendrait à mesurer un système de fichiers.

```
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d --build

./bench/ladder.sh            # échelle de concurrence : latence + mémoire
DURATION=30m ./bench/soak.sh # dérive mémoire dans le temps (Mio/h)
```

## 📄 Licence

Ce projet est sous licence MIT. Il peut être librement audité, modifié et intégré dans des infrastructures d'entreprises propriétaires.
