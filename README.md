# Controle de Obrigações Martinelli: instalação em VPS

Sistema web para controlar, mês a mês, as obrigações de cada CNPJ cliente (DAS, XML de notas, despesas, ISS, ICMS, fechamento contábil, folha e demais), por regime tributário e **só com o que se aplica a cada empresa**. Nada depende do Claude: é PHP + MySQL/MariaDB rodando no seu servidor.

**Versão multi-escritório.** Cada escritório de contabilidade é uma “ilha”, identificada pelo **CNPJ**: clientes, equipe, obrigações, departamentos e feriados de um escritório nunca aparecem em outro. O login tem dois passos: (1) CNPJ do escritório; (2) e-mail e senha. Hoje você começa só com o escritório Martinelli + Luiz; quando alguém (inclusive o próprio Luiz, com a carteira só dele) quiser entrar, você cadastra o CNPJ novo pela tela **Escritórios** e ele já nasce com os mesmos departamentos, características e obrigações-padrão.

## O que vem no pacote

```
obrigacoes/
├── public/            ← única pasta aberta ao público (o servidor aponta para aqui)
│   ├── index.html     tela do sistema
│   ├── app.css        visual
│   ├── app.js         lógica da tela e cálculo dos vencimentos
│   ├── api.php        servidor (login, permissões, gravação)
│   └── .htaccess      só para Apache
├── app/
│   ├── bootstrap.php  funções internas (sessão, CNPJ, banco)
│   └── padrao.php     cadastro-padrão que todo escritório novo recebe
├── config/config.sample.php   modelo de configuração
├── database/schema.sql        cria as tabelas
├── bin/
│   ├── setup.php      primeira instalação, novo administrador, redefinir senha, clientes de exemplo (só no terminal)
│   └── backup.sh      backup diário do banco
└── deploy/            exemplos de configuração do Nginx e do Apache
```

## Papéis de acesso

| Papel | O que pode |
|---|---|
| **Administrador** | Tudo **dentro do seu escritório**: equipe, obrigações, departamentos, características, feriados, clientes e tarefas |
| **Contador** | Vê todas as carteiras, cadastra clientes e atualiza tarefas |
| **Colaborador** | Vê e atualiza **só** os clientes em que é o responsável |

Os papéis valem **por escritório**: a mesma pessoa (um e-mail, uma senha) pode ser administradora de um escritório e colaboradora de outro; ao entrar, ela informa o CNPJ do escritório e, se atender mais de um, troca pelo seletor no topo da tela.

Além disso existe o **dono da plataforma** (você, criado na instalação): só ele vê a aba **Escritórios**, para criar e ativar/desativar escritórios. Nessa aba aparecem apenas nome, CNPJ e contagens, nunca clientes ou tarefas de outro escritório.

As regras são aplicadas no servidor (`api.php`), não só na tela: toda consulta e toda gravação são filtradas pelo escritório da sessão. Cada mudança de situação fica registrada com quem fez e quando.

## Requisitos

- VPS com Ubuntu 22.04 ou 24.04 (ou Debian 12), 1 GB de RAM já basta
- Um endereço (por exemplo `obrigacoes.seudominio.com.br`) apontando para o IP da VPS
- PHP 8.1 ou superior, MariaDB 10.4+ ou MySQL 5.7+, Nginx (ou Apache)

## Instalação passo a passo (Ubuntu 24.04 + Nginx)

Os comandos com `sudo` são feitos no terminal da VPS, conectado por SSH.

### 1. Aponte o endereço para a VPS
No painel de DNS do seu domínio, crie um registro **A** para `obrigacoes` apontando para o IP da VPS. Pode levar alguns minutos para valer.

### 2. Instale os programas
```bash
sudo apt update
sudo apt install -y nginx mariadb-server php-fpm php-mysql php-mbstring unzip certbot python3-certbot-nginx ufw
```

### 3. Crie o banco e o usuário do banco
```bash
sudo mysql
```
Dentro do prompt do MariaDB (troque `SENHA_FORTE_AQUI` por uma senha longa, só sua):
```sql
CREATE DATABASE obrigacoes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'obrigacoes'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';
GRANT SELECT, INSERT, UPDATE, DELETE ON obrigacoes.* TO 'obrigacoes'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```
O usuário do sistema só tem permissão de ler e gravar dados, não de apagar tabelas.

