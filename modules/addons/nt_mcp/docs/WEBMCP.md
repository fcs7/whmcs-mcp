# WebMCP — base do navegador, sem tools

Esta etapa adiciona somente a infraestrutura de carregamento no mesmo addon
`nt_mcp`. A integração registra **zero ferramentas**: não há catálogo, consulta de
preços, dados de clientes, gestão de chaves nem endpoint MCP do cliente.

Requisitos desta base: **WHMCS 8.0+ e PHP 8.1+**, inclusive nos handlers de
CLI/cron da instalação. A classe `CurrentUser` foi introduzida no WHMCS 8.0:
[autenticação no WHMCS](https://developers.whmcs.com/advanced/authentication/).
Isso é específico do WebMCP; não amplia a compatibilidade do servidor MCP.

## Ativação

No WHMCS, abrir **Addons > NT MCP Server > WebMCP — Base do navegador**,
marcar **Ativar base WebMCP** e salvar. O formulário usa a sessão administrativa e
o CSRF do addon. O estado é persistido em `nt_mcp_webmcp_enabled`, separadamente dos
gates administrativos, e a alteração é registrada no Activity Log.

A chave ausente significa **desativado**. Valores canônicos `1`/`0` representam
ligado/desligado; valores não reconhecidos mantêm a integração desativada.
Falhas de leitura ou de resolução da autenticação não produzem saída pública;
geram diagnóstico sem conteúdo da exceção quando o logger está disponível.

O `hooks.php` do addon usa `ClientAreaFooterOutput`. Para emitir o script, exige:

- tema `ntweb-2026-theme`;
- marcador de landing `_nt_landing_slug` igual a `hospedagem`, definido pelo
  entry point do tema antes de `initPage()`;
- HTTPS (`servedOverSsl` do WHMCS);
- ausência de usuário, administrador e conta autenticados nesta requisição,
  verificada por `WHMCS\Authentication\CurrentUser`;
- integração explicitamente ativada.

Um usuário WHMCS 8 sem conta selecionada ainda está autenticado e não recebe o
script. Essa limitação é própria da etapa inicial; não define as permissões das
futuras ferramentas autenticadas.

O cookie administrativo da instalação é restrito a `/admin/`, conforme
`CLAUDE.md`. Um administrador autenticado apenas nesse caminho pode aparecer
como visitante na landing: o hook só conhece a autenticação enviada na
requisição atual. A exclusão de administrador cobre a sessão que o WHMCS
efetivamente disponibiliza ali, inclusive o acesso como cliente.

O asset é `assets/webmcp.js`, carregado com `defer` e versão na URL. Não é preciso
editar templates Smarty: o tema já imprime `footeroutput`. Com o addon ativo,
o WHMCS detecta `hooks.php` na pasta do módulo, conforme sua
[documentação de hooks de addons](https://developers.whmcs.com/addon-modules/hooks/).
Validar esse carregamento no desenvolvimento depois de enviar os arquivos.

O hook copia esse marcador para seu contexto local de validação; não depende da
ordem de preenchimento das variáveis Smarty por `ClientAreaPage` e não lê a rota
de parâmetros enviados pelo visitante.

`servedOverSsl` permanece uma verificação estrita de booleano, como define a
[documentação do hook](https://developers.whmcs.com/hooks-reference/output/#clientareafooteroutput).
Se o proxy fizer o WHMCS considerar HTTP uma página HTTPS, corrigir a configuração
do proxy/WHMCS; não confiar diretamente em headers do visitante para liberar a base.

## Carregamento e diagnóstico no servidor

O registro do hook não carrega classes do addon. Os dois `require_once` ficam
no callback do footer, depois da verificação de arquivos legíveis. PHP anterior
ao 8.1 encerra apenas este hook antes de carregar o enum; arquivos ausentes,
ilegíveis ou com erros capturáveis de carregamento mantêm o footer vazio.
Isso reduz o impacto de um upload incompleto, mas não torna o deploy atômico.
Enviar `src/` e `assets/` antes de `hooks.php` e conferir o PHP do site e do cron.

Falhas ao consultar configuração ou autenticação usam a fronteira de logs
existente, `Diagnostics::logWithFingerprint`, com contexto fixo
`webmcp_footer_unavailable`, categoria `runtime_failure` e apenas o tipo da
exceção higienizado. O fingerprint é omitido, sem ler a mensagem ou consultar
novamente a configuração. `Diagnostics.php` é carregado diretamente apenas no
caminho de falha; não há autoloader, SDK nem logger público paralelo.

Mensagem, argumentos, stack trace, caminhos de classes anônimas e dados da
sessão não são registrados. Configuração desligada e páginas inelegíveis não
geram logs em uma instalação íntegra. PHP incompatível, upload incompleto ou
logger indisponível mantêm a saída vazia sem log adicional: conferir arquivos e
handlers faz parte da validação de implantação. A disponibilidade do log depende
do handler PHP; os gotchas do Plesk estão no `CLAUDE.md`.

## Diagnóstico no navegador

O script só executa no documento principal. Ele verifica contexto seguro,
`document.modelContext` e a presença de `registerTool`, **sem chamar a API**.
Não usa o alias legado em `navigator` nem adiciona polyfill.

No console da página elegível, consultar:

```js
window.NTWebMCP
```

O resultado contém `version: '1.0.0'`, `toolCount: 0` e um destes estados:

- `ready`: a API esperada está presente; isso não comprova disponibilidade de um
  agente nem registra qualquer ferramenta.
- `unsupported`: a API não está disponível ou seu acesso foi recusado.

Sem carregamento do script, `window.NTWebMCP` permanece indefinido. Inclusões
repetidas preservam o mesmo objeto de diagnóstico. Ele não contém credenciais,
identificadores de conta nem dados da sessão.

O asset usa sintaxe ES2020. Navegadores anteriores podem registrar um erro de
sintaxe e ignorar apenas esse script; a página não depende dele para funcionar.

Esta etapa não configura origin trial. Para testar a API experimental no Chrome,
usar uma versão que ofereça a flag `chrome://flags/#enable-webmcp-testing` e
reiniciar o navegador após alterá-la. Um navegador sem suporte deve continuar
funcionando normalmente. Referência: [WebMCP no Chrome](https://developer.chrome.com/docs/ai/webmcp).

## Separação e reversão

O callback público carrega `ConfigFlag` e `ClientBootstrap`, e `Diagnostics`
somente em caso de falha de configuração/autenticação. Ele não importa o
autoloader Composer, o bootstrap do servidor, `mcp.php` ou `nt_mcp.php`.
O JavaScript não faz requisições, discovery, registros ou execuções de tools.
Não há telemetria ou alteração no `llms.txt` nesta etapa.

Para reverter, desmarcar a opção e salvar; recarregar as páginas abertas para
remover a base daquele documento. Não é necessário desativar o addon ou mudar
as credenciais do conector administrativo.

Desativar o addon no WHMCS não apaga `nt_mcp_webmcp_enabled`. Ao reativá-lo, a
preferência anterior volta a valer, assim como os demais gates persistidos.
Para mantê-lo desligado após reativação, salvar a opção WebMCP desmarcada.

## Validação

Na pasta `modules/addons/nt_mcp`, os testes da alteração podem ser executados com:

```sh
php vendor/bin/phpunit tests/WebMcp tests/Admin
node --test tests/WebMcp/bootstrap.test.cjs
```

Os testes PHP cobrem configuração, isolamento por página, identidade, falhas de
autenticação/configuração, URLs do asset e o formulário administrativo. Os testes
JavaScript simulam API presente, ausente e recusada, verificando ausência de
chamadas, comportamento em frames e inclusão repetida.

`HookTest` registra o hook real com um stub isolado de `add_hook` e verifica a
leitura tardia do marcador global, a ausência de imports no registro e os casos
de upload incompleto. Execute PHPUnit com usuário sem privilégios de root para
que os testes de permissão de arquivo sejam efetivos.

O teste opcional de Chromium agora está no repositório. Exige PHP 8.1+, Node 20+
e `playwright-core` disponível no ambiente de testes, além de Chromium com a
feature experimental `WebMCP` (validado com a versão 152). Exemplo:

```sh
WEBMCP_PLAYWRIGHT_MODULE=/caminho/node_modules/playwright-core \
WEBMCP_CHROMIUM_BIN=/caminho/chromium \
node tests/WebMcp/browser-smoke.cjs
```

O runner inicia um servidor PHP apenas em `127.0.0.1`, encerra servidor e navegador
ao terminar e imprime um relatório JSON dos 24 cenários. Usa os arquivos reais
do hook e do asset, mas simula o WHMCS, sua autenticação e `servedOverSsl`.
Não valida Apache/nginx, TLS do servidor nem o core WHMCS.

Para repetir a revisão assistida, com o CLI oficial CodeRabbit autenticado,
executar na raiz do repositório:

```sh
coderabbit review --agent --uncommitted --include-untracked
```

Essa revisão usa um serviço externo e pode produzir avaliações diferentes;
seus achados exigem triagem e não substituem os testes.

Validação local em **2026-09-05**:

- Suíte completa em PHP 8.3: 1.548 casos, 4.858 assertions, nenhuma falha e um
  teste de concorrência ignorado por ausência de `pcntl` no container. Esse teste
  passou separadamente no PHP 8.5 com `pcntl` e o bootstrap WHMCS simulado
  (1 teste, 12 assertions).
- JavaScript: 5 testes. Os casos novos de hook e diagnóstico estão incluídos na
  suíte PHP acima. Todos os arquivos PHP alterados passaram na verificação de
  sintaxe em PHP 8.1.
- Chromium 152: 24 cenários com a API nativa ligada e desligada, usando os
  arquivos reais do addon e um ambiente WHMCS simulado. A API ligada retornou
  zero tools; os cenários excluídos não receberam o script.
- Opengrep direcionado, excluindo dependências e artefatos: nenhum achado
  relevante na alteração. Os sete arquivos de produção passaram sem erros de
  parsing. A rodada ampliada incluiu explicitamente os onze arquivos PHP/JS de
  testes/fakes/fixtures, sem depender do ignore padrão de `tests/`. Alertas sobre
  o fetch local, a emissão do footer na fixture e os evals preexistentes dos
  stubs foram triados no contexto dos testes. O scan customizado teve parsing
  parcial em `FakeCapsule.php` por sintaxe preexistente; não representa cobertura
  integral desse arquivo. O re-scan dos quatro arquivos afetados pelo ajuste do
  logger terminou sem achados nem erros de parsing.
- Revisão CodeRabbit inicial: a sugestão de reverter a configuração caso a auditoria
  falhe foi descartada após verificar o contrato existente de `ActivityLog`,
  que trata falhas do destino de auditoria internamente. Dois testes comprovam
  que ativar/desativar continua salvo, com mensagem correta e diagnóstico sem
  exposição do conteúdo da exceção.
- Na rodada posterior, CodeRabbit sugeriu esperar novos imports em `HookTest`.
  O apontamento não se aplica: a comparação ocorre na segunda chamada, depois
  que a primeira já carregou as classes. O teste passou na suíte completa.

A sugestão de escrever `error_log` diretamente foi adaptada ao contrato de
`DiagnosticBoundaryTest`: `Diagnostics` continua sendo o único escritor de logs
do addon. Essa proteção existente permaneceu intacta.

Antes de uma implantação, validar também no WHMCS de desenvolvimento: ativação e
desativação, página anônima e autenticada, outras landings, carregamento do asset
sob as regras do servidor web e ausência de ferramentas registradas pela base.
Conferir o evento `MCP ADMIN WEBMCP CONFIG CHANGED` no Activity Log, resposta HTTP
200 do `.js`, `window.NTWebMCP`, configuração de proxy/HTTPS e execução normal do
cron com PHP 8.1+. Caso o painel permaneça antigo após upload, verificar o opcache.
Os testes locais não substituem essa validação. Esta entrega não foi implantada.

A evolução por ferramentas e autorizações está no
[roadmap do cliente](CLIENT-MCP-ROADMAP.md).
