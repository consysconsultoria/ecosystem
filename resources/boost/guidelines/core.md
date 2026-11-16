# Regras de Desenvolvimento Consys (Laravel)

Regras gerais para todos os projetos Laravel da Consys. Regras específicas do projeto (`.ai/rules`, `.ai/guidelines`, CLAUDE.md/AGENTS.md locais) têm prioridade quando forem mais detalhadas ou conflitarem com estas.

## Stack

Projetos novos nascem com esta stack; projetos existentes seguem as versões do próprio `composer.json`/`package.json` (confira antes de escrever código e use a API daquela versão, ex.: Inertia v2 vs v3).

| Camada | Padrão |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13 |
| Banco | PostgreSQL (também nos testes) |
| Frontend | Inertia.js v3 + Vue 3, JavaScript puro |
| Estilo | Tailwind CSS 4 via `@tailwindcss/vite` |
| Build | Vite + `laravel-vite-plugin` |
| Rotas no JS | Ziggy (`route()`) |
| Ícones | lucide (`@lucide/vue`) |
| Máscaras | maska |
| Gráficos | ApexCharts (`vue3-apexcharts`) |
| Fila, cache e sessão | driver `database`; Horizon só quando o projeto usa Redis |
| Testes | Pest |
| Formatação | Pint |
| Dev/IA | Laravel Boost, Pail, Tinker |

- Não adicione, remova ou atualize dependências e não crie pastas base novas sem aprovação. Antes de instalar um pacote, verifique se o que já existe resolve.
- Fora da stack: TypeScript, Options API, Vuex/Pinia (estado vem das props do Inertia), axios (use os helpers do Inertia), jQuery, Bootstrap, SCSS, `tailwind.config.js`, Livewire, Blade para telas (Blade só no `app.blade.php`, e-mails e PDFs).

### Laravel 13

- Estrutura enxuta: middleware, exceções e rotas registradas em `bootstrap/app.php`; providers em `bootstrap/providers.php`; sem `app/Http/Kernel.php` nem `app/Console/Kernel.php`. Comandos em `app/Console/Commands` são registrados sozinhos e agendamentos ficam em `routes/console.php`.
- Casts no método `casts()` do model, não na propriedade `$casts`.
- Tarefas demoradas (e-mail, integrações, relatórios, PDF) vão para jobs em fila (`ShouldQueue`).
- Autorização com policies/gates; autenticação com os recursos nativos do Laravel (ou Passport onde já existe).

### PostgreSQL

- `DB_CONNECTION=pgsql` em todos os ambientes, incluindo `phpunit.xml` (banco `<projeto>_testing`). Não use SQLite para testes: ele não reproduz o comportamento do Postgres (DDL em transação, tipos, ordenação, `ilike`).
- Busca textual sem diferenciar maiúsculas: `whereLike()` (case-insensitive por padrão) ou `ilike`; nunca `LOWER()` manual espalhado.
- Use os tipos certos: `decimal` para dinheiro (nunca `float`), `jsonb` via `json()`, `boolean`, `date`/`timestamp`. Colunas `time` voltam como `HH:MM:SS`.
- `CREATE/DROP DATABASE` não roda dentro de transação.
- Toda FK com `constrained()`; defina `cascadeOnDelete()`/`restrictOnDelete()`/`nullOnDelete()` de forma intencional.
- Índices para colunas usadas em filtro, ordenação e buscas frequentes.

### Inertia v3

- Páginas renderizadas com `Inertia::render('Module/Page', [...])`, em `resources/js/Pages`.
- Formulários com `useForm` (`form.post/put/delete`, `form.errors`, `form.processing`); navegação com `router` e `<Link>`; requisições que não trocam de página (busca de ComboBox etc.) com `useHttp`.
- Feedback ao usuário via flash (`Inertia::flash('toast', [...])` ou flash de sessão) renderizado pelo componente de toasts compartilhado.
- Use props deferidas, `Inertia::optional()`/lazy e `only` em reloads parciais para dados pesados, em vez de carregar tudo na primeira visita.
- Dados compartilhados (usuário, flash, permissões) só via `HandleInertiaRequests::share()`.
- Com SSR ativo, nada de `window`/`document` no setup e nada de `<Teleport>` direto: use o portal do projeto que só monta no cliente.

### Vue 3

- Sempre `<script setup>` com Composition API e raiz única no template.
- Props declaradas com `defineProps` (com tipos/defaults), eventos com `defineEmits`, `v-model` em componentes com `defineModel`.
- `computed` para valores derivados; `watch` só para efeitos colaterais.
- Lógica reutilizável em composables (`useXxx`) dentro de `Composables/` ou `Support/`.
- Sem manipulação direta de DOM; use `ref` de template quando inevitável.

