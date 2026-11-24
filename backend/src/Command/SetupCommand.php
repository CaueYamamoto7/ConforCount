<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\CatalogoPadrao;
use App\Service\Cnpj;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use PDO;
use RuntimeException;
use Throwable;

final class SetupCommand extends Command
{
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->addOption('novo-admin', ['help' => 'CNPJ do escritório que receberá um administrador'])
            ->addOption('redefinir', ['help' => 'E-mail do usuário cuja senha será redefinida'])
            ->addOption('exemplos', ['help' => 'CNPJ do escritório que receberá clientes fictícios']);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        try {
            $pdo = ConnectionManager::get('default')->getDriver()->pdo();

            return $this->configurar($pdo, $args->getOptions(), $io);
        } catch (Throwable $erro) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $io->err($erro->getMessage());

            return static::CODE_ERROR;
        }
    }

    private function configurar(PDO $pdo, array $opcoes, ConsoleIo $io): int
    {
        $min = 8;
        if (isset($opcoes['redefinir'])) {
            $email = mb_strtolower(trim((string)$opcoes['redefinir']));
            $st = $pdo->prepare('SELECT id, nome FROM usuarios WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u) {
                throw new RuntimeException('Usuário não encontrado: ' . $email);
            }
            $io->out('Redefinindo a senha de ' . $u['nome']);
            $senha = $this->pedirSenha($min);
            $pdo->prepare('UPDATE usuarios SET senha_hash = ?, ativo = 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
            $io->out('Senha redefinida.');

            return static::CODE_SUCCESS;
        }

        if (isset($opcoes['exemplos'])) {
            $esc = $this->escritorioPorCnpj($pdo, (string)$opcoes['exemplos']);
            $st = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE escritorio_id = ?');
            $st->execute([$esc['id']]);
            if ((int)$st->fetchColumn() > 0) {
                throw new RuntimeException('Este escritório já tem clientes; os exemplos só entram em escritório vazio.');
            }
            $cars = [];
            $st = $pdo->prepare('SELECT id, nome FROM caracteristicas WHERE escritorio_id = ?');
            $st->execute([$esc['id']]);
            foreach ($st->fetchAll() as $r) {
                $cars[$r['nome']] = (int)$r['id'];
            }
            $pdo->beginTransaction();
            foreach (CatalogoPadrao::exemplosClientes() as $i => [$nome, $regime, $lista]) {
                $base = '990000' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '0001'; // CNPJs inventados (base 99...)
                $d1 = Cnpj::dv($base);
                $cnpj = $base . $d1 . Cnpj::dv($base . $d1);
                $cid = $this->inserirId($pdo, 'INSERT INTO clientes (escritorio_id, nome, cnpj, regime, ativo) VALUES (?, ?, ?, ?, 1)', [$esc['id'], $nome, $cnpj, $regime]);
                foreach ($lista as $nomeCar) {
                    if (isset($cars[$nomeCar])) {
                        $pdo->prepare('INSERT INTO cliente_caracteristicas (cliente_id, caracteristica_id) VALUES (?, ?)')->execute([$cid, $cars[$nomeCar]]);
                    }
                }
            }
            $pdo->commit();
            $io->out(count(CatalogoPadrao::exemplosClientes()) . ' clientes fictícios criados em "' . $esc['nome'] . '".');

            return static::CODE_SUCCESS;
        }

        if (isset($opcoes['novo-admin'])) {
            $esc = $this->escritorioPorCnpj($pdo, (string)$opcoes['novo-admin']);
            $io->out('Novo administrador para: ' . $esc['nome']);
            $nome = $this->perguntar('Nome: ', false, 'SETUP_NOME');
            $email = mb_strtolower($this->perguntar('E-mail: ', false, 'SETUP_EMAIL'));
            if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Nome ou e-mail inválido.');
            }
            $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
            $st->execute([$email]);
            $uid = (int)$st->fetchColumn();
            if ($uid === 0) {
                $senha = $this->pedirSenha($min);
                $uid = $this->inserirId($pdo, 'INSERT INTO usuarios (nome, email, senha_hash, ativo) VALUES (?, ?, ?, 1)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
            } else {
                $io->out('Este e-mail já existe: a pessoa será apenas vinculada ao escritório (a senha dela continua a mesma).');
            }
            $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, \'admin\', 1)
                           ON CONFLICT (usuario_id, escritorio_id) DO UPDATE SET papel = EXCLUDED.papel, ativo = 1')->execute([$uid, $esc['id']]);
            $io->out('Pronto.');

            return static::CODE_SUCCESS;
        }

        // primeira instalação
        if ((int)$pdo->query('SELECT COUNT(*) FROM escritorios')->fetchColumn() > 0) {
            throw new RuntimeException('A instalação já foi feita. Use --novo-admin=CNPJ, --redefinir=EMAIL ou crie escritórios pela tela "Escritórios".');
        }
        $io->out('Primeira instalação: cadastro do seu escritório e do seu usuário.');
        $nomeEsc = $this->perguntar('Nome do escritório: ', false, 'SETUP_ESCRITORIO');
        if ($nomeEsc === '') {
            throw new RuntimeException('Informe o nome do escritório.');
        }
        $cnpj = $this->pedirCnpj('CNPJ do escritório (é ele que identifica o escritório no login): ', 'SETUP_CNPJ');
        $nome = $this->perguntar('Seu nome: ', false, 'SETUP_NOME');
        $email = mb_strtolower($this->perguntar('Seu e-mail (será o seu login): ', false, 'SETUP_EMAIL'));
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Nome ou e-mail inválido.');
        }
        $senha = $this->pedirSenha($min);

        $pdo->beginTransaction();
        $pdo->query('SELECT pg_advisory_xact_lock(3, 2)');
        if ((int)$pdo->query('SELECT COUNT(*) FROM escritorios')->fetchColumn() > 0) {
            $pdo->rollBack();
            throw new RuntimeException('A instalação já foi feita.');
        }
        $eid = $this->inserirId($pdo, 'INSERT INTO escritorios (cnpj, nome, ativo) VALUES (?, ?, 1)', [$cnpj, $nomeEsc]);
        CatalogoPadrao::semearEscritorio($pdo, $eid);
        $uid = $this->inserirId($pdo, 'INSERT INTO usuarios (nome, email, senha_hash, ativo, dono_plataforma) VALUES (?, ?, ?, 1, 1)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
        $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, \'admin\', 1)')->execute([$uid, $eid]);
        $pdo->commit();
        $io->out('Pronto. Escritório "' . $nomeEsc . '" criado com os departamentos, características e obrigações-padrão. Entre com o CNPJ ' . Cnpj::formatar($cnpj) . ', seu e-mail e a senha.');

        return static::CODE_SUCCESS;
    }

    private function perguntar(string $rotulo, bool $oculto = false, ?string $env = null): string
    {
        $v = $env === null ? false : getenv($env);
        if ($v !== false && $v !== '') { // uso automatizado (testes)
            return trim($v);
        }
        fwrite(STDOUT, $rotulo);
        if ($oculto && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
            shell_exec('stty -echo 2>/dev/null');
            $v = fgets(STDIN);
            shell_exec('stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
        } else {
            $v = fgets(STDIN);
        }

        return trim((string)$v);
    }

    private function pedirSenha(int $min): string
    {
        $s1 = $this->perguntar('Senha (mínimo ' . $min . ' caracteres): ', true, 'SETUP_SENHA');
        if (mb_strlen($s1) < $min) {
            throw new RuntimeException('Senha curta demais.');
        }
        if (getenv('SETUP_SENHA') === false) {
            $s2 = $this->perguntar('Repita a senha: ', true);
            if ($s1 !== $s2) {
                throw new RuntimeException('As senhas não conferem.');
            }
        }

        return $s1;
    }

    private function pedirCnpj(string $rotulo, ?string $env = null): string
    {
        $c = Cnpj::normalizar($this->perguntar($rotulo, false, $env));
        if (!Cnpj::valido($c)) {
            throw new RuntimeException('CNPJ inválido. Confira os números e letras.');
        }

        return $c;
    }

    private function escritorioPorCnpj(PDO $pdo, string $valor): array
    {
        $c = Cnpj::normalizar($valor);
        $st = $pdo->prepare('SELECT id, nome, cnpj FROM escritorios WHERE cnpj = ?');
        $st->execute([$c]);
        $e = $st->fetch();
        if (!$e) {
            throw new RuntimeException('Não há escritório com o CNPJ ' . Cnpj::formatar($c) . '.');
        }

        return $e;
    }

    private function inserirId(PDO $pdo, string $sql, array $valores): int
    {
        $st = $pdo->prepare($sql . ' RETURNING id');
        $st->execute($valores);

        return (int)$st->fetchColumn();
    }
}
