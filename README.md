# Controle de Obrigações — Conforcont

Sistema multi-escritório para acompanhar obrigações mensais por cliente, regime tributário e características da empresa. A interface HTML/CSS/JS tem um HTML e um script por página. A API usa PHP 8.5, CakePHP 5.4 e Neon PostgreSQL. FrankenPHP/Caddy serve a interface e a API na mesma origem na Vercel.

## Papéis de acesso

| Papel | O que pode |
|---|---|
| **Administrador** | Tudo **dentro do seu escritório**: equipe, obrigações, departamentos, características, feriados, clientes e tarefas |
| **Contador** | Vê todas as carteiras, cadastra clientes e atualiza tarefas |
| **Colaborador** | Vê e atualiza **só** os clientes em que é o responsável |

Os papéis valem **por escritório**: a mesma pessoa (um e-mail, uma senha) pode ser administradora de um escritório e colaboradora de outro; ao entrar, ela informa o CNPJ do escritório e, se atender mais de um, troca pelo seletor no topo da tela.

Além disso existe o **dono da plataforma** (você, criado na instalação): só ele vê a aba **Escritórios**, para criar e ativar/desativar escritórios. Nessa aba aparecem apenas nome, CNPJ e contagens, nunca clientes ou tarefas de outro escritório.

A API CakePHP aplica as regras de acesso: toda consulta e toda gravação são filtradas pelo escritório da sessão. Cada mudança de situação fica registrada com quem fez e quando.

## Estrutura

- Os HTMLs na raiz representam as telas. Por exemplo, `clientes.html` abre em `/clientes` e carrega `assets/js/pages/clientes.js`.
- `assets/js/domain/` contém o calendário, as regras de aplicabilidade e a validação de CNPJ. `assets/js/components/` contém os controles compartilhados.
- `backend/` é a aplicação CakePHP. As rotas JSON estão em `config/routes.php` e os controllers chamam serviços do domínio. Os modelos do ORM definem as tabelas, chaves e relações.
- `router.php` serve a interface e a API na mesma origem durante o desenvolvimento. Em produção, `Caddyfile` e `Dockerfile.vercel` cumprem essa função.
- O pipeline de CI verifica as regras de domínio, a integração da API com PostgreSQL e o build do contêiner.

## Desenvolvimento local

Use PHP 8.5, Composer 2, as extensões `pdo_pgsql`, `mbstring`, `intl` e `openssl`, e PostgreSQL com suporte a ICU. Crie um banco vazio e exporte as variáveis no terminal. O arquivo `.env.example` serve como referência.

```powershell
$env:DATABASE_URL = 'postgres://usuario:senha@127.0.0.1:5432/obrigacoes?sslmode=disable'
composer install --working-dir=backend
php backend/bin/cake.php migrations migrate --connection direct --no-lock
php backend/bin/cake.php setup
php -S 127.0.0.1:8080 router.php
```

Abra http://127.0.0.1:8080 e informe o CNPJ do escritório, o e-mail e a senha criados no setup. O setup aceita os comandos abaixo, executados em uma estação administrativa com acesso ao banco.

- `php backend/bin/cake.php setup --novo-admin=CNPJ` cria ou vincula um administrador.
- `php backend/bin/cake.php setup --redefinir=EMAIL` solicita uma nova senha.
- `php backend/bin/cake.php setup --exemplos=CNPJ` cria clientes fictícios em um escritório sem clientes.

### Teste de integração

Inicialize um banco descartável com `php backend/bin/cake.php migrations migrate --connection direct --no-lock` e `php backend/bin/cake.php setup`. Use os valores fictícios de `SETUP_CNPJ`, `SETUP_EMAIL` e `SETUP_SENHA` no ambiente que vai executar o teste. Inicie dois processos com `php -S 127.0.0.1:PORTA router.php`, ambos conectados ao mesmo banco. Aponte `TEST_API_URL` e `TEST_API_URL_SECONDARY` para essas origens e rode `node tests/postgres-api.mjs` com Node.js 18 ou posterior. O teste cria registros e requer uma base recém-inicializada a cada execução.

## Vercel com Neon

