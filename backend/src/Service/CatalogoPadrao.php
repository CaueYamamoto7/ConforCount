<?php
declare(strict_types=1);

namespace App\Service;

use PDO;

class CatalogoPadrao
{
    private const REGIMES_TODOS = ['MEI', 'Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
    private const REGIMES_PJ = ['Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
    private const REGIMES_LUCRO = ['Lucro Presumido', 'Lucro Real'];

    public static function padraoDepartamentos(): array
    {
        return ['Fiscal', 'Contábil', 'Pessoal'];
    }

    public static function padraoCaracteristicas(): array
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
    public static function padraoModelos(): array
    {
        $F = 'Tem funcionários (folha)';

        return [
            // ---- Fiscal ----
            ['Receber XML das notas de saída (NF-e)', 'Fiscal', self::REGIMES_PJ, 'Emite NF-e (produtos)', [], 'dia', 5, 'prorroga', 'XML das vendas do mês anterior.'],
            ['Conferir NFS-e emitidas', 'Fiscal', self::REGIMES_PJ, 'Emite NFS-e (serviços)', [], 'dia', 5, 'prorroga', 'Notas de serviço do mês anterior (portal do município ou nacional).'],
            ['Receber XML das compras (entrada)', 'Fiscal', self::REGIMES_PJ, 'Compras com XML de entrada', [], 'dia', 5, 'prorroga', 'XML das notas de compra do mês anterior.'],
            ['Receber despesas e comprovantes', 'Fiscal', self::REGIMES_PJ, null, [], 'dia', 8, 'prorroga', 'Notas de despesa, recibos e comprovantes do mês anterior.'],
            ['Apurar e emitir o DAS', 'Fiscal', ['Simples Nacional'], null, [], 'dia', 20, 'prorroga', 'Se o dia 20 não for dia útil, vence no próximo dia útil.'],
            ['Emitir o DAS-MEI', 'Fiscal', ['MEI'], null, [], 'dia', 20, 'prorroga', null],
            ['PIS/COFINS', 'Fiscal', self::REGIMES_LUCRO, null, [], 'dia', 25, 'antecipa', 'Confira o ajuste para dia sem expediente.'],
            ['ISS (guia do município)', 'Fiscal', self::REGIMES_LUCRO, 'Tem ISS a recolher', [], 'dia', 10, 'antecipa', 'O dia varia por município: ajuste ao do seu cliente.'],
            ['ICMS (guia do estado)', 'Fiscal', self::REGIMES_LUCRO, 'Tem ICMS', [], 'dia', 10, 'antecipa', 'O dia varia por estado e atividade: ajuste.'],
            ['EFD ICMS/IPI (SPED Fiscal)', 'Fiscal', self::REGIMES_LUCRO, 'Tem ICMS', [], 'dia', 20, 'prorroga', 'O prazo varia por estado: ajuste.'],
            ['IRPJ/CSLL trimestral', 'Fiscal', ['Lucro Presumido'], null, [1, 4, 7, 10], 'ultimo', 0, 'antecipa', 'Trimestre anterior: vence no último dia útil do mês seguinte.'],
            ['IRPJ/CSLL por estimativa', 'Fiscal', ['Lucro Real'], null, [], 'ultimo', 0, 'antecipa', null],
            ['EFD-Contribuições', 'Fiscal', self::REGIMES_LUCRO, null, [], 'nutil', 10, 'prorroga', '10º dia útil do 2º mês subsequente.'],
            ['DEFIS', 'Fiscal', ['Simples Nacional'], null, [3], 'dia', 31, 'prorroga', 'Anual. Confira eventual prorrogação.'],
            ['DASN-SIMEI', 'Fiscal', ['MEI'], null, [5], 'dia', 31, 'prorroga', 'Anual. Confira eventual prorrogação.'],
            // ---- Contábil ----
            ['Receber extratos bancários', 'Contábil', self::REGIMES_PJ, null, [], 'dia', 8, 'prorroga', null],
            ['Fechar a contabilidade', 'Contábil', self::REGIMES_PJ, null, [], 'dia', 28, 'prorroga', null],
            ['ECD', 'Contábil', self::REGIMES_LUCRO, null, [5], 'ultimo', 0, 'prorroga', 'Anual. Confira eventual prorrogação.'],
            ['ECF', 'Contábil', self::REGIMES_LUCRO, null, [7], 'ultimo', 0, 'prorroga', 'Anual. Confira eventual prorrogação.'],
            // ---- Pessoal (só para quem tem folha) ----
            ['Fechar a folha', 'Pessoal', self::REGIMES_TODOS, $F, [], 'nutil', 5, 'prorroga', 'Pagamento até o 5º dia útil.'],
            ['eSocial: folha', 'Pessoal', self::REGIMES_TODOS, $F, [], 'dia', 15, 'prorroga', null],
            ['FGTS Digital', 'Pessoal', self::REGIMES_TODOS, $F, [], 'dia', 20, 'antecipa', 'Confirme o ajuste para dia sem expediente.'],
            ['DCTFWeb', 'Pessoal', self::REGIMES_TODOS, $F, [], 'ultimo', 0, 'antecipa', null],
        ];
    }

    /** Cria, para o escritório informado, uma cópia do cadastro-padrão. */
    public static function semearEscritorio(PDO $pdo, int $eid): void
    {
        $deptos = [];
        foreach (self::padraoDepartamentos() as $i => $nome) {
            $deptos[$nome] = self::inserirId($pdo, 'INSERT INTO departamentos (escritorio_id, nome, ordem) VALUES (?, ?, ?)', [$eid, $nome, $i + 1]);
        }
        $cars = [];
        foreach (self::padraoCaracteristicas() as $i => $nome) {
            $cars[$nome] = self::inserirId($pdo, 'INSERT INTO caracteristicas (escritorio_id, nome, ordem) VALUES (?, ?, ?)', [$eid, $nome, $i + 1]);
        }
        $st = $pdo->prepare('INSERT INTO modelos (escritorio_id, departamento_id, nome, regimes, caracteristica_id, meses, venc_tipo, venc_valor, ajuste, observacao)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::padraoModelos() as [$nome, $depto, $regimes, $car, $meses, $tipo, $valor, $ajuste, $obs]) {
            $st->execute([$eid, $deptos[$depto], $nome, implode(',', $regimes), $car === null ? null : $cars[$car], implode(',', $meses), $tipo, $valor, $ajuste, $obs]);
        }
    }

    /** Clientes fictícios (CNPJs inventados) para testar. Use: php bin/setup.php --exemplos=CNPJ_DO_ESCRITORIO */
    public static function exemplosClientes(): array
    {
        $F = 'Tem funcionários (folha)';
        $NFS = 'Emite NFS-e (serviços)';
        $NFE = 'Emite NF-e (produtos)';
        $XML = 'Compras com XML de entrada';
        $ISS = 'Tem ISS a recolher';
        $ICMS = 'Tem ICMS';

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

    private static function inserirId(PDO $pdo, string $sql, array $campos): int
    {
        $st = $pdo->prepare($sql . ' RETURNING id');
        $st->execute($campos);

        return (int)$st->fetchColumn();
    }
}
