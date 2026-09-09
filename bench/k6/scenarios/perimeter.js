// bench/k6/scenarios/perimeter.js — assertions NÉGATIVES du périmètre.
//
// Un banc qui ne mesure que le chemin heureux ne dit rien de la sûreté d'une
// passerelle. Ce scénario envoie ce qu'un attaquant enverrait et exige un refus,
// à 100 %, sur chaque tentative — un refus « la plupart du temps » n'est pas un
// refus.
//
// ## Ce qui est vérifié ici, et ce qui ne peut pas l'être
//
// La mission d'origine demandait la vérification d'assertions signées
// (`X-Waffle-Assertion`, 401 RFC 7807 sur en-tête forgé). **Cette surface
// n'existe pas dans EcoShield**, et l'inventer donnerait un résultat vert sans
// objet. La passerelle est anonyme PAR CONCEPTION : `AnonymousSecurityContext`
// documente l'authentification de bord comme un sujet beta7, et
// `AppKernelFactory` documente l'absence de `SsrfGuard` comme un choix — la
// destination amont est FIXÉE par l'exploitant, et un guard SSRF rejetterait
// justement l'adressage interne qui fait la raison d'être d'une passerelle.
//
// Le périmètre réellement défendu, et donc réellement testé, est ailleurs :
// le client ne choisit PAS la destination, ne sort PAS du chemin de base, ne
// force PAS une mise en cache privée, et ne réécrit PAS la chaîne de transfert.
// C'est cela qui tient lieu d'immunité SSRF ici, et c'est mesurable.
//
// Le cadrage des requêtes (Content-Length + Transfer-Encoding) n'est pas
// exprimable depuis k6 : le client HTTP de Go normalise le message avant
// l'envoi. Ces cas sont traités à l'octet près par
// `bench/scripts/perimeter-raw.sh`.
import { check, group } from 'k6';
import http from 'k6/http';
import { BASE_URL, TREND_STATS, makeHandleSummary } from '../lib/common.js';

const REPEATS = parseInt(__ENV.REPEATS || '20', 10);

export const options = {
  scenarios: {
    perimeter: {
      executor: 'per-vu-iterations',
      vus: 1,
      iterations: REPEATS,
      maxDuration: '5m',
    },
  },
  summaryTrendStats: TREND_STATS,
  thresholds: {
    // Le seuil du scénario : TOUTE assertion doit passer, à chaque répétition.
    checks: ['rate==1'],
  },
};

// Les refus attendus de la passerelle, avec la raison pour laquelle chacun est
// un refus et non une tolérance. Tous répondent en `application/problem+json`
// (RFC 7807) : c'est la signature de la passerelle, à distinguer du 400
// `text/plain` que Caddy rend quand c'est LUI qui refuse (voir perimeter-raw.sh).
const REJECTIONS = [
  {
    name: 'host_forge',
    // Un Host forgé est le vecteur classique d'empoisonnement de cache et de
    // redirection : TrustedHostMiddleware n'accepte que l'allow-list.
    request: () => http.get(`${BASE_URL}/api/products/42`, { headers: { Host: 'evil.example' } }),
  },
  {
    name: 'traversal_encoded',
    // `%2e%2e` survit à un test littéral sur `..` : le contrôle décode d'abord.
    request: () => http.get(`${BASE_URL}/%2e%2e/%2e%2e/etc/passwd`),
  },
  {
    name: 'traversal_double_encoded',
    // `%252e%252e` survit à UN décodage et devient `%2e%2e` : d'où la boucle
    // jusqu'au point fixe côté passerelle.
    request: () => http.get(`${BASE_URL}/%252e%252e/%252e%252e/etc/passwd`),
  },
  {
    name: 'traversal_backslash',
    // `..\` est de la traversée pour un amont Windows, qu'un découpage sur `/`
    // seul laisserait passer.
    request: () => http.get(`${BASE_URL}/%2e%2e%5c%2e%2e%5cwindows/win.ini`),
  },
  {
    name: 'traversal_too_deep',
    // Plus d'imbrications que le décodeur n'en suit : refus plutôt que
    // validation d'une valeur que personne n'a résolue jusqu'au bout.
    request: () => http.get(`${BASE_URL}/%2525252e%2525252e/etc/passwd`),
  },
  {
    name: 'protocol_relative',
    // `//hôte/chemin` est la façon dont un client redirige un proxy vers un
    // hôte de son choix. C'est l'assertion d'immunité SSRF la plus directe.
    request: () => http.get(`${BASE_URL}//evil.example/api/products/1`),
  },
  {
    name: 'null_byte',
    // Un octet nul tronque le chemin pour un consommateur écrit en C.
    request: () => http.get(`${BASE_URL}/api/%00`),
  },
];

