<?php

/**
 * Falha se a cobertura de linhas do relatório clover estiver abaixo do mínimo (spec: ≥ 80%).
 * Uso: php scripts/coverage-check.php coverage/clover.xml 80
 */
$file = $argv[1] ?? 'coverage/clover.xml';
$min = (float) ($argv[2] ?? 80);
if (! is_file($file)) {
    fwrite(STDERR, "Relatório de cobertura não encontrado: {$file}\n");
    exit(1);
}
$metrics = simplexml_load_file($file)->project->metrics;
$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$pct = $total > 0 ? 100 * $covered / $total : 0;
printf("Cobertura de linhas: %.2f%% (%d/%d), mínimo %.0f%%\n", $pct, $covered, $total, $min);
exit($pct >= $min ? 0 : 1);