1. Crie uma base Neon própria para o Conforcont e uma base ou branch isolada para Preview. Escolha uma localização próxima à região de execução configurada na Vercel, `gru1` (São Paulo), conforme disponibilidade no projeto.
2. Configure `DATABASE_URL` na Vercel com a URL TLS do endpoint com pool do Neon, usando valores separados para Preview e produção. Configure `SECURITY_SALT` com uma chave aleatória de pelo menos 32 caracteres e `APP_FULL_BASE_URL` com o endereço HTTPS do ambiente. Os hosts de Preview fornecidos em `VERCEL_URL` e `VERCEL_BRANCH_URL` também são aceitos. O código aceita `sslmode=require`, `verify-ca` e `verify-full`. A role da aplicação precisa de acesso ao schema, operações de leitura e gravação nas tabelas da aplicação e uso das sequências de identidade.
3. No terminal administrativo, exporte `DATABASE_URL_DIRECT` com a conexão direta e a role proprietária das tabelas. Execute `php backend/bin/cake.php migrations migrate --connection direct --no-lock` antes do deploy. O comando registra as migrations aplicadas e aceita reexecução. Em uma base PostgreSQL da versão anterior, as migrations conferem o registro prévio e as colunas do esquema antes de adotá-lo. Faça backup e confirme a adoção primeiro em uma cópia. Em banco novo, execute também `php backend/bin/cake.php setup`, usando a conexão da aplicação em `DATABASE_URL`. Para dados legados, siga a seção de importação antes de criar qualquer escritório no destino.
4. Importe o repositório na Vercel indicando a raiz do projeto. O `Dockerfile.vercel` ativa o deploy em contêiner. O Caddy escuta em `$PORT`, serve os HTMLs e assets e encaminha `/api/*` a `backend/webroot/index.php`. As sessões são compartilhadas no PostgreSQL e expiram após oito horas de inatividade.
5. Valide o Preview com dados fictícios. Confira se o login persiste após reinícios, se o ambiente expõe `VERCEL=1` e se o cabeçalho `X-Vercel-Forwarded-For` fornece o IP usado pelo limitador. Confira também se as respostas autenticadas incluem `Cache-Control: no-store`. Compare os fluxos por papel e por escritório com a versão atual antes de promover o deploy.

Para reproduzir o build, use `docker build -f Dockerfile.vercel -t conforcont .`. Para testar a imagem de produção com navegador, use HTTPS e um banco de homologação, pois o cookie da imagem de produção exige HTTPS.

## Transição de MySQL/MariaDB para Neon

1. Faça um backup da base legada e restaure uma cópia para ensaio. Use uma base PostgreSQL vazia e rode as migrations.
2. Em uma estação administrativa com `pdo_mysql` e `pdo_pgsql`, configure `MYSQL_SOURCE_DSN` (por exemplo, `mysql:host=127.0.0.1;dbname=obrigacoes;charset=utf8mb4`), `MYSQL_SOURCE_USER`, `MYSQL_SOURCE_PASSWORD` e `DATABASE_URL_DIRECT`. Execute `php backend/bin/cake.php importar_mysql`.
3. O importador copia as 13 tabelas em uma transação, preserva IDs e hashes de senha, confere contagens e ajusta a próxima identificação de cada tabela. Confira os vínculos, os históricos e o login das contas migradas na homologação. A troca exige novo login.
4. Na virada, pause gravações na origem, faça um backup final, importe para uma base Neon vazia e repita as verificações antes de liberar o acesso. Preserve a origem e aponte de volta para ela se a validação falhar antes da liberação. Se a nova base já tiver recebido alterações, pause os acessos e preserve o estado do Neon para reconciliar essas gravações antes de retomar a origem.

## Backup e restauração

Execute `bash bin/backup.sh` em um ambiente administrativo com `pg_dump` e um serviço libpq configurado para a conexão direta. O serviço padrão é `conforcont` e pode ser ajustado por `PGSERVICE`. Defina `BACKUP_DIR` para o destino dos arquivos. O backup inclui o schema e os dados de negócio. A tabela de sessões é restaurada vazia.

Para ensaiar a restauração, aponte um **serviço separado para uma base vazia** e execute:

```bash
pg_restore --no-owner --no-privileges --single-transaction --exit-on-error -d 'service=conforcont_restore' caminho-do-backup.dump
```

Confira contagens, relacionamentos e login após a restauração. Agende o backup fora do contêiner e aplique a retenção no armazenamento de backups.

