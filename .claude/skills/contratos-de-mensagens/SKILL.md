---
name: contratos-de-mensagens
description: Como este serviço produz e consome mensagens SQS seguindo os contratos do fiapx-docs (envelope, schemas versionados, idempotência, contract tests). Use ao escrever produtores, consumidores ou testes de mensagens.
---

# Contratos de mensagens

Os contratos (JSON Schema draft 2020-12) ficam em `fiapx-docs/contracts/`, com exemplos válidos em
`contracts/examples/`. Eles são a fonte da verdade: este repositório não define formato de mensagem por conta
própria.

## Este serviço

- **Consome** `ProcessVideo` e `CleanupArtifacts` (`processing-commands`).
- **Produz** `ProcessingStarted`, `VideoProcessed`, `VideoProcessingFailed` (com `failure_code`) e `ArtifactsCleaned` na `saga-replies.fifo`.
- Idempotência pela chave determinística `results/{video_id}/frames.zip`.

## Regras

- **Envelope padrão** em toda mensagem nossa: `message_id` (UUID v7, chave de idempotência), `type`,
  `version`, `saga_id`, `video_id`, `correlation_id`, `occurred_at` (UTC), `payload`. Só o
  `SendNotification` aceita `saga_id`/`video_id` nulos (e-mails de senha).
- **Produtor estrito:** a mensagem produzida valida contra o schema, sem campos extras.
- **Consumidor tolerante:** ignora campos desconhecidos e aceita as versões em transição.
- **Idempotência:** processar a mesma mensagem duas vezes não pode duplicar efeito.
- Mensagens são JSON puro e versionado; nunca jobs serializados do Laravel entre serviços.
- Na `saga-replies.fifo`, `MessageGroupId = saga_id` e `MessageDeduplicationId = message_id`.
- Propague o `correlation_id` em logs (JSON) e eventos do New Relic.

## Contract tests

- Validar as mensagens produzidas contra o schema e consumir os exemplos de `contracts/examples/` nos
  testes.
- A forma de obter os schemas no CI (cópia sincronizada ou checkout do `fiapx-docs`) é decidida na primeira
  etapa de código e documentada no README.
- Mudança incompatível: nova `version`. O consumidor passa a aceitar as duas versões antes de o produtor mudar
  (`fiapx-docs/06-deploy/fluxo-de-deploy.md`, "Mudanças de contrato").

## Mudar um contrato

Abra o PR no `fiapx-docs` primeiro (skill `contratos` de lá), depois ajuste produtor e consumidores.
