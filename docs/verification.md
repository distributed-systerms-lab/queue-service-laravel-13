# Registro verifiche

Il porting prende come riferimento il repository Go al commit `3d25da84a12b561e17240090ccbea03c83019ef7`.

L'ambiente locale della chat non era disponibile. Le esecuzioni effettive vengono verificate attraverso GitHub Actions; non viene dichiarata alcuna esecuzione locale.

## Controlli previsti

- Installazione Laravel 13 e dipendenze nel container PHP 8.4.
- Generazione dei messaggi protobuf dallo stesso contratto gRPC.
- Lint PHP e test unitari.
- Audit Composer.
- Test con RabbitMQ 4.3 e PostgreSQL 17 reali: chiamate gRPC, payload binari, duplicati concorrenti, retry e DLQ, messaggi non instradabili, conflitti, messaggi invalidi e SIGKILL dopo il commit.
- Validazione Compose e build dell'immagine runtime.

## Risultato osservato

Verifica CI in corso. Consultare il workflow associato all'ultimo commit della PR.
