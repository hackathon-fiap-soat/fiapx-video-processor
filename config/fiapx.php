<?php

/*
 * Configuração do FIAP X. Cada recurso vem de variável de ambiente; quando ela está vazia, o
 * ParameterResolver busca no SSM em /fiapx/<FIAPX_ENV>/... (ADR-001, fiapx-docs/06-deploy/parametros-ssm.md).
 * Na AWS, o deploy preenche as variáveis a partir do SSM. O bucket vem em cada comando (payload.bucket).
 */
return [
    'env' => env('FIAPX_ENV', 'local'),

    'aws' => [
        'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        // Endpoint do LocalStack no ambiente local. Vazio na AWS.
        'endpoint' => env('AWS_ENDPOINT_URL'),
    ],

    // [variável de ambiente, chave SSM relativa a /fiapx/<env>/]
    'parameters' => [
        'processing_commands_queue_url' => [env('SQS_PROCESSING_COMMANDS_URL'), 'sqs/processing-commands/url'],
        'saga_replies_queue_url' => [env('SQS_SAGA_REPLIES_URL'), 'sqs/saga-replies/url'],
    ],

    'processor' => [
        // Identificador do worker no ProcessingStarted: o nome do pod (HOSTNAME) no Kubernetes.
        'worker_id' => env('FIAPX_WORKER_ID') ?: (gethostname() ?: 'fiapx-video-processor'),
        // Diretório de trabalho (emptyDir com sizeLimit no Kubernetes). Cada mensagem usa um subdiretório.
        'work_dir' => env('FIAPX_WORK_DIR', '/work'),
        // Long polling do ReceiveMessage (máximo do SQS: 20 s).
        'wait_seconds' => (int) env('FIAPX_CONSUMER_WAIT_SECONDS', 20),
        // Recebimentos antes da DLQ (maxReceiveCount da processing-commands). Na última tentativa, uma falha
        // transitória vira definitiva (VideoProcessingFailed) para a saga compensar sem esperar o timeout.
        'max_attempts' => (int) env('FIAPX_MAX_ATTEMPTS', 3),
        'ffmpeg_binary' => env('FIAPX_FFMPEG_BINARY', 'ffmpeg'),
        // Menor que o timeout da saga (30 min), para a falha chegar antes da compensação por timeout.
        'ffmpeg_timeout_seconds' => (int) env('FIAPX_FFMPEG_TIMEOUT_SECONDS', 1500),
        // Heartbeat de visibility (ADR-009): a cada 60 s, ChangeMessageVisibility para +300 s.
        'heartbeat_interval_seconds' => (int) env('FIAPX_HEARTBEAT_INTERVAL_SECONDS', 60),
        'heartbeat_extension_seconds' => (int) env('FIAPX_HEARTBEAT_EXTENSION_SECONDS', 300),
        // Liveness: arquivo tocado a cada volta do laço; o probe falha se ficar parado por mais que isto.
        'alive_file' => env('FIAPX_ALIVE_FILE', sys_get_temp_dir().'/fiapx-processor-alive'),
        'alive_max_age_seconds' => (int) env('FIAPX_ALIVE_MAX_AGE_SECONDS', 600),
    ],
];