### Tailwind 4

- Configuração CSS-first: um único `resources/css/app.css` com `@import "tailwindcss"`, tokens no `@theme` e dark mode via `@custom-variant dark`.
- Utilitários atuais do v4: `shrink-*`/`grow-*`, `bg-black/50` (não `bg-opacity-*`), `size-*` para largura+altura iguais, `gap-*` em vez de margens entre itens de flex/grid.
- Layouts com flex/grid; grid responsivo com `grid-cols-12` e `col-span-*` por breakpoint, mobile first.
- Fonte Plus Jakarta Sans carregada pelo Vite.

## Antes de Editar

- Se existir `.ai/rules/index.md`, abra-o, leia toda regra cujo glob cubra os arquivos em questão e rode `grep -rin '<palavra>' .ai/rules` antes de planejar ou editar.
- Olhe arquivos irmãos e siga a estrutura, nomes e abordagem já usados. Reutilize componentes, enums, helpers e classes CSS existentes antes de criar algo novo; prefira estender (nova prop/variante/slot) a copiar markup.
- Use a ferramenta de documentação do Laravel Boost (quando disponível) para consultar a doc da versão instalada.

## Idioma

- Todo código é em inglês: classes, métodos, variáveis, props, rotas (nomes), arquivos, tabelas, colunas, valores de enum, chaves de config, mensagens de log e descrições de testes.
- Só o que o usuário vê é em pt-BR: telas, botões, labels, placeholders, `label()` de enums, flash/toasts, validação, e-mails, notificações, páginas de erro.
- Termos fiscais brasileiros sem tradução mantêm o nome (`inscricao_estadual`, `ncm`, `cnpj`).

## Comentários

- Nunca deixe comentários no código: inline, bloco, `<!-- -->` em templates Vue/Blade.
- PHPDoc/JSDoc é permitido para tipos (`@param`, `@return`, `@var`, array shapes) e descrições curtas em inglês.
- Se algo precisa de explicação, renomeie ou extraia um método com nome descritivo.

## Nomenclatura

- camelCase para variáveis, parâmetros, métodos, propriedades e identificadores JS/Vue.
- PascalCase para classes, componentes Vue e cases de enum (`Monthly`, `InProgress`).
- snake_case para tabelas, colunas e chaves de dados trocadas com o backend (props Inertia, campos de form: `useForm({ customer_id: null })`).
- Convenções do Laravel: tabelas no plural, FK `<model>_id`, pivô singular em ordem alfabética (`tenant_user`), `created_at`/`updated_at`/`deleted_at`. Não declare `$table` quando o nome inferido serve.
- Nomes descritivos: `isRegisteredForDiscounts`, não `discount()`.

## PHP

- Sempre chaves em estruturas de controle, mesmo com uma linha.
- Constructor property promotion; sem `__construct()` vazio.
- Tipos explícitos em todos os parâmetros e retornos.
- Rode `vendor/bin/pint --dirty` antes de finalizar.

## Backend

- Organize classes por módulo: `Http/Controllers/Tickets/TicketController`, `Http/Requests/Admin/StoreTenantRequest`, idem para jobs, policies, resources.
- Validação sempre em Form Requests, nunca inline no controller.
- Eloquent e relacionamentos antes de `DB::`; use `Model::query()`; evite N+1 com eager loading.
- Controllers Inertia mapeiam os dados explicitamente para a página (`->through(fn (Model $m): array => [...])`), sem expor o model inteiro.
- Enums string-backed com `label()` em pt-BR e `options()` (`[{value, label}]`) para selects.
- Lógica de negócio reutilizável vai em Actions/Services conforme o padrão do projeto, não no controller.
- Use `config()` no código, nunca `env()` fora de `config/`.
- Gere arquivos com `php artisan make:* --no-interaction` (models com factory e seeder quando fizer sentido).

## Rotas

- URIs em pt-BR, nomes em inglês seguindo resources do Laravel: `/clientes` → `customers.index`, `/contas-a-receber` → `receivables.index`. Nomes compostos em snake_case.
- Parâmetros em inglês (camelCase do model) para o implicit binding: `->parameters(['clientes' => 'customer'])`.
- No frontend, URLs sempre via `route()` do Ziggy; nunca hardcoded.

## Migrations

- Ordem: `id()`, FKs (`foreignId()->constrained()`), demais colunas, auditoria (`created_by`, `timestamps()`, `deleted_by`, `softDeletes()`), índices.
- Sem tamanho em strings (`string('name')`, nunca `string('name', 150)`).
- Ao alterar uma coluna, repita todos os atributos dela, senão eles se perdem.

