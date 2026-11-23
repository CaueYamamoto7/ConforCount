<?php
declare(strict_types=1);

/**
 * Cadastro-padrão que todo escritório novo recebe (uma cópia própria, que o escritório pode editar à vontade):
 * departamentos, características dos clientes e modelos de obrigação.
 *
 * Para mudar o que os NOVOS escritórios recebem, edite as listas abaixo. Escritórios já criados não são afetados.
 * Os prazos são pontos de partida: confira com o seu calendário (veja o README).
 */

const REGIMES_TODOS = ['MEI', 'Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
const REGIMES_PJ = ['Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
const REGIMES_LUCRO = ['Lucro Presumido', 'Lucro Real'];

function padrao_departamentos(): array
{
    return ['Fiscal', 'Contábil', 'Pessoal'];
}

function padrao_caracteristicas(): array
{
    return [
        'Tem funcionários (folha)',
        'Emite NFS-e (serviços)',
        'Emite NF-e (produtos)',
        'Compras com XML de entrada',
        'Tem ISS a recolher',
        'Tem ICMS',
    ];
}

/**
 * [nome, departamento, regimes, característica exigida (ou null), meses, tipo do prazo, valor, ajuste, observação]
 * tipo: dia | nutil | ultimo      ajuste: prorroga | antecipa
 */
function padrao_modelos(): array
{
    $F = 'Tem funcionários (folha)';
    return [
        // ---- Fiscal ----
        ['Receber XML das notas de saída (NF-e)', 'Fiscal', REGIMES_PJ, 'Emite NF-e (produtos)', [], 'dia', 5, 'prorroga', 'XML das vendas do mês anterior.'],
        ['Conferir NFS-e emitidas', 'Fiscal', REGIMES_PJ, 'Emite NFS-e (serviços)', [], 'dia', 5, 'prorroga', 'Notas de serviço do mês anterior (portal do município ou nacional).'],
        ['Receber XML das compras (entrada)', 'Fiscal', REGIMES_PJ, 'Compras com XML de entrada', [], 'dia', 5, 'prorroga', 'XML das notas de compra do mês anterior.'],
        ['Receber despesas e comprovantes', 'Fiscal', REGIMES_PJ, null, [], 'dia', 8, 'prorroga', 'Notas de despesa, recibos e comprovantes do mês anterior.'],
        ['Apurar e emitir o DAS', 'Fiscal', ['Simples Nacional'], null, [], 'dia', 20, 'prorroga', 'Se o dia 20 não for dia útil, vence no próximo dia útil.'],
        ['Emitir o DAS-MEI', 'Fiscal', ['MEI'], null, [], 'dia', 20, 'prorroga', null],
        ['PIS/COFINS', 'Fiscal', REGIMES_LUCRO, null, [], 'dia', 25, 'antecipa', 'Confira o ajuste para dia sem expediente.'],
        ['ISS (guia do município)', 'Fiscal', REGIMES_LUCRO, 'Tem ISS a recolher', [], 'dia', 10, 'antecipa', 'O dia varia por município: ajuste ao do seu cliente.'],
        ['ICMS (guia do estado)', 'Fiscal', REGIMES_LUCRO, 'Tem ICMS', [], 'dia', 10, 'antecipa', 'O dia varia por estado e atividade: ajuste.'],
        ['EFD ICMS/IPI (SPED Fiscal)', 'Fiscal', REGIMES_LUCRO, 'Tem ICMS', [], 'dia', 20, 'prorroga', 'O prazo varia por estado: ajuste.'],
        ['IRPJ/CSLL trimestral', 'Fiscal', ['Lucro Presumido'], null, [1, 4, 7, 10], 'ultimo', 0, 'antecipa', 'Trimestre anterior: vence no último dia útil do mês seguinte.'],
        ['IRPJ/CSLL por estimativa', 'Fiscal', ['Lucro Real'], null, [], 'ultimo', 0, 'antecipa', null],
        ['EFD-Contribuições', 'Fiscal', REGIMES_LUCRO, null, [], 'nutil', 10, 'prorroga', '10º dia útil do 2º mês subsequente.'],
        ['DEFIS', 'Fiscal', ['Simples Nacional'], null, [3], 'dia', 31, 'prorroga', 'Anual. Confira eventual prorrogação.'],
        ['DASN-SIMEI', 'Fiscal', ['MEI'], null, [5], 'dia', 31, 'prorroga', 'Anual. Confira eventual prorrogação.'],
        // ---- Contábil ----
        ['Receber extratos bancários', 'Contábil', REGIMES_PJ, null, [], 'dia', 8, 'prorroga', null],
        ['Fechar a contabilidade', 'Contábil', REGIMES_PJ, null, [], 'dia', 28, 'prorroga', null],
        ['ECD', 'Contábil', REGIMES_LUCRO, null, [5], 'ultimo', 0, 'prorroga', 'Anual. Confira eventual prorrogação.'],
        ['ECF', 'Contábil', REGIMES_LUCRO, null, [7], 'ultimo', 0, 'prorroga', 'Anual. Confira eventual prorrogação.'],
        // ---- Pessoal (só para quem tem folha) ----
        ['Fechar a folha', 'Pessoal', REGIMES_TODOS, $F, [], 'nutil', 5, 'prorroga', 'Pagamento até o 5º dia útil.'],
        ['eSocial: folha', 'Pessoal', REGIMES_TODOS, $F, [], 'dia', 15, 'prorroga', null],
        ['FGTS Digital', 'Pessoal', REGIMES_TODOS, $F, [], 'dia', 20, 'antecipa', 'Confirme o ajuste para dia sem expediente.'],
        ['DCTFWeb', 'Pessoal', REGIMES_TODOS, $F, [], 'ultimo', 0, 'antecipa', null],
    ];
}

/** Cria, para o escritório informado, uma cópia do cadastro-padrão. */
function semear_escritorio(PDO $pdo, int $eid): void
{
    $deptos = [];
    foreach (padrao_departamentos() as $i => $nome) {
        $pdo->prepare('INSERT INTO departamentos (escritorio_id, nome, ordem) VALUES (?, ?, ?)')->execute([$eid, $nome, $i + 1]);
        $deptos[$nome] = (int)$pdo->lastInsertId();
    }
    $cars = [];
    foreach (padrao_caracteristicas() as $i => $nome) {
        $pdo->prepare('INSERT INTO caracteristicas (escritorio_id, nome, ordem) VALUES (?, ?, ?)')->execute([$eid, $nome, $i + 1]);
        $cars[$nome] = (int)$pdo->lastInsertId();
    }
    $st = $pdo->prepare('INSERT INTO modelos (escritorio_id, departamento_id, nome, regimes, caracteristica_id, meses, venc_tipo, venc_valor, ajuste, observacao)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (padrao_modelos() as [$nome, $depto, $regimes, $car, $meses, $tipo, $valor, $ajuste, $obs]) {
        $st->execute([$eid, $deptos[$depto], $nome, implode(',', $regimes), $car === null ? null : $cars[$car], implode(',', $meses), $tipo, $valor, $ajuste, $obs]);
    }
}

/** Clientes fictícios (CNPJs inventados) para testar. Use: php bin/setup.php --exemplos=CNPJ_DO_ESCRITORIO */
function exemplos_clientes(): array
{
    $F = 'Tem funcionários (folha)'; $NFS = 'Emite NFS-e (serviços)'; $NFE = 'Emite NF-e (produtos)';
    $XML = 'Compras com XML de entrada'; $ISS = 'Tem ISS a recolher'; $ICMS = 'Tem ICMS';
    return [
        ['Padaria Pão Quente ME', 'Simples Nacional', [$F, $NFE, $XML]],
        ['Clínica Sorriso Odontologia', 'Simples Nacional', [$F, $NFS]],
        ['Studio Bela Pele', 'Simples Nacional', [$NFS]],
        ['Pet Shop Amigo Fiel', 'Simples Nacional', [$NFE, $XML]],
        ['Auto Peças Vale', 'Simples Nacional', [$F, $NFE, $XML]],
        ['Mercadinho Bom Preço', 'Simples Nacional', [$F, $NFE, $XML]],
        ['Transportes Rápido Norte', 'Simples Nacional', [$F]],
        ['Escritório de Engenharia Prisma', 'Simples Nacional', [$NFS]],
        ['João Silva Eletricista (MEI)', 'MEI', []],
        ['Maria Doces Artesanais (MEI)', 'MEI', []],
        ['Carlos Fotografia (MEI)', 'MEI', [$F]],
        ['Agência Criativa Pixel Ltda', 'Lucro Presumido', [$F, $NFS, $ISS]],
        ['Construtora Alicerce Ltda', 'Lucro Presumido', [$F, $NFS, $ISS, $XML]],
        ['Distribuidora Horizonte Ltda', 'Lucro Presumido', [$F, $NFE, $XML, $ICMS]],
        ['Consultoria Aurora Ltda', 'Lucro Presumido', [$NFS, $ISS]],
        ['Indústria Metalúrgica Forte S.A.', 'Lucro Real', [$F, $NFE, $XML, $ICMS]],
        ['Rede de Supermercados Central S.A.', 'Lucro Real', [$F, $NFE, $XML, $ICMS]],
    ];
}
