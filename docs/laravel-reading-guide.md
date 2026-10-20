# Come parte e opera il servizio Laravel

## 1. Docker sceglie il processo iniziale

Nel servizio `queue-service-laravel-13`, Compose avvia:

```sh
rr serve -c .rr.yaml
```

`rr` è RoadRunner: apre la porta gRPC 50051 e avvia il numero di processi PHP indicato da `PUBLISH_CONCURRENCY`. Il comando per ciascun processo è `php grpc-worker.php`.

Ogni processo gestisce una chiamata alla volta. Con quattro processi possono avanzare quattro pubblicazioni contemporaneamente.

## 2. PHP esegue grpc-worker.php

La prima inclusione è `vendor/autoload.php`, generata da Composer. Questo file registra l'autoloader: quando PHP incontra una classe non ancora caricata, l'autoloader risolve il nome nel relativo file.

Le corrispondenze sono dichiarate in `composer.json`:

| Prefisso della classe | Directory |
| --- | --- |
| `App\\` | `app/` |
| `Queue\\V1\\` | `generated/Queue/V1/` |
| `GPBMetadata\\` | `generated/GPBMetadata/` |

Per esempio `App\\Grpc\\QueueService` corrisponde a `app/Grpc/QueueService.php`. I prefissi delle librerie installate sono aggiunti da Composer attraverso i rispettivi pacchetti.

## 3. Il bootstrap costruisce Laravel

`grpc-worker.php` include `bootstrap/app.php`, che costruisce l'applicazione Laravel. Il bootstrap del kernel console carica ambiente, configurazione e provider.

`bootstrap/providers.php` include `AppServiceProvider`. Il provider registra nel container come costruire `Settings`, `Publisher` e `ReceiptStore`. Registrare una factory non apre subito una connessione: l'istanza viene costruita quando viene richiesta.

Quando il worker risolve `QueueService`, Laravel costruisce le sue dipendenze. La factory del publisher apre RabbitMQ, dichiara le code e chiude quella connessione iniziale. Il processo API non costruisce il database.

## 4. RoadRunner inoltra una chiamata

RoadRunner legge il messaggio gRPC secondo `api/queue/v1/queue.proto` e lo passa al worker PHP attraverso pipe. La libreria RoadRunner ricostruisce `PublishRequest` e chiama `QueueService::Publish()`.

Il metodo controlla che la coda sia ammessa e che l'envelope sia valido. Poi chiama `RabbitPublisher::publish()`.

Il publisher apre una connessione e un canale per quella chiamata, abilita le conferme, invia un messaggio persistente con `mandatory=true` e attende la risposta del broker. Un messaggio non instradabile fallisce anche se il broker invia una conferma.

Solo dopo la conferma senza return il metodo restituisce `PublishResponse`. La libreria serializza la risposta protobuf e RoadRunner la invia al client.

Una connessione per pubblicazione mantiene indipendenti gli esiti e permette alla chiamata successiva di ripartire dopo un riavvio del broker. Costa più di un pool: è una scelta di semplicità per questo laboratorio.

## 5. Il consumer parte da Artisan

Il secondo servizio applicativo avvia:

```sh
php artisan queue:consume
```

`artisan` include l'autoloader, costruisce Laravel e passa il comando al kernel console. Laravel trova la classe `Consume` in `app/Console/Commands`.

Il comando principale avvia `CONCURRENCY` processi figli indipendenti, ciascuno con:

```sh
php artisan queue:consume --single
```

Ogni figlio esegue il bootstrap e apre le proprie connessioni RabbitMQ e PostgreSQL. Non eredita socket già aperti dal padre.

## 6. Il figlio riceve un messaggio

`Consumer::run()` dichiara la topologia, prepara la tabella delle ricevute e imposta il prefetch a uno. Registra quindi una sottoscrizione con ACK automatico disabilitato.

Quando arriva una delivery, `DeliveryHandler::handle()`:

1. Ricostruisce e valida l'envelope AMQP.
2. Chiede a `ReceiptStore` di registrarlo.
3. La transazione PostgreSQL inserisce la ricevuta, oppure confronta la fingerprint della ricevuta esistente.
4. Dopo un commit riuscito o un duplicato identico, invia ACK.

In caso di errore transitorio aspetta il backoff e invia `basic.reject` con requeue. Per un conflitto, un envelope invalido o tentativi esauriti invia NACK senza requeue: RabbitMQ trasferisce il messaggio alla DLQ.

## 7. L'arresto interrompe il lavoro incompleto

Il padre intercetta SIGTERM e SIGINT e li inoltra ai figli. Ogni figlio controlla un token tra le operazioni e durante le attese.

Se viene richiesto l'arresto prima dell'ACK, il figlio chiude la connessione lasciando la delivery senza ACK. RabbitMQ può ridistribuirla. Dopo un commit già riuscito, la nuova delivery trova la ricevuta e non ripete l'inserimento.

Il padre aspetta fino a `SHUTDOWN_TIMEOUT`, poi termina i figli rimasti. Se un figlio muore inaspettatamente, termina gli altri ed esce con errore, consentendo a Docker di riavviare il gruppo.