## Como cada empresa recebe só as obrigações dela

Cada obrigação (modelo) diz **para quais regimes** vale, **em quais meses** vence e, opcionalmente, **qual característica** o cliente precisa ter. A empresa recebe a tarefa quando o regime bate **e** ela tem a característica exigida:

| Obrigação (exemplos do cadastro-padrão) | Regimes | Só para quem tem |
|---|---|---|
| Receber XML das notas de saída (NF-e) | Simples, Presumido, Real | Emite NF-e (produtos) |
| Conferir NFS-e emitidas | Simples, Presumido, Real | Emite NFS-e (serviços) |
| Receber XML das compras (entrada) | Simples, Presumido, Real | Compras com XML de entrada |
| Receber despesas e comprovantes | Simples, Presumido, Real | (todos do regime) |
| ISS (guia do município) | Presumido, Real | Tem ISS a recolher |
| ICMS (guia do estado) e EFD ICMS/IPI | Presumido, Real | Tem ICMS |
| Fechar a folha, eSocial, FGTS Digital, DCTFWeb | todos | Tem funcionários (folha) |

Quem não tem ISS simplesmente não vê a tarefa de ISS; se começar a ter, basta marcar a característica no cliente.

**Posso alterar sempre? Sim.** Tudo isso é cadastro, não programa:
- **Obrigações:** criar, editar, desligar, mudar prazo, regime, meses e a característica exigida (Modelos e ajustes). A mudança vale para todos os clientes do escritório. O campo **“Aplica a partir de”** evita mexer em meses passados.
- **Departamentos e características:** criar os seus (por exemplo Societário, “Tem retenção de INSS”). Só dá para excluir um item que nenhuma obrigação use.
- **Exceção por cliente:** no cadastro do cliente, “Exceções” permite **Sempre incluir** ou **Nunca incluir** uma obrigação só para aquela empresa, ignorando regime e características.
- **Entrada e saída de cliente:** os campos “Atende desde” e “Até” fazem o cliente gerar tarefas só nos meses em que foi atendido.

## Adicionar outro escritório (outra “ilha”)

1. Entre como dono da plataforma e abra **Escritórios → Novo escritório**.
2. Informe o nome, o CNPJ (numérico ou alfanumérico) e o primeiro administrador. Se o e-mail dele já existir em outro escritório (caso do Luiz), a pessoa é apenas vinculada e continua com a mesma senha; senão, informe uma senha inicial.
3. O escritório já nasce com os departamentos, características e obrigações-padrão de `app/padrao.php`. Para mudar o que **os próximos** escritórios recebem, edite esse arquivo; os já criados não são alterados.
4. Para dar acesso ao Luiz nos dois, basta vinculá-lo (pela tela Equipe de cada escritório); ele passa a ter o seletor no topo.

## Revisão dos prazos (importante)

Os modelos vêm com prazos de partida, que **você precisa conferir** com o seu calendário:

- O cálculo considera feriados nacionais, Carnaval (segunda e terça) e Corpus Christi como dias sem expediente. Feriados estaduais e municipais vêm da lista de feriados locais.
- Cada modelo escolhe se o dia fixo **prorroga** (vai para o próximo dia útil) ou **antecipa** (vai para o dia útil anterior). O DAS está como prorroga; PIS/COFINS, FGTS Digital e DCTFWeb estão como antecipa. Confirme esse comportamento para cada tributo.
- ISS, ICMS e guias locais variam por município e estado; o dia 10 é só um espaço reservado.
- Os prazos de DEFIS (31/03), DASN-SIMEI (31/05), ECD (maio) e ECF (julho) seguem o calendário comum, mas podem ser prorrogados por ato da Receita em algum ano. Ajuste no modelo quando isso acontecer.

## Como o sistema pensa

- A tela do mês mostra as obrigações que **vencem naquele mês** (a competência de referência é o mês anterior).
- As tarefas são geradas na hora a partir de **Modelos × Clientes**; só ficam gravadas a situação, a observação e o histórico. Se você mudar um modelo, o mês aparece recalculado, inclusive os meses passados.
- “Em atraso” é calculado: tarefa ainda aberta (pendente, em andamento ou aguardando cliente) com vencimento anterior a hoje.
- A tela se atualiza sozinha a cada minuto, para ver o que os colegas marcaram.
