<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reverse proxies de confiance
|--------------------------------------------------------------------------
|
| Seules ces adresses voient leurs en-têtes `X-Forwarded-*` honorées.
|
| Faire confiance à « toutes » les adresses (`*`) revient à croire n'importe
| quel appelant sur parole : il lui suffit d'envoyer un `X-Forwarded-For`
| différent à chaque requête pour repartir à zéro sur les limiteurs par IP,
| dont les cinq tentatives de connexion par minute. Le risque est réel dès que
| l'API est joignable autrement que par son proxy — un port publié suffit.
|
| Par défaut : boucle locale et plages privées, ce qui couvre un proxy sur la
| même machine comme sur le même réseau Docker. Renseigner TRUSTED_PROXIES
| quand le proxy se trouve ailleurs.
|
| Cette clé est lue par le middleware au moment de la requête, et non depuis
| `bootstrap/app.php` : la configuration n'y est pas encore chargée, et
| `config:cache` — que l'entrypoint exécute au démarrage — coupe de toute façon
| la lecture du `.env` en dehors des fichiers de configuration.
|
*/

$defaults = ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

$configured = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('TRUSTED_PROXIES', ''))
)));

return [

    // Une variable vide vaut « pas de choix explicite », donc les valeurs par
    // défaut : une liste vide ne ferait confiance à aucun proxy, et l'API
    // verrait l'adresse du proxy pour tous ses visiteurs.
    'proxies' => $configured !== [] ? $configured : $defaults,

];