### 4. Envie os arquivos para a VPS
No seu computador (troque `usuario` e `IP_DA_VPS`):
```bash
scp obrigacoes.zip usuario@IP_DA_VPS:/tmp/
```
Na VPS:
```bash
sudo mkdir -p /var/www/obrigacoes
sudo unzip /tmp/obrigacoes.zip -d /var/www/
sudo chown -R root:www-data /var/www/obrigacoes
sudo find /var/www/obrigacoes -type d -exec chmod 750 {} \;
sudo find /var/www/obrigacoes -type f -exec chmod 640 {} \;
sudo chmod +x /var/www/obrigacoes/bin/backup.sh
```
Se o zip criar a pasta com outro nome, ajuste o caminho para ficar em `/var/www/obrigacoes`.

### 5. Crie as tabelas
```bash
cd /var/www/obrigacoes
sudo mysql obrigacoes < database/schema.sql
```
⚠️ O `schema.sql` **apaga e recria** as tabelas. Rode só na instalação inicial, nunca depois que o sistema estiver em uso.

### 6. Configure a conexão
```bash
sudo cp config/config.sample.php config/config.php
sudo nano config/config.php          # coloque a senha do banco
sudo chown root:www-data config/config.php
sudo chmod 640 config/config.php
```
Mantenha `'cookie_seguro' => true` (o site vai abrir só por HTTPS, passo 8).

### 7. Configure o Nginx
```bash
sudo cp deploy/nginx.conf.sample /etc/nginx/sites-available/obrigacoes
sudo nano /etc/nginx/sites-available/obrigacoes     # troque o server_name pelo seu endereço
ls /run/php/                                         # confira o nome do socket (ex.: php8.3-fpm.sock)
sudo ln -s /etc/nginx/sites-available/obrigacoes /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```
Se o socket tiver outro nome, corrija a linha `fastcgi_pass` no arquivo.

### 8. Ative o HTTPS (obrigatório)
```bash
sudo certbot --nginx -d obrigacoes.seudominio.com.br
```
Aceite o redirecionamento de HTTP para HTTPS. O certificado renova sozinho. Sem HTTPS o login **não funciona** com `cookie_seguro` ligado, e as senhas viajariam sem proteção.

### 9. Firewall
```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

### 10. Crie o seu escritório e o seu usuário
```bash
cd /var/www/obrigacoes
sudo -u www-data php bin/setup.php
```
Informe o nome e o **CNPJ do escritório** (o programa confere os dígitos verificadores) e depois o seu nome, e-mail e senha. Isso cria o escritório com os departamentos, características e obrigações-padrão e torna você administrador e dono da plataforma. Abra `https://obrigacoes.seudominio.com.br`, digite o CNPJ, depois e-mail e senha.

Para testar com clientes fictícios (CNPJs inventados que começam com `99.000.`; apague depois pela tela Clientes):
```bash
sudo -u www-data php bin/setup.php --exemplos=CNPJ_DO_ESCRITORIO
```

### 11. Monte a equipe (no navegador, logado como administrador)
1. **Equipe → Novo usuário**: crie o contador, o Luiz e cada colaborador, com e-mail, papel e senha inicial. Peça para cada um trocar a senha em **Minha conta**.
2. **Clientes**: cadastre os CNPJs, com regime, responsável e as **características** da empresa (tem folha, emite NFS-e, emite NF-e, tem ISS, tem ICMS, recebe XML de compras…).
3. **Modelos e ajustes**: revise as obrigações e os prazos (veja “Revisão dos prazos” abaixo).
4. **Modelos e ajustes → Feriados locais**: cadastre os feriados do seu município e dos clientes.

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

**LGPD.** Se você hospeda a carteira de outro escritório, você passa a ser operador dos dados dele (o escritório é o controlador). Formalize com um contrato de prestação do serviço que trate de finalidade, segurança, backup e devolução/exclusão dos dados. Este texto é orientação geral, não parecer jurídico.

