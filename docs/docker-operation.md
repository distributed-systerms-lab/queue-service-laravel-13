# Docker: reti, processi e dati

## Reti

Compose usa una rete privata `internal: true` per API, consumer, RabbitMQ e PostgreSQL. Solo l'API appartiene anche alla rete esterna `LAB_NETWORK`.

Un container del laboratorio risolve `queue-service-laravel-13` sulla rete condivisa e apre una connessione TCP alla porta 50051. RoadRunner riceve la chiamata gRPC e la passa a un processo PHP tramite pipe. Quel processo raggiunge RabbitMQ sulla rete privata.

Non sono pubblicate porte sull'host. La UI di RabbitMQ è disponibile soltanto nella rete privata: non viene esposta per impostazione predefinita.

L'alias Laravel è diverso dall'alias Go. I due stack hanno reti private e volumi separati, pur potendo condividere la rete esterna del laboratorio.

## Processi

L'API avvia RoadRunner, che gestisce un pool di `PUBLISH_CONCURRENCY` processi PHP. Il valore predefinito è 4. Ogni worker esegue una chiamata alla volta e viene riciclato dopo 1000 richieste.

Il consumer avvia un comando Artisan supervisore. Il supervisore crea `CONCURRENCY` figli, ognuno con una sottoscrizione e prefetch uno. Con due repliche e concorrenza quattro ci sono otto sottoscrizioni.

Ogni pubblicazione apre una connessione broker nuova. Ogni figlio consumer mantiene invece la propria connessione e controlla gli heartbeat anche durante le attese artificiali.

`init: true` fornisce l'init del container. I processi applicativi usano UID 10001, filesystem in sola lettura e `/tmp` temporanea. Le cache del bootstrap vengono preparate durante la build. I log Laravel e RoadRunner vanno su stderr; Docker li conserva con il driver `local` e rotazione.

## Configurazione

| Variabile | Default | Effetto |
| --- | --- | --- |
| `LAB_NETWORK` | `lab-messaging` | Nome della rete esterna esistente |
| `QUEUES` | `tasks` | Allowlist di code, separate da virgole |
| `QUEUE_NAME` | `tasks` | Coda sottoscritta dal consumer |
| `CONCURRENCY` | 4 | Figli per container consumer, da 1 a 128 |
| `PUBLISH_CONCURRENCY` | 4 | Processi Laravel nel pool gRPC |
| `MAX_RETRIES` | 3 | Tentativi aggiuntivi, da 0 a 100 |
| `MAX_QUEUE_LENGTH` | 100000 | Limite delle delivery pronte nella coda sorgente |
| `OPERATION_TIMEOUT` | `5s` | Timeout per operazione broker e statement database |
| `SHUTDOWN_TIMEOUT` | `15s` | Attesa del supervisore per l'arresto dei figli |
| `RETRY_DELAY` | `1s` | Backoff con slot occupato |
| `PROCESSING_DELAY` | `0s` | Attesa prima dell'effetto, per esperimenti |
| `ACKNOWLEDGMENT_DELAY` | `0s` | Attesa dopo il commit e prima dell'ACK |

Le durate accettano `ms`, `s` o `m` e non superano un minuto. Timeout operativi e di arresto devono essere positivi; le altre attese possono essere zero.

Modificare variabili che definiscono una coda già esistente, come delivery limit e lunghezza massima, può produrre un errore di topologia incompatibile. Usa code nuove per esperimenti con parametri diversi; non eliminare dati da conservare.

## Volumi e riavvio

`rabbitmq-data` conserva code e messaggi; `postgres-data` conserva le ricevute. `docker compose down` mantiene entrambi. Aggiungere `--volumes` li elimina, perdendo messaggi e deduplicazione.

Il nodo RabbitMQ ha hostname stabile `rabbitmq-laravel`. Il broker è singolo: le quorum queue qui non costituiscono un cluster ad alta disponibilità.

Docker usa `restart: unless-stopped`. Se un figlio consumer fallisce, il supervisore ferma gli altri ed esce con errore: il riavvio costruisce connessioni nuove. L'API ritenta implicitamente la connessione soltanto quando arriva una nuova chiamata.

Il timeout Compose per l'arresto è 20 secondi. Se aumenti `SHUTDOWN_TIMEOUT` oltre questo valore, aumenta anche `stop_grace_period`.

## Differenze di runtime rispetto a Go

Contratto protobuf, envelope AMQP, schema SQL e fingerprint sono compatibili. Cambiano il modello di concorrenza e alcuni limiti temporali: processi PHP al posto delle goroutine, connessione per pubblicazione e timeout per singola operazione anziché un unico context cancellabile.

Una richiesta gRPC cancellata può lasciare avanzare un'operazione PHP già iniziata. La garanzia applicativa resta: ripeti la stessa identità e lo stesso envelope quando non conosci l'esito, e il consumer sopprime l'effetto duplicato.

## Ambiente di test

`compose.test.yaml` ha nome di progetto, rete e credenziali propri; non pubblica porte e non usa `LAB_NETWORK`. I test creano code con nomi casuali e le rimuovono alla fine.

`make integration-down` rimuove soltanto questo progetto di test. Non eseguirlo con file Compose o nomi di progetto diversi.