## Frontend

- Páginas agrupadas por módulo (`Pages/Customers/Index.vue`); componentes genéricos em `Components/`, layouts em `Layouts/`, composables/helpers em `Composables/` ou `Support/`; componentes de um único módulo ficam junto das páginas dele.
- Componentes Vue com raiz única e `<script setup>`.
- Use `router`, `useForm`, `Link` do Inertia para navegação e formulários; não use axios onde o Inertia resolve.
- Selects/buscas usam o componente de busca compartilhado (opções do controller ou busca remota).
- Estados vazios, modais, dropdowns e confirmações destrutivas usam os componentes compartilhados do projeto.
- Se uma mudança não aparecer na tela, pergunte se `npm run dev`/`composer run dev` está rodando.

## Visual

- Layout plano: **nunca use sombras** (`shadow-*`, `drop-shadow-*`, `box-shadow`, `text-shadow`), nem em modais, popovers ou toasts; desative sombras de bibliotecas. Separe superfícies com `border-border` e contraste de fundo. Anéis de foco são permitidos.
- Cores só por tokens semânticos do `@theme` em `resources/css/app.css` (`primary`, `background`, `surface`/`card`, `foreground`, `muted`, `border`...). Nunca `bg-blue-600`, `text-gray-500` ou hex nos componentes. Cor nova = token novo no `app.css`.
- Hover/ativo com opacidade sobre a cor original (`hover:bg-primary/90`, `bg-muted/10`), não com tokens extras.
- Sem valores arbitrários (`bg-[#000]`, `rounded-[10px]`, `w-[317px]`); use a escala do Tailwind ou uma classe semântica no CSS.
- Combinações de classes repetidas viram classe em `app.css`; mantenha esse conjunto curto.
- Arredondamento único `rounded-xl` em cards, controles, inputs, botões, tabelas, dropdowns, modais e badges. `rounded-full` só para avatares, pills e indicadores circulares. Cantos parciais usam `rounded-t-xl` e equivalentes; `rounded-none` só para unir elementos sem borda interna.
- Cinzas exclusivamente da paleta `neutral` (tokens claros e escuros, overlays como `bg-neutral-950/20`). Nunca `gray`, `zinc`, `slate` ou `stone`, nem hex avulso para superfícies cinzas. Cores de marca, status e gráficos continuam como estão.
- Tipografia segue a escala definida no `@theme` do projeto; não restaure escalas resetadas.
- Todo estilo funciona em modo claro e escuro (preferencialmente via tokens; `dark:` só quando o token não resolve).
- Sem Bootstrap, SCSS ou `tailwind.config.js`. UI minimalista: sem gradientes ou bordas decorativas.

## Textos da Interface

- Títulos, botões, labels, placeholders, menus, abas e opções em Title Case: "Cadastro de Clientes", "Novo Cliente", "Criado Por".
- Só artigos (o, a, os, as) e os conectores de, da, do, das, dos, e, ou ficam minúsculos, exceto como primeira palavra. Preposições como Por, Em, Com, Para, No, Na são maiúsculas.
- Frases terminadas em ponto usam escrita normal: "Nenhum cliente encontrado para o período selecionado."
- Prefira labels substantivos para metadados: "Criador", "Data de Criação".
- Separador visual entre trechos inline é `|`, nunca `·`.
- Cabeçalho de página mostra só título e ações, sem subtítulo descritivo.
- Mensagens de validação em `lang/pt_BR`, sem `:attribute`: "Este campo é obrigatório." Labels de campos novos vão no array `attributes` de `lang/pt_BR/validation.php`.

## Testes

- Pest. Crie com `php artisan make:test --pest`; a maioria é feature test.
- Adicione/atualize testes para mudanças de comportamento e lógica e seus principais casos de falha; mudanças só de texto, estilo ou layout não precisam.
- Use factories (e seus states) em vez de criar dados à mão; não crie scripts de verificação ou tinker quando um teste cobre.
- Rode só os testes afetados (`php artisan test --compact --filter=...`) e garanta que passem. Não apague testes sem aprovação.
- Testes rodam em PostgreSQL, igual à produção.

## Git

- Mensagens de commit curtas, em inglês, tudo em minúsculas, imperativo, sem ponto final: `add tenant isolation tests`. Corpo opcional com bullets `- `. Trailers (`Co-Authored-By:`) mantêm a capitalização padrão.
- Só faça commit ou push quando pedido.

## Comportamento do Agente

- Respostas concisas, focadas no que importa.
- Não crie arquivos de documentação sem pedido.
- Instruções pontuais da tarefa atual não viram regras; novas regras só são registradas quando o usuário pedir ou aprovar.