## Backup (faça no primeiro dia)
```bash
sudo nano /root/.obrigacoes.cnf
```
Conteúdo:
```
[client]
user=obrigacoes
password=SENHA_DO_BANCO
```
```bash
sudo chmod 600 /root/.obrigacoes.cnf
sudo /var/www/obrigacoes/bin/backup.sh                 # testa: deve criar um .sql.gz em /var/backups/obrigacoes
sudo crontab -e                                        # e acrescente a linha abaixo para rodar todo dia às 2h
0 2 * * * /var/www/obrigacoes/bin/backup.sh
```
Guarde também uma cópia **fora** da VPS (baixe de vez em quando com `scp`, ou ative o snapshot automático do provedor). Para restaurar: `gunzip -c arquivo.sql.gz | sudo mysql obrigacoes`.

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

## Atualizar o sistema no futuro
Troque os arquivos de `public/` e `app/` (nunca o `config/config.php`). Se alterar `app.js` ou `app.css`, mude o número em `?v=2` no `index.html` para o navegador baixar a versão nova. **Não rode** o `schema.sql` de novo.

## Problemas comuns

| Sintoma | O que verificar |
|---|---|
| Página em branco ou erro 502 | Nome do socket PHP no Nginx (`ls /run/php/`) e `systemctl status php8.3-fpm` |
| “Configuração ausente” | Falta criar `config/config.php` (passo 6) |
| “Erro interno” ao entrar | `sudo tail -n 50 /var/log/nginx/error.log` e a senha do banco no `config.php` |
| Entra e volta para o login | O site está abrindo por `http://` com `cookie_seguro` ligado: use o endereço `https://` |
| Esqueceu a senha | `sudo -u www-data php bin/setup.php --redefinir=email@dominio.com` |
| “CNPJ, e-mail ou senha incorretos” | Confira o CNPJ **do escritório** (não o do cliente), o e-mail e a senha; a mensagem é propositalmente igual para os três casos |
| Esqueceu de criar o administrador de um escritório | `sudo -u www-data php bin/setup.php --novo-admin=CNPJ_DO_ESCRITORIO` |
| “Muitas tentativas” | Aguarde 15 minutos (limite de tentativas errôneas por e-mail) |
| Vencimento diferente do esperado | Veja o tipo de prazo e o ajuste do modelo, e os feriados locais cadastrados |

## Segurança: resumo do que já está feito

- Senhas guardadas com hash (`password_hash`); nunca em texto puro.
- Todas as consultas ao banco usam parâmetros (sem injeção de SQL); toda saída na tela é escapada.
- Proteção contra requisições forjadas (token CSRF) e cookie de sessão `HttpOnly`, `Secure` e `SameSite=Strict`.
- Login em dois passos por CNPJ do escritório, com mensagem de erro única (não revela se o CNPJ ou o e-mail existem); limite de tentativas por e-mail e por IP; sessão expira após 8 horas sem uso.
- Isolamento entre escritórios: toda consulta e gravação leva o `escritorio_id` da sessão; ids de outro escritório são recusados ou ignorados.
- Papéis verificados no servidor a cada requisição; usuário bloqueado perde o acesso na hora.
- `config.php`, `app/`, `bin/` e `database/` ficam fora da pasta pública; só o `api.php` executa PHP.
- Cabeçalhos de segurança e política de conteúdo (CSP) no servidor.

Boas práticas que dependem de você: manter a VPS atualizada (`sudo apt update && sudo apt upgrade` de tempos em tempos), usar chave SSH em vez de senha, bloquear (não só excluir) quem sair da equipe, e conferir os backups.

## Limites desta versão
- Não envia alertas por e-mail ou WhatsApp (dá para acrescentar depois).
- O CNPJ (do escritório e dos clientes) é conferido pelos dígitos verificadores, no formato numérico atual e no alfanumérico (IN RFB nº 2.229/2024, emitido a partir de julho de 2026). Isso não prova que o CNPJ existe na Receita; apenas que está bem formado.
- A pessoa que atende mais de um escritório só altera nome, e-mail e senha pela própria tela Minha conta; o administrador de cada escritório controla apenas o papel e o acesso dela ali.
- Os clientes de exemplo têm CNPJs inventados (começam com `99.000.`), apenas para teste.
- Se um administrador informar o e-mail de alguém já cadastrado em outro escritório, o sistema vincula essa pessoa (e, com isso, revela que o e-mail existe na plataforma). Em um ambiente com muitos escritórios independentes, avalie exigir convite com aceite.
