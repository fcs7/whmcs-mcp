# OAuth — integração com banco real

Requer Docker Compose e `vendor/` instalado na raiz do addon. O banco é descartável,
sem porta publicada, em rede interna. O script recusa nomes de banco fora do prefixo
`nt_mcp_test`. Nunca apontar este teste a um banco real de WHMCS.

Na raiz do addon:

```sh
docker compose -f tests/integration/compose.yaml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f tests/integration/compose.yaml down -v
```

Exercita o migration e o grant handler reais com Illuminate Database 8.83.27/MariaDB 11.4:
pedido pendente, aprovação válida, replay, rollback após falha no insert do refresh,
concorrência de refresh com revogação global/por família/remoção do client e recusa de MyISAM.
As dependências aqui são exclusivas do harness; não entram no vendor de produção.
