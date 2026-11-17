# fiapx-video-processor

Worker do FIAP X: consome `ProcessVideo` e `CleanupArtifacts`, extrai 1 frame por segundo com ffmpeg, grava o
zip no S3 e responde à SAGA. Escalado pelo KEDA de 1 a 10 pods, um vídeo por pod.

> Parte do **Hackathon FIAP X — Processador de Vídeos** (PÓS TECH SOAT, Fase 5).
> Etapa 5: código, testes, imagem, manifests, CI e deploy na AWS (`deploy.yml`). A documentação vem
> antes do código: a arquitetura, as decisões (ADRs/RFCs) e os contratos ficam em
> [`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs) (spec §8.5,
> [ADR-009](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/04-adrs/ADR-009-worker-laravel-no-eks-escalado-por-keda.md)).

## Stack

PHP 8.4.27 (CLI) · Laravel 13 · ffmpeg · AWS SDK (S3, SQS, SSM) · Symfony Process · ZipArchive · ramsey/uuid
(UUID v7) · PHPUnit + Mockery · opis/json-schema (contratos) · Pint · PHPStan (Larastan, nível 6) · Docker ·
Kustomize (EKS) · KEDA

## Arquitetura

Worker sem HTTP e sem banco (stateless). Dentro dele, DDD + Clean Architecture
([ADR-003](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/04-adrs/ADR-003-ddd-e-clean-architecture-nos-servicos-laravel.md)),
com as dependências apontando para dentro:

```text
app/Domain/Processing/     ProcessingJob (PROCESSING → COMPLETED | FAILED), VOs (FramesPerSecond, StorageLocation,
                           FrameSet, ProcessingError, SagaReference...), exceções ProcessingFailed (definitiva) e
                           TransientFailure, interfaces FrameExtractor, FrameArchiver, ObjectStorage e ReplyPublisher
app/Application/           ProcessVideoUseCase, CleanupArtifactsUseCase, portas Heartbeat, Workspace e ProcessingTelemetry,
                           IncomingCommand (consumidor tolerante) e MessageFactory (envelope dos contratos v1)
app/Infrastructure/        FfmpegFrameExtractor, ZipFrameArchiver, S3ObjectStorage, LocalWorkspace,
                           SqsCommandConsumer, SqsReplyPublisher, VisibilityHeartbeat, ParameterResolver (env → SSM),
                           NewRelicProcessingTelemetry (evento VideoProcessed)
app/Presentation/Console/  processor:consume (o laço do worker) e processor:alive (liveness)
app/Providers/RepositoryServiceProvider.php   liga cada interface à implementação
```

### Fluxo de uma mensagem

```text
ReceiveMessage (long polling 20 s, 1 mensagem)
  ProcessVideo     → zip já existe em results/{video_id}/frames.zip? → VideoProcessed (sem reprocessar)
                   → ProcessingStarted → baixa uploads/{video_id}.{ext} em stream para /work
                   → ffmpeg -vf fps=1 (heartbeat: ChangeMessageVisibility +300 s a cada 60 s)
                   → zip (ZipArchive, frames sem recompressão) → sobe results/{video_id}/frames.zip
                   → VideoProcessed {zip_key, frame_count, zip_size_bytes, duration_ms}
  CleanupArtifacts → apaga results/{video_id}/ → ArtifactsCleaned {deleted_objects}
DeleteMessage só depois de subir o zip e publicar a resposta; /work limpo no finally
```

| Situação | O que o worker faz |
|---|---|
| Sucesso | responde e apaga a mensagem |
| Falha definitiva: vídeo corrompido ou timeout do ffmpeg (`FFMPEG_ERROR`), nenhum frame (`NO_FRAMES`), vídeo inexistente (`DOWNLOAD_ERROR`) | `VideoProcessingFailed` e apaga a mensagem |
| Falha transitória (S3, rede, ffmpeg morto por sinal) | não apaga: a mensagem volta para a fila |
| Falha transitória na última tentativa (`ApproximateReceiveCount ≥ FIAPX_MAX_ATTEMPTS`, padrão 3 = `maxReceiveCount` da fila) | `VideoProcessingFailed` (`DOWNLOAD_ERROR`, `UPLOAD_ERROR`...) e apaga, para a saga compensar sem esperar o timeout |
| Mensagem mal formada (JSON inválido, versão ou tipo desconhecido, chaves fora da convenção) | log de erro; volta para a fila e vai para a DLQ |
| Erro inesperado | log de erro; volta para a fila |
| `SIGTERM`/`SIGINT` | termina a mensagem atual e não pega outra |

Respostas na `saga-replies.fifo` com `MessageGroupId = saga_id` e `MessageDeduplicationId = message_id`;
`saga_id`, `video_id` e `correlation_id` são sempre os do comando. Logs em JSON no stderr com `correlation_id`,
`saga_id`, `video_id` e `message_id`; com o agente New Relic, cada mensagem vira uma transação de background.

Antes de cada resposta `VideoProcessed`, o worker registra o evento customizado `VideoProcessed` no New Relic
([observabilidade](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/07-observabilidade/observabilidade.md)):
`video_id`, `saga_id`, `correlation_id`, `worker_id` (nome do pod), `duration_ms` (download + ffmpeg + zip +
upload), `ffmpeg_ms`, `frame_count`, `video_size_bytes`, `zip_size_bytes` e `reused`. Na resposta idempotente
(`reused = true`), `duration_ms` é o do processamento original (metadado do zip) e `ffmpeg_ms` e
`video_size_bytes` são 0. Sem o agente (local e testes), nada é enviado. Consulta:
`SELECT * FROM VideoProcessed WHERE video_id = '<id>' SINCE 1 day ago`.

O zip guarda nos metadados do objeto `frame-count` e `duration-ms`, que a resposta idempotente reaproveita.
Sem esses metadados, o vídeo é reprocessado.

## Mensagens

Contratos (JSON Schema) em [`fiapx-docs/contracts`](https://github.com/hackathon-fiap-soat/fiapx-docs/tree/main/contracts),
fila `fiapx-<env>-processing-commands` (Standard, visibility 300 s, DLQ após 3) na entrada e
`fiapx-<env>-saga-replies.fifo` na saída.

| Consome | Produz |
|---|---|
| `ProcessVideo` v1 (`bucket`, `video_key`, `fps`, `output_prefix`) | `ProcessingStarted`, `VideoProcessed`, `VideoProcessingFailed` |
| `CleanupArtifacts` v1 (`bucket`, `prefix`) | `ArtifactsCleaned` |

Produtor estrito (testes validam cada resposta no schema, sem campos extras) e consumidor tolerante (ignora
campos desconhecidos). Os testes leem os schemas direto do `fiapx-docs` clonado ao lado (`FIAPX_DOCS_DIR`;
no CI, um checkout do `fiapx-docs`): nada é copiado para cá.

## Como rodar

Pré-requisitos: Docker e os repositórios `fiapx-infra` e `fiapx-docs` clonados ao lado deste. O PHP e o ffmpeg
rodam no container; não precisa deles na máquina.

```bash
# 1. Ambiente local (MySQL + LocalStack + Mailpit)
(cd ../fiapx-infra/local && docker compose up -d --wait)

# 2. Dependências
cp .env.example .env
docker compose run --rm processor composer install

# 3. Worker
docker compose up -d
docker compose logs -f processor
```

### Fluxo ponta a ponta local

Com o `fiapx-video-api` de pé **sem** o profile `simulador` (`cd ../fiapx-video-api && docker compose up -d`),
este worker substitui o `processor:simulate`:

```bash
TOKEN=$(curl -s -X POST localhost:3001/api/auth/login -H 'content-type: application/json' \
  -d '{"email":"maria@example.com","password":"Senha-forte-123!"}' | jq -r .access_token)   # fiapx-auth: npm run dev

# vídeo de 3 s gerado com o ffmpeg da imagem
docker run --rm -v "$PWD":/s fiapx-video-processor:dev ffmpeg -loglevel error -f lavfi \
  -i testsrc=duration=3:size=320x240:rate=10 -pix_fmt yuv420p -y /s/aula.mp4

R=$(curl -s -X POST localhost:8080/api/videos -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' \
  -d "{\"filename\":\"aula.mp4\",\"size_bytes\":$(wc -c < aula.mp4),\"content_type\":\"video/mp4\"}")
# POST pré-assinado: todos os upload.fields e o arquivo por último
curl -s $(echo "$R" | jq -r '.upload.fields | to_entries[] | "-F \(.key)=\(.value)"') -F file=@aula.mp4 "$(echo "$R" | jq -r .upload.url)"
ID=$(echo "$R" | jq -r .id)
curl -s localhost:8080/api/videos/$ID -H "authorization: Bearer $TOKEN"            # ... COMPLETED, frame_count 3
curl -s -o frames.zip "$(curl -s localhost:8080/api/videos/$ID/download -H "authorization: Bearer $TOKEN" | jq -r .url)"
unzip -l frames.zip                                                                  # frame_0001.png .. frame_0003.png
```

Um arquivo que não é vídeo termina em `FAILED` com `FFMPEG_ERROR` (o worker responde `VideoProcessingFailed`,
a saga manda `CleanupArtifacts` e recebe `ArtifactsCleaned`).

## Como testar

```bash
docker compose run --rm processor composer lint            # pint --test + phpstan nível 6
docker compose run --rm processor composer test            # todas as suítes do PHPUnit
docker compose run --rm processor composer test:coverage   # com cobertura mínima de 80% (é o que o CI roda)
```

| Suíte | O que cobre |
|---|---|
| `Unit` | domínio, casos de uso com Mockery, heartbeat, workspace, zip e o laço do `processor:consume` com fila em memória (ack, retry, última tentativa, mensagem inválida) |
| `Contract` | respostas produzidas × JSON Schemas do `fiapx-docs` e consumo dos exemplos de comando com campos desconhecidos |
| `Integration` | ffmpeg real com vídeos gerados na hora (3 s → 3 frames, corrompido, < 1 s, timeout, sinal), S3 e SQS no LocalStack e o `processor:consume` de ponta a ponta (sucesso, idempotência, limpeza e vídeo corrompido) |

`Contract` e `Integration` precisam do `fiapx-docs` ao lado e do ambiente local de pé. A `Integration` cria um
bucket e filas temporários no LocalStack (não toca nos recursos do ambiente nem disputa mensagens com o
`fiapx-video-api`) e apaga tudo no fim.

## Variáveis de ambiente

| Variável | Uso | Padrão |
|---|---|---|
| `FIAPX_ENV` | Prefixo dos parâmetros `/fiapx/<env>/...` | `local` |
| `AWS_REGION`, `AWS_ENDPOINT_URL` | Região e endpoint (LocalStack no local; vazio na AWS) | `us-east-1` |
| `SQS_PROCESSING_COMMANDS_URL`, `SQS_SAGA_REPLIES_URL` | Filas; vazias = lidas do SSM (`sqs/processing-commands/url`, `sqs/saga-replies/url`) | SSM |
| `FIAPX_WORK_DIR` | Diretório de trabalho | `/work` |
| `FIAPX_WORKER_ID` | `worker_id` do `ProcessingStarted` (nome do pod) | hostname |
| `FIAPX_CONSUMER_WAIT_SECONDS` | Long polling do `ReceiveMessage` | `20` |
| `FIAPX_MAX_ATTEMPTS` | Recebimentos antes da DLQ; na última tentativa a falha transitória vira definitiva | `3` |
| `FIAPX_FFMPEG_TIMEOUT_SECONDS` | Limite do ffmpeg (menor que o timeout de 30 min da saga) | `1500` |
| `FIAPX_HEARTBEAT_INTERVAL_SECONDS`, `FIAPX_HEARTBEAT_EXTENSION_SECONDS` | Heartbeat de visibility | `60`, `300` |
| `FIAPX_ALIVE_FILE`, `FIAPX_ALIVE_MAX_AGE_SECONDS` | Arquivo e limite do liveness | `/tmp/fiapx-processor-alive`, `600` |
| `NEW_RELIC_ENABLED`, `NEW_RELIC_LICENSE_KEY` | Agente New Relic (só na imagem de produção) | desligado |

O bucket vem em cada comando (`payload.bucket`). O worker não usa banco: `DB_*` não existe aqui.

## Imagem e Kubernetes

- `Dockerfile` multi-stage: `dev` (composer, pcov) e `prod` (`php:8.4.27-cli-alpine` + `ffmpeg`, extensões
  `zip` e `pcntl`, agente New Relic, `USER 82:82` numérico, `/work` e `HEALTHCHECK`). A imagem de produção é
  `linux/amd64`, porque o agente New Relic para Alpine só existe para x86_64. CMD `php artisan processor:consume`.
- `k8s/base` + `k8s/overlays/prod` (Kustomize), namespace `fiapx`:
  - Deployment `video-processor`: `runAsNonRoot`, seccomp `RuntimeDefault`, `drop: [ALL]`; `/work` em `emptyDir`
    de 10Gi; `terminationGracePeriodSeconds: 1800`; requests 500m/512Mi e limits 1500m/1536Mi (2 a 4 nós
    `t3.large`); liveness `php artisan processor:alive`; `FIAPX_WORKER_ID` = nome do pod.
  - `ScaledObject` (`aws-sqs-queue` na `processing-commands`, `queueLength: "1"`, 1 a 10 réplicas) e
    `TriggerAuthentication` com `identityOwner: keda`.
  - ServiceAccount sem Pod Identity: na AWS Academy os pods e o KEDA usam a `LabRole` dos nós pelo IMDS
    ([ADR-019](https://github.com/hackathon-fiap-soat/fiapx-docs/blob/main/04-adrs/ADR-019-deploy-na-aws-academy-learner-lab.md)).
  - O overlay gera o ConfigMap `fiapx-video-processor-aws` de `aws.env` (exemplo em `aws.env.example`; o real é
    gerado do SSM no deploy e fica fora do git) e substitui a URL da fila e a região no `ScaledObject`. A imagem
    `fiapx-video-processor` recebe o nome do ECR e a tag do commit no deploy.
- O repositório do ECR já existe no `fiapx-infra` (`/fiapx/<env>/ecr/<repo>/url`); este repositório não tem Terraform.

## CI

`.github/workflows/ci.yml` em todo PR e push na `main`: testes (com `fiapx-docs` e `fiapx-infra` lado a lado e o
compose do ambiente local; pint, phpstan e phpunit com cobertura ≥ 80%), build da imagem `linux/amd64` com Trivy
(falha em CRITICAL) e `kustomize build` + `kubeconform` (Kubernetes 1.33). Análise no **SonarCloud** ([organização `hackathon-fiap-soat`](https://sonarcloud.io/organizations/hackathon-fiap-soat/projects)) depois dos testes, com a cobertura e o Quality Gate como check (`sonar-project.properties`, Secret `SONAR_TOKEN`). O `clover.xml` gerado no
container tem os caminhos ajustados para o runner antes da análise.

## Deploy

- `deploy.yml`: em todo merge na `main` (exceto mudanças só de documentação) e quando o `deploy-all` do
  `fiapx-infra` chama (ADR-018), logo depois da infraestrutura, em paralelo com notification → auth →
  video-api. Lê o SSM `/fiapx/prod/...` e gera `k8s/overlays/prod/aws.env` (com o ambiente destruído, falha
  aqui), faz o build `linux/amd64` (`--target prod`) e o push no ECR `fiapx-video-processor` com a tag do
  commit, cria o Secret `fiapx-video-processor-app` (`NEW_RELIC_LICENSE_KEY`) e aplica os manifests,
  esperando o rollout.
- `destroy.yml`: chamado pelo `destroy-all`; remove o `ScaledObject` e depois os demais recursos do worker.
- Credenciais da AWS Academy nos Secrets `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` e `AWS_SESSION_TOKEN`
  (ADR-019). Os pods usam a `LabRole` do nó (S3 get/put/delete/list e SQS).
- O smoke do `deploy-all` cobre este worker: vídeo de 3 s → `COMPLETED` → zip com 3 frames.

## Links

- Documentação: [`fiapx-docs`](https://github.com/hackathon-fiap-soat/fiapx-docs)
- Todos os repositórios: [hackathon-fiap-soat](https://github.com/hackathon-fiap-soat)

## Contribuição

- A branch `main` é protegida: toda mudança entra por Pull Request (a aprovação é opcional; qualquer integrante, inclusive o autor, pode fazer o merge).
- O `README.md` deve estar sempre atualizado: toda mudança que afete propósito, stack, como rodar/testar,
  variáveis de ambiente ou links atualiza este arquivo no mesmo PR.
- Nunca commitar segredos (`.env`, chaves, tokens). Use `.env.example` com valores fictícios.
- Contexto para o Claude Code: [`CLAUDE.md`](CLAUDE.md) (projeto, regras e o papel deste repositório) e skills em
  [`.claude/skills/`](.claude/skills/) com os procedimentos deste repositório.

## Licença

[MIT](LICENSE)