export default function () {
  group('refus attendus', () => {
    for (const attack of REJECTIONS) {
      const res = attack.request();
      check(
        res,
        {
          [`${attack.name} → 400`]: (r) => r.status === 400,
        },
        { assertion: attack.name },
      );
    }
  });

  group('épinglage de la sortie', () => {
    // Chaque requête porte une query unique : sans elle, le Shield servirait la
    // réponse mise en cache au premier passage et le banc mesurerait le cache au
    // lieu de mesurer le traitement des en-têtes. Le piège a été rencontré
    // pendant la mise au point, il est consigné ici pour qu'il ne se reprenne pas.
    const nonce = `${__VU}-${__ITER}-${Date.now()}`;
    const res = http.get(`${BASE_URL}/__echo?n=${nonce}`, {
      headers: {
        'X-Forwarded-Host': 'evil.example',
        'X-Forwarded-For': '1.2.3.4',
        'X-Real-IP': '8.8.8.8',
        Forwarded: 'for=9.9.9.9;host=evil.example',
      },
    });

    let echoed = {};
    try {
      echoed = res.json();
    } catch (e) {
      echoed = {};
    }

    check(
      { res, echoed },
      {
        // La destination reste celle que l'exploitant a fixée, quoi qu'envoie
        // le client. C'est l'invariant qui tient lieu d'immunité SSRF.
        'la sortie reste épinglée sur l amont configuré': (c) =>
          c.res.status === 200 && c.echoed.host === 'legacy-nginx',
        // Le X-Forwarded-Host du client est écrasé par le Host réellement
        // observé, jamais relayé.
        'X-Forwarded-Host forge est ecrase': (c) => c.echoed.x_forwarded_host === 'localhost',
        // La valeur forgée peut rester dans la chaîne, mais le pair RÉEL est
        // ajouté en DERNIER — la seule position qu'un consommateur correct lit.
        'le pair reel est ajoute en fin de chaine': (c) =>
          typeof c.echoed.x_forwarded_for === 'string' &&
          !c.echoed.x_forwarded_for.endsWith('1.2.3.4'),
        // `Forwarded` (RFC 7239) et `X-Real-IP` portent la même information :
        // les laisser passer laisserait au client la paternité de ce que ce
        // saut n'a jamais observé.
        'Forwarded et X-Real-IP sont supprimes': (c) =>
          c.echoed.forwarded === null && c.echoed.x_real_ip === null,
      },
      { assertion: 'egress_pinning' },
    );
  });

  group('confidentialite du cache', () => {
    const nonce = `${__VU}-${__ITER}-${Date.now()}`;

    // Une réponse obtenue avec un Cookie ou un Authorization est PRIVÉE : la
    // mutualiser entre clients est la fuite de session classique d'un cache de
    // passerelle.
    const withCookie = http.get(`${BASE_URL}/api/catalogue`, { headers: { Cookie: `sid=${nonce}` } });
    const withAuth = http.get(`${BASE_URL}/api/catalogue`, { headers: { Authorization: 'Bearer forged' } });

    check(
      { withCookie, withAuth },
      {
        'une requete porteuse de Cookie n est jamais servie du cache': (c) =>
          c.withCookie.headers['X-Ecoshield-Cache'] === 'BYPASS',
        'une requete porteuse d Authorization n est jamais servie du cache': (c) =>
          c.withAuth.headers['X-Ecoshield-Cache'] === 'BYPASS',
      },
      { assertion: 'cache_privacy' },
    );
  });

  group('observations — surface non defendue', () => {
    // CE GROUPE NE PROUVE PAS UNE PROTECTION : il constate une propriété, pour
    // qu'elle soit écrite noir sur blanc plutôt que supposée.
    //
    // La passerelle ne réécrit QUE la famille de transfert. Un en-tête d'identité
    // arbitraire traverse tel quel — comportement correct pour un proxy
    // transparent dont l'amont possède sa propre authentification, et mise en
    // garde d'exploitation dès lors que l'amont accorderait sa confiance à un
    // en-tête « venu du réseau ». Il n'y a rien à forger ici parce qu'il n'y a
    // rien qui vérifie : c'est la réponse honnête à la demande d'un 401 sur
    // assertion falsifiée.
    const nonce = `${__VU}-${__ITER}-${Date.now()}`;
    const res = http.get(`${BASE_URL}/__echo?n=${nonce}`, {
      headers: {
        'X-Authenticated-User': 'admin',
        'X-Waffle-Assertion': 'forged.assertion.value',
      },
    });

    let echoed = {};
    try {
      echoed = res.json();
    } catch (e) {
      echoed = {};
    }

    check(
      echoed,
      {
        'un en-tete d identite arbitraire traverse tel quel (constat, pas garantie)': (e) =>
          e.x_authenticated_user === 'admin' && e.x_waffle_assertion === 'forged.assertion.value',
      },
      { assertion: 'identity_passthrough' },
    );
  });
}

export const handleSummary = makeHandleSummary('perimeter');
