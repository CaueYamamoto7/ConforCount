<?php
/**
 * Configuração do Controle de Obrigações.
 *
 * Copie este arquivo para config/config.php e preencha os dados do banco.
 * O arquivo config.php NUNCA deve ficar dentro da pasta public/ nem ser enviado para o GitHub.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'obrigacoes',
        'user' => 'obrigacoes',
        'pass' => 'TROQUE_POR_UMA_SENHA_FORTE',
    ],

    // Fuso horário usado nos registros de data e hora.
    'timezone' => 'America/Sao_Paulo',

    // Tempo máximo sem usar o sistema antes de pedir login de novo (em minutos).
    'sessao_minutos' => 480,

    // Marque true se o site abre só por HTTPS (recomendado). Faz o cookie de login só trafegar em HTTPS.
    'cookie_seguro' => true,

    // Tentativas de login erradas permitidas por e-mail ou IP dentro da janela abaixo.
    'login_max_tentativas' => 8,
    'login_janela_minutos' => 15,

    // Tamanho mínimo das senhas.
    'senha_minima' => 8,
];
