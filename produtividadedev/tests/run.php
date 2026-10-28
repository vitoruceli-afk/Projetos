<?php
// Executor dos testes automatizados (sem dependências). Uso:  php tests/run.php
// Usa o banco "produtividade_aw_test" (variável TESTE_DB_NAME muda o nome); recusa rodar no banco de trabalho.
require __DIR__ . '/bootstrap.php';
echo "Testes — banco " . DB_NAME . "\n\n";
foreach (glob(__DIR__ . '/*_test.php') as $arq) require $arq;
executarTestes();
