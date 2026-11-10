<?php

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ResponseInterface;

final class FinanceiroTest extends TestCase
{
    private \Slim\App $app;

    protected function setUp(): void
    {
        putenv('FINANCEIRO_DB_PATH=:memory:');
        $this->app = require __DIR__ . '/../src/app.php';
    }

    protected function tearDown(): void
    {
        unset($this->app);
        putenv('FINANCEIRO_DB_PATH');
    }

    public function testHealthAndCategoryEndpoints(): void
    {
        $status = $this->request('GET', '/status');
        $this->assertSame(200, $status->getStatusCode());
        $this->assertSame(['status' => 'ok'], $this->json($status));

        $list = $this->request('GET', '/categorias');
        $this->assertSame(200, $list->getStatusCode());
        $this->assertSame([], $this->json($list));

        $invalid = $this->request('POST', '/categorias', ['nome' => '', 'tipo' => 'outro']);
        $this->assertSame(422, $invalid->getStatusCode());
        $this->assertArrayHasKey('erro', $this->json($invalid));

        $created = $this->request('POST', '/categorias', ['nome' => 'Salário', 'tipo' => 'receita']);
        $this->assertSame(201, $created->getStatusCode());
        $category = $this->json($created);
        $this->assertSame('Salário', $category['nome']);
        $this->assertSame('receita', $category['tipo']);
        $id = $category['id'];

        $this->assertSame(409, $this->request(
            'POST',
            '/categorias',
            ['nome' => 'Salário', 'tipo' => 'receita']
        )->getStatusCode());

        $read = $this->request('GET', '/categorias/' . $id);
        $this->assertSame(200, $read->getStatusCode());
        $readCategory = $this->json($read);
        $this->assertSame($category['id'], $readCategory['id']);
        $this->assertSame($category['nome'], $readCategory['nome']);
        $this->assertSame($category['tipo'], $readCategory['tipo']);
        $this->assertSame(404, $this->request('GET', '/categorias/9999')->getStatusCode());

        $updated = $this->request('PUT', '/categorias/' . $id, [
            'nome' => 'Renda',
            'tipo' => 'receita',
        ]);
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame('Renda', $this->json($updated)['nome']);
        $this->assertSame(422, $this->request('PUT', '/categorias/' . $id, ['nome' => 'Renda'])->getStatusCode());
        $this->assertSame(404, $this->request('PUT', '/categorias/9999', [
            'nome' => 'Ausente',
            'tipo' => 'receita',
        ])->getStatusCode());

        $this->assertSame(204, $this->request('DELETE', '/categorias/' . $id)->getStatusCode());
        $this->assertSame(404, $this->request('DELETE', '/categorias/' . $id)->getStatusCode());
    }

    public function testCategoryCannotBeDeletedWhileTransactionsUseIt(): void
    {
        $category = $this->createCategory('Moradia', 'despesa');
        $this->createTransaction($category['id'], 'Aluguel', 1200, '2026-01-05', 'despesa');

        $response = $this->request('DELETE', '/categorias/' . $category['id']);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertArrayHasKey('erro', $this->json($response));
    }

    public function testTransactionEndpointsAndBusinessRules(): void
    {
        $incomeCategory = $this->createCategory('Trabalho', 'receita');
        $expenseCategory = $this->createCategory('Mercado', 'despesa');

        $invalid = $this->request('POST', '/lancamentos', [
            'descricao' => 'Erro',
            'valor' => 0,
            'data' => '2026-02-31',
            'tipo' => 'receita',
            'categoria_id' => $incomeCategory['id'],
        ]);
        $this->assertSame(422, $invalid->getStatusCode());

        $missingCategory = $this->request('POST', '/lancamentos', [
            'descricao' => 'Erro',
            'valor' => 10,
            'data' => '2026-02-01',
            'tipo' => 'receita',
            'categoria_id' => 9999,
        ]);
        $this->assertSame(422, $missingCategory->getStatusCode());

        $mismatchedType = $this->request('POST', '/lancamentos', [
            'descricao' => 'Erro',
            'valor' => 10,
            'data' => '2026-02-01',
            'tipo' => 'receita',
            'categoria_id' => $expenseCategory['id'],
        ]);
        $this->assertSame(422, $mismatchedType->getStatusCode());

        $created = $this->request('POST', '/lancamentos', [
            'descricao' => 'Pagamento',
            'valor' => 3500.50,
            'data' => '2026-02-10',
            'tipo' => 'receita',
            'categoria_id' => $incomeCategory['id'],
        ]);
        $this->assertSame(201, $created->getStatusCode());
        $transaction = $this->json($created);
        $this->assertSame('Pagamento', $transaction['descricao']);
        $id = $transaction['id'];

        $list = $this->request('GET', '/lancamentos');
        $this->assertSame(200, $list->getStatusCode());
        $this->assertCount(1, $this->json($list));

        $read = $this->request('GET', '/lancamentos/' . $id);
        $this->assertSame(200, $read->getStatusCode());
        $this->assertSame($transaction, $this->json($read));
        $this->assertSame(404, $this->request('GET', '/lancamentos/9999')->getStatusCode());

        $updated = $this->request('PUT', '/lancamentos/' . $id, [
            'descricao' => 'Pagamento atualizado',
            'valor' => 3600,
            'data' => '2026-02-11',
            'tipo' => 'receita',
            'categoria_id' => $incomeCategory['id'],
        ]);
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame('Pagamento atualizado', $this->json($updated)['descricao']);
        $this->assertSame(404, $this->request('PUT', '/lancamentos/9999', [
            'descricao' => 'Ausente',
            'valor' => 10,
            'data' => '2026-02-11',
            'tipo' => 'receita',
            'categoria_id' => $incomeCategory['id'],
        ])->getStatusCode());

        $this->assertSame(204, $this->request('DELETE', '/lancamentos/' . $id)->getStatusCode());
        $this->assertSame(404, $this->request('DELETE', '/lancamentos/' . $id)->getStatusCode());
    }

