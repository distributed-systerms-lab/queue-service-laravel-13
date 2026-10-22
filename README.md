# Queue Service — Laravel 13

Versione Laravel 13 del [Queue Service in Go](https://github.com/distributed-systerms-lab/queue-service), letta al commit `3d25da84a12b561e17240090ccbea03c83019ef7`.

Stesso servizio gRPC `queue.v1.QueueService/Publish`, stessi campi protobuf e stesso schema delle ricevute. RabbitMQ gestisce code quorum persistenti, conferme di pubblicazione, ACK/NACK manuali, retry limitati e dead-letter queue. PostgreSQL registra l'effetto del consumer e sopprime i duplicati.

## Avvio

Servono Docker Engine e Docker Compose v2. PHP e Composer sono inclusi nell'immagine.

```sh
git clone https://github.com/distributed-systerms-lab/queue-service-laravel-13.git
cd queue-service-laravel-13
# Finché la PR non è integrata:
git switch feat/laravel-queue-service
cp .env.example .env
```

Imposta le due password in `.env`, usando caratteri sicuri nelle URI, per esempio l'output di `openssl rand -hex 24`.

`LAB_NETWORK` deve indicare la rete Docker condivisa già esistente nel laboratorio. Compose la usa come rete esterna. Se devi crearla, scegli prima una subnet che non si sovrapponga a LAN, VPN o altre reti Docker:

```sh
# Esempio da adattare al piano di indirizzamento del laboratorio.
docker network create --driver bridge --subnet 172.30.10.0/24 lab-messaging
docker compose up -d --build --wait
docker compose exec queue-service-laravel-13 php artisan queue:publish \
  --id=first-message --type=lab.echo --payload='hello from Laravel' \
  --correlation-id=first-run
docker compose logs -f consumer
```

`message accepted`: conferma di RabbitMQ. `message processed`: ricevuta PostgreSQL committata o duplicato riconosciuto. `message acknowledged`: ACK inviato. Le differenze tra questi eventi sono descritte nel [contratto di consegna](docs/delivery-contract.md).

Nessuna porta viene pubblicata sull'host. Gli altri container raggiungono il servizio su `queue-service-laravel-13:50051` attraverso `LAB_NETWORK`. Il nome è diverso da quello Go, così le due implementazioni possono convivere.

## Comandi del laboratorio

```sh
docker compose exec queue-service-laravel-13 php artisan queue:health
docker compose exec queue-service-laravel-13 php artisan queue:inspect --queue=tasks
docker compose exec queue-service-laravel-13 php artisan queue:publish --id=poison-1 --type=lab.fail
docker compose exec queue-service-laravel-13 php artisan queue:peek-dead --queue=tasks
```

`queue:publish` chiama l'API gRPC tramite il client incluso, quindi percorre la stessa strada di un chiamante esterno. `queue:peek-dead` legge un messaggio e lo rimette nella DLQ; il payload JSON è codificato in base64.

## Lettura del codice

Parti dalla [guida di lettura Laravel](docs/laravel-reading-guide.md). Il percorso è:

1. `api/queue/v1/queue.proto`: contratto pubblico.
2. `grpc-worker.php`: avvio Laravel e registrazione del servizio.
3. `app/Grpc/QueueService.php`: validazione e risposta gRPC.
4. `app/Queue/RabbitPublisher.php`: pubblicazione e conferma.
5. `app/Queue/DeliveryHandler.php`: effetto, retry, DLQ e ACK.
6. `app/Queue/ReceiptStore.php`: transazione e deduplicazione.
7. `app/Console/Commands/Consume.php`: processi consumer e arresto.

Le classi dei messaggi protobuf vengono generate da `protoc` durante la build; l'interfaccia RoadRunner a un solo metodo è scritta in `app/Grpc/QueueServiceInterface.php`.

## Verifiche

```sh
make check             # Build ambiente test, lint PHP e unit test.
make audit             # Audit delle dipendenze nell'immagine test.
make integration       # RabbitMQ e PostgreSQL reali, gRPC e morte del processo.
make integration-down  # Elimina soltanto container e dati del progetto di test.
make build             # Immagine runtime.
```

I test di integrazione usano una rete Compose separata, senza porte sull'host e senza la rete esterna del laboratorio. [CI](.github/workflows/ci.yaml) esegue questi controlli; il [registro verifiche](docs/verification.md) distingue i risultati osservati dai controlli solo previsti.

Per sviluppo fuori da Docker: PHP 8.4 con le estensioni elencate in `composer.json`, Composer, `protoc`, `rr` 3.0.0 e `grpcurl` 1.9.3. Genera i messaggi prima di avviare il servizio:

```sh
mkdir -p generated
protoc -I api --php_out=generated api/queue/v1/queue.proto
composer install
```

Imposta `AMQP_URL`, `DATABASE_URL`, `GRPC_ADDRESS=0.0.0.0:50051` e `PUBLISH_CONCURRENCY=4`. Il processo API richiede RabbitMQ; solo il consumer richiede PostgreSQL.

## Limiti dell'esempio

L'effetto del consumer è la ricevuta stessa. Operazioni di business esterne richiedono un proprio contratto di idempotenza o un outbox. Un singolo nodo RabbitMQ conserva i dati dopo un riavvio con volume intatto; non offre alta disponibilità.

L'API del laboratorio usa connessioni in chiaro, reflection gRPC e nessuna autenticazione. La [guida Docker](docs/docker-operation.md) descrive reti, processi, volumi e configurazione. Gli [esperimenti](docs/failure-experiments.md) mostrano duplicati, retry, crash e persistenza.
