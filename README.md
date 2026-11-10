# Web Service de Controle Financeiro Pessoal

API REST em PHP com Slim 4 e SQLite para registrar receitas e despesas, manter categorias e acompanhar o saldo.

## Requisitos

- PHP 8.1 ou superior, com as extensões `pdo` e `pdo_sqlite` habilitadas.
- Composer.

## Instalação e execução

Na pasta do projeto, instale as dependências:

```bash
composer install
```

Inicie o servidor embutido do PHP:

```bash
php -S localhost:8080 index.php
```

A API ficará disponível em `http://localhost:8080`. O banco SQLite é criado automaticamente em `data/financeiro.sqlite`.
Para usar outro arquivo de banco, configure a variável de ambiente `FINANCEIRO_DB_PATH` antes de iniciar a aplicação.

## Testes

Execute todos os testes automatizados com:

```bash
composer test
```

Cada teste usa seu próprio banco SQLite em memória, sem alterar os dados da aplicação.

## Formato das requisições e erros

Envie os corpos das requisições como JSON com o cabeçalho `Content-Type: application/json`. Erros são retornados no formato `{"erro":"mensagem"}`. A API usa `200` para consultas e atualizações, `201` para criações, `204` para exclusões sem corpo, `400` para JSON malformado, `404` para recursos inexistentes, `409` para conflitos e `422` para dados ou regras de negócio inválidos.

Tipos aceitos: `receita` e `despesa`. As datas devem usar `AAAA-MM-DD`.

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| GET | `/status` | Verifica se a API está disponível |
| POST | `/categorias` | Cadastra categoria (`nome`, `tipo`) |
| GET | `/categorias` | Lista categorias |
| GET | `/categorias/{id}` | Consulta categoria |
| PUT | `/categorias/{id}` | Atualiza categoria (`nome`, `tipo`) |
| DELETE | `/categorias/{id}` | Exclui categoria sem lançamentos vinculados |
| POST | `/lancamentos` | Cadastra lançamento (`descricao`, `valor`, `data`, `tipo`, `categoria_id`) |
| GET | `/lancamentos` | Lista lançamentos; aceita filtros abaixo |
| GET | `/lancamentos/{id}` | Consulta lançamento |
| PUT | `/lancamentos/{id}` | Atualiza lançamento com todos os campos |
| DELETE | `/lancamentos/{id}` | Exclui lançamento |
| GET | `/resumo/saldo` | Retorna receitas, despesas e saldo atual |
| GET | `/resumo/periodo?data_inicio=AAAA-MM-DD&data_fim=AAAA-MM-DD` | Retorna totais e saldo do período |

Filtros combináveis de lançamentos:

- `data_inicio` e `data_fim`: período inclusivo; ambos são obrigatórios quando um deles for informado.
- `categoria_id`: identificador da categoria.
- `tipo`: `receita` ou `despesa`.

Exemplo de criação de categoria:

```json
{
  "nome": "Moradia",
  "tipo": "despesa"
}
```

Exemplo de criação de lançamento:

```json
{
  "descricao": "Conta de luz",
  "valor": 145.9,
  "data": "2026-05-12",
  "tipo": "despesa",
  "categoria_id": 1
}
```

## Regras implementadas

- O valor do lançamento deve ser maior que zero e sua data deve existir no calendário.
- O tipo da categoria deve corresponder ao tipo do lançamento.
- O nome das categorias é único; a comparação também ignora caixa em letras ASCII.
- Não é possível criar lançamento com categoria inexistente nem excluir categoria vinculada a lançamentos.
- O saldo é calculado como total de receitas menos total de despesas.
