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
    

## 📊 Objectifs et Métriques FinOps (Green IT)

En maintenant l'application en mémoire vive (Worker Mode) et en éliminant l'overhead de démarrage (Bootstrap), EcoShield vise les performances suivantes face à PHP-FPM :

- 📉 **Réduction de la RAM :** ~80% d'économie de mémoire sous haute charge.
    
- ⚡ **Latence :** Temps de réponse divisé par 5 sur les routes interceptées.
    
- 🌱 **Green IT :** Moins de CPU cyclé = Moins de serveurs allumés = Réduction de l'empreinte carbone.
    

## 🏗️ Architecture Technique

Ce projet est une application "consommatrice" de l'écosystème Open Source Waffle.

- **Moteur HTTP :** FrankenPHP (Caddy Server).
    
- **Framework :** [Waffle-Commons](https://github.com/waffle-commons "null") (`v0.1.0-beta1`).
    
- **Standards :** 100% PSR-Compliant (PSR-7, PSR-15, PSR-18).
    
- **Qualité :** Architecture "Zero-Debt" certifiée Mago (0 erreurs d'analyse statique).
    

## 🚀 Installation & Démonstration

> _Les instructions de déploiement Docker et le protocole de benchmark (k6) seront documentés ici lors de la publication de la Release Candidate._

### Pré-requis

- Docker & Docker Compose
    
- PHP 8.5 CLI (pour le développement local)
    

```
# Clone the repository
git clone [https://github.com/waffle-commons/ecoshield-gateway.git](https://github.com/waffle-commons/ecoshield-gateway.git)
cd ecoshield-gateway

# Install Waffle dependencies
composer install

# Start the Gateway & the Legacy Dummy Backend
docker compose up -d
```

## 📄 Licence

Ce projet est sous licence MIT. Il peut être librement audité, modifié et intégré dans des infrastructures d'entreprises propriétaires.
