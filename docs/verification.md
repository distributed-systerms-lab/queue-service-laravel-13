# Registro verifiche

Porting del repository Go al commit `3d25da84a12b561e17240090ccbea03c83019ef7`.

## Esecuzione osservata

Il 20 ottobre 2026 il [workflow CI 39465791553](https://github.com/distributed-systerms-lab/queue-service-laravel-13/actions/runs/39465791553) è terminato con esito **success** sul commit applicativo `86481799e849f25388043b78de4123d5c2a953d1`.

| Controllo | Risultato |
| --- | --- |
| Build ambiente test e validazione Composer con lock | Passato |
| Lint PHP | Passato |
| Suite unitaria | 34 test, 54 asserzioni |
| Suite completa con RabbitMQ e PostgreSQL reali | 44 test, 133 asserzioni |
| Audit Composer | Nessun advisory di vulnerabilità trovato |
| Validazione Compose | Passata |
| Build immagine runtime | Passata |
| Avvio runtime con utente non root e filesystem in sola lettura | Passato |
| Pubblicazione attraverso il comando Artisan e l'API gRPC | Passata |
| Messaggio confermato prima del riavvio di RabbitMQ | Presente dopo il riavvio: 1 pronto, 0 consumer |
| Elaborazione del messaggio persistito dopo il riavvio | Ricevuta registrata |
| Nuova pubblicazione dal processo API già avviato dopo il riavvio | Ricevuta registrata |

La suite completa comprende i 34 test unitari e 10 test di integrazione: messaggi binari, 12 pubblicazioni concorrenti con la stessa identità e quattro consumer, retry e DLQ, messaggi non instradabili, conflitti di identità, envelope invalidi, SIGKILL dopo il commit e prima dell'ACK, arresto del supervisore e dei figli, tre letture della DLQ senza rimozione, overflow della coda e delivery persistenti.

## Versioni osservate

- PHP 8.4.26.
- Laravel 13.38.0, fissato in `composer.lock`.
- php-amqplib 3.7.5 e roadrunner/grpc 3.8.1, fissati nel lock.
- RoadRunner 3.0.0 e grpcurl 1.9.3 nelle immagini di build.
- RabbitMQ 4.3 e PostgreSQL 17 nei servizi di test.

L'ambiente locale della chat non era disponibile: tutte queste esecuzioni sono avvenute su GitHub Actions, senza usare l'infrastruttura del laboratorio dell'utente. Gli aggiornamenti successivi del registro e degli esempi documentali non modificano il codice applicativo verificato.

## Confini della verifica

Il test di riavvio conserva il volume del broker e verifica che il messaggio sia ancora pronto prima di riavviare il consumer. Non verifica perdita di host o disco, replica RabbitMQ tra nodi, TLS, autenticazione o carichi prolungati. Il contratto e le differenze temporali rispetto al runtime Go sono descritti in [delivery-contract.md](delivery-contract.md) e [docker-operation.md](docker-operation.md).
