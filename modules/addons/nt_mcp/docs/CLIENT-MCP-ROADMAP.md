# MCP e WebMCP do cliente — visão e próximos passos

Registro: 2026-09-05.

**Status: base sem tools implementada no código, desativada por padrão.** Chaves
por cliente, permissões por chave, endpoints do cliente e ferramentas permanecem
como intenção documentada para etapas futuras. Não houve publicação em produção.

## O que o usuário quer

- Evoluir a integração do cliente dentro do mesmo projeto `whmcs-mcp` e addon
  `nt_mcp` já existente.
- Começar com uma base vazia, sem nenhuma tool, e aprender gradualmente quais
  ferramentas fazem sentido para os clientes.
- Permitir, futuramente, que o próprio cliente crie uma chave/token seguro na
  área do cliente para usar com a inteligência artificial de sua escolha.
- Permitir ativar ou desativar funções individualmente **em cada chave**. Uma
  chave não deve representar autorização irrestrita para todas as funções.
- Usar essa autorização para controlar o acesso da IA a funções da área do
  cliente, incluindo a integração WebMCP desejada.
- Avaliar segurança e utilidade de cada ferramenta antes de implementá-la.

Nenhuma ferramenta foi escolhida para a primeira entrega. A consulta de planos
anteriormente sugerida foi adiada junto com as demais tools. Domínios continuam
fora do escopo por enquanto.

## Primeiro passo: base sem tools

A base implementada contempla:

- Hooks do próprio addon e um JavaScript específico para WebMCP no navegador.
- Controle independente de ativação no painel administrativo, desligado por
  padrão e usando a proteção CSRF existente.
- Carregamento inicial restrito à página pública de hospedagem, para visitantes
  deslogados. Essa restrição pertence à base inicial; o acesso autenticado será
  desenhado em uma etapa posterior.
- Detecção de `document.modelContext`, com saída normal quando não houver
  suporte e **zero ferramentas registradas**.
- Independência do endpoint e das credenciais do MCP administrativo.
- Validação do carregamento, da desativação e da ausência de tools, além dos
  testes pertinentes e do Opengrep direcionado quando houver alteração de código.

Os detalhes de ativação e validação estão em [WEBMCP.md](WEBMCP.md). A base não
antecipa a implementação das chaves ou das ferramentas descritas abaixo.

## Chaves e permissões: intenção registrada, desenho ainda aberto

A experiência desejada é o cliente criar uma chave na própria área do cliente,
escolher quais funções aquela chave permite e usá-la na sua IA. O painel deve
permitir revisar e alterar essa seleção por chave.

Ainda vamos decidir:

- Se a IA se conectará por MCP remoto sem o site aberto, por WebMCP no navegador,
  ou pelos dois canais. A preferência de canal não foi confirmada.
- Como uma chamada WebMCP será associada à autorização escolhida pelo cliente.
  Registrar uma tool na página não cria, por si só, um mecanismo de autenticação
  por chave. Não assumir que a chave precisa ser colocada no JavaScript.
- Como vincular a autorização ao usuário WHMCS e à conta de cliente correta,
  inclusive quando um usuário puder acessar mais de uma conta.
- O ciclo de vida das chaves: criação, visualização inicial, validade, revogação,
  substituição e identificação da IA que usa cada chave.
- Quais funções poderão ser autorizadas, quais dependerão de confirmação
  adicional e como mudanças de permissão afetarão conexões já abertas.

WebMCP no navegador e MCP remoto do cliente devem ser tratados como canais
distintos. Usar o mesmo addon não significa compartilhar o endpoint, as
credenciais ou os privilégios administrativos. A API de registro WebMCP está
descrita no [draft do W3C Community Group](https://webmachinelearning.github.io/webmcp/).

## Preparação para o desenho de segurança

Os pontos abaixo são diretrizes propostas para a próxima avaliação técnica,
não funcionalidades implementadas nem escolhas fechadas de protocolo:

- Identidade, conta e permissões precisam ser verificadas no servidor em cada
  operação que acessar recursos do cliente; ocultar uma tool no navegador não
  substitui autorização.
- A permissão de uma chave deve ficar limitada à interseção das capacidades
  liberadas pela NTweb, das permissões atuais do usuário na conta e da seleção
  feita para aquela chave.
- Novas funções devem nascer desabilitadas para chaves existentes. Uma mudança
  no catálogo não deve ampliar silenciosamente uma autorização anterior.
- Prever chaves revogáveis e armazenamento adequado ao mecanismo escolhido,
  evitando persistir tokens Bearer em texto puro ou registrar segredos em logs.
- Não publicar chaves no `llms.txt`, em HTML público, em assets ou na documentação.
- Prever auditoria por chave e função, com identificação suficiente para o
  cliente entender o uso, sem copiar conteúdo sensível desnecessariamente.
- Manter configurações e limites do acesso do cliente independentes dos gates
  e do catálogo do MCP administrativo.

## Evolução, uma ferramenta por vez

Antes de adicionar uma tool, registrar a necessidade real do cliente, quais
dados ela usa, quais efeitos produz, a quem pertencem os recursos envolvidos e
qual permissão será exigida. Definir também os cenários de teste e como retirar
essa capacidade caso necessário.

A ordem pretendida é: base vazia; desenho das autorizações e chaves; avaliação
individual das ferramentas; implementação e validação gradual das escolhidas.
Isso não autoriza implementar agora nenhuma das etapas futuras.

O `llms.txt` será atualizado quando existir uma capacidade pública utilizável.
Até lá, esta visão permanece na documentação técnica do addon.