    public function testTransactionFilters(): void
    {
        $income = $this->createCategory('Freelance', 'receita');
        $expense = $this->createCategory('Transporte', 'despesa');
        $this->createTransaction($income['id'], 'Projeto', 800, '2026-03-05', 'receita');
        $this->createTransaction($expense['id'], 'Ônibus', 50, '2026-03-10', 'despesa');
        $this->createTransaction($income['id'], 'Outro projeto', 300, '2026-04-02', 'receita');

        $period = $this->request('GET', '/lancamentos?data_inicio=2026-03-01&data_fim=2026-03-31');
        $this->assertSame(200, $period->getStatusCode());
        $this->assertCount(2, $this->json($period));

        $byCategory = $this->request('GET', '/lancamentos?categoria_id=' . $income['id']);
        $this->assertCount(2, $this->json($byCategory));

        $byType = $this->request('GET', '/lancamentos?tipo=despesa');
        $this->assertCount(1, $this->json($byType));

        $invalidPeriod = $this->request('GET', '/lancamentos?data_inicio=2026-03-31&data_fim=2026-03-01');
        $this->assertSame(422, $invalidPeriod->getStatusCode());

        $invalidType = $this->request('GET', '/lancamentos?tipo=transferencia');
        $this->assertSame(422, $invalidType->getStatusCode());
    }

    public function testFinancialSummaryEndpoints(): void
    {
        $income = $this->createCategory('Receitas', 'receita');
        $expense = $this->createCategory('Contas', 'despesa');
        $this->createTransaction($income['id'], 'Salário', 4000, '2026-05-01', 'receita');
        $this->createTransaction($expense['id'], 'Conta de luz', 250.50, '2026-05-12', 'despesa');
        $this->createTransaction($income['id'], 'Extra', 500, '2026-06-02', 'receita');

        $balance = $this->request('GET', '/resumo/saldo');
        $this->assertSame(200, $balance->getStatusCode());
        $balanceData = $this->json($balance);
        $this->assertEquals(4500, $balanceData['total_receitas']);
        $this->assertEquals(250.50, $balanceData['total_despesas']);
        $this->assertEquals(4249.50, $balanceData['saldo']);

        $period = $this->request('GET', '/resumo/periodo?data_inicio=2026-05-01&data_fim=2026-05-31');
        $this->assertSame(200, $period->getStatusCode());
        $periodData = $this->json($period);
        $this->assertEquals(4000, $periodData['total_receitas']);
        $this->assertEquals(250.50, $periodData['total_despesas']);

        $invalid = $this->request('GET', '/resumo/periodo?data_inicio=2026-06-30&data_fim=2026-06-01');
        $this->assertSame(422, $invalid->getStatusCode());
    }

    public function testMalformedJsonReturnsStandardError(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/categorias')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{invalido');
        $request->getBody()->rewind();

        $response = $this->app->handle($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('erro', $this->json($response));
    }

    private function request(string $method, string $uri, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withHeader('Accept', 'application/json');

        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode($body, JSON_UNESCAPED_UNICODE));
            $request->getBody()->rewind();
        }

        return $this->app->handle($request);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createCategory(string $name, string $type): array
    {
        $response = $this->request('POST', '/categorias', ['nome' => $name, 'tipo' => $type]);
        $this->assertSame(201, $response->getStatusCode());

        return $this->json($response);
    }

    private function createTransaction(
        int $categoryId,
        string $description,
        float $amount,
        string $date,
        string $type
    ): array {
        $response = $this->request('POST', '/lancamentos', [
            'descricao' => $description,
            'valor' => $amount,
            'data' => $date,
            'tipo' => $type,
            'categoria_id' => $categoryId,
        ]);
        $this->assertSame(201, $response->getStatusCode());

        return $this->json($response);
    }
}
