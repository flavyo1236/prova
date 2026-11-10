<?php

use App\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$pdo = Database::connect();
$app = AppFactory::create();

$respond = static function (
    ResponseInterface $response,
    array $body,
    int $status = 200
): ResponseInterface {
    $response->getBody()->write(
        (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withStatus($status);
};

$parseJson = static function (ServerRequestInterface $request) use ($respond): array {
    $contentType = $request->getHeaderLine('Content-Type');
    if (stripos($contentType, 'application/json') !== 0) {
        return ['error' => $respond(
            new \Slim\Psr7\Response(),
            ['erro' => 'O Content-Type deve ser application/json.'],
            415
        )];
    }

    try {
        $data = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return ['error' => $respond(
            new \Slim\Psr7\Response(),
            ['erro' => 'O corpo da requisição deve conter um JSON válido.'],
            400
        )];
    }

    if (!is_array($data)) {
        return ['error' => $respond(
            new \Slim\Psr7\Response(),
            ['erro' => 'O corpo da requisição deve ser um objeto JSON.'],
            400
        )];
    }

    return ['data' => $data];
};

$validateCategory = static function (array $input): array {
    $name = isset($input['nome']) && is_string($input['nome']) ? trim($input['nome']) : '';
    $type = $input['tipo'] ?? null;

    if ($name === '' || !is_string($type) || !in_array($type, ['receita', 'despesa'], true)) {
        return ['erro' => 'Informe um nome e um tipo válido (receita ou despesa).'];
    }

    return ['nome' => $name, 'tipo' => $type];
};

$validDate = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $dateObject = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = \DateTimeImmutable::getLastErrors();

    return $dateObject !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $dateObject->format('Y-m-d') === $date;
};

$validateTransaction = static function (array $input, PDO $pdo) use ($validDate): array {
    $description = isset($input['descricao']) && is_string($input['descricao'])
        ? trim($input['descricao'])
        : '';
    $type = $input['tipo'] ?? null;
    $amount = $input['valor'] ?? null;
    $date = $input['data'] ?? null;
    $categoryId = filter_var($input['categoria_id'] ?? null, FILTER_VALIDATE_INT);

    if (
        $description === ''
        || !is_string($type)
        || !in_array($type, ['receita', 'despesa'], true)
        || (!is_int($amount) && !is_float($amount))
        || !is_finite((float) $amount)
        || (float) $amount <= 0
        || !is_string($date)
        || $categoryId === false
        || $categoryId < 1
    ) {
        return ['erro' => 'Informe descrição, valor maior que zero, data, tipo válido e categoria_id.'];
    }

    if (!$validDate($date)) {
        return ['erro' => 'A data deve ser válida e estar no formato AAAA-MM-DD.'];
    }

    $statement = $pdo->prepare('SELECT tipo FROM categorias WHERE id = :id');
    $statement->execute(['id' => $categoryId]);
    $category = $statement->fetch();
    if ($category === false) {
        return ['erro' => 'A categoria informada não existe.'];
    }

    if ($category['tipo'] !== $type) {
        return ['erro' => 'O tipo do lançamento deve ser igual ao tipo da categoria.'];
    }

    return [
        'descricao' => $description,
        'valor' => (float) $amount,
        'data' => $date,
        'tipo' => $type,
        'categoria_id' => $categoryId,
    ];
};

$isDuplicateCategory = static function (PDOException $exception): bool {
    return isset($exception->errorInfo[1])
        && (int) $exception->errorInfo[1] === 19
        && stripos($exception->getMessage(), 'categorias.nome') !== false;
};

$readTransaction = static function (PDO $pdo, int $id): array|false {
    $statement = $pdo->prepare(
        'SELECT id, descricao, valor, data_lancamento AS data, tipo, categoria_id
         FROM lancamentos WHERE id = :id'
    );
    $statement->execute(['id' => $id]);

    return $statement->fetch();
};

$app->get('/status', static function (ServerRequestInterface $request, ResponseInterface $response) use ($respond) {
    return $respond($response, ['status' => 'ok']);
});

$app->get('/categorias', static function (ServerRequestInterface $request, ResponseInterface $response) use ($pdo, $respond) {
    $categories = $pdo->query('SELECT id, nome, tipo FROM categorias ORDER BY id')->fetchAll();

    return $respond($response, $categories);
});

$app->post('/categorias', static function (ServerRequestInterface $request, ResponseInterface $response) use ($pdo, $respond, $parseJson, $validateCategory, $isDuplicateCategory) {
    $parsed = $parseJson($request);
    if (isset($parsed['error'])) {
        return $parsed['error'];
    }

    $category = $validateCategory($parsed['data']);
    if (isset($category['erro'])) {
        return $respond($response, $category, 422);
    }

    try {
        $statement = $pdo->prepare('INSERT INTO categorias (nome, tipo) VALUES (:nome, :tipo)');
        $statement->execute($category);
    } catch (PDOException $exception) {
        if ($isDuplicateCategory($exception)) {
            return $respond($response, ['erro' => 'Já existe uma categoria com esse nome.'], 409);
        }

        throw $exception;
    }

    $category['id'] = (int) $pdo->lastInsertId();

    return $respond($response, $category, 201)
        ->withHeader('Location', '/categorias/' . $category['id']);
});

$app->get('/categorias/{id:[0-9]+}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($pdo, $respond) {
    $statement = $pdo->prepare('SELECT id, nome, tipo FROM categorias WHERE id = :id');
    $statement->execute(['id' => (int) $args['id']]);
    $category = $statement->fetch();
    if ($category === false) {
        return $respond($response, ['erro' => 'Categoria não encontrada.'], 404);
    }

    return $respond($response, $category);
});

$app->put('/categorias/{id:[0-9]+}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($pdo, $respond, $parseJson, $validateCategory, $isDuplicateCategory) {
    $id = (int) $args['id'];
    $parsed = $parseJson($request);
    if (isset($parsed['error'])) {
        return $parsed['error'];
    }

    $category = $validateCategory($parsed['data']);
    if (isset($category['erro'])) {
        return $respond($response, $category, 422);
    }

    try {
        $statement = $pdo->prepare('UPDATE categorias SET nome = :nome, tipo = :tipo WHERE id = :id');
        $statement->execute($category + ['id' => $id]);
    } catch (PDOException $exception) {
        if ($isDuplicateCategory($exception)) {
            return $respond($response, ['erro' => 'Já existe uma categoria com esse nome.'], 409);
        }

        throw $exception;
    }

    if ($statement->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM categorias WHERE id = :id');
        $exists->execute(['id' => $id]);
        if ($exists->fetchColumn() === false) {
            return $respond($response, ['erro' => 'Categoria não encontrada.'], 404);
        }
    }

    return $respond($response, ['id' => $id] + $category);
});

$app->delete('/categorias/{id:[0-9]+}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($pdo, $respond) {
    $id = (int) $args['id'];
    $pdo->beginTransaction();

    try {
        $exists = $pdo->prepare('SELECT 1 FROM categorias WHERE id = :id');
        $exists->execute(['id' => $id]);
        if ($exists->fetchColumn() === false) {
            $pdo->rollBack();
            return $respond($response, ['erro' => 'Categoria não encontrada.'], 404);
        }

        $linked = $pdo->prepare('SELECT 1 FROM lancamentos WHERE categoria_id = :id LIMIT 1');
        $linked->execute(['id' => $id]);
        if ($linked->fetchColumn() !== false) {
            $pdo->rollBack();
            return $respond($response, ['erro' => 'Não é possível excluir uma categoria que possui lançamentos.'], 409);
        }

        $delete = $pdo->prepare('DELETE FROM categorias WHERE id = :id');
        $delete->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return $response->withStatus(204);
});

$app->get('/lancamentos', static function (ServerRequestInterface $request, ResponseInterface $response) use ($pdo, $respond, $validDate) {
    $query = $request->getQueryParams();
    $conditions = [];
    $parameters = [];

    if (isset($query['data_inicio']) || isset($query['data_fim'])) {
        if (
            !isset($query['data_inicio'], $query['data_fim'])
            || !is_string($query['data_inicio'])
            || !is_string($query['data_fim'])
            || !$validDate($query['data_inicio'])
            || !$validDate($query['data_fim'])
            || $query['data_inicio'] > $query['data_fim']
        ) {
            return $respond($response, ['erro' => 'Informe um período válido usando data_inicio e data_fim (AAAA-MM-DD).'], 422);
        }

        $conditions[] = 'data_lancamento BETWEEN :data_inicio AND :data_fim';
        $parameters['data_inicio'] = $query['data_inicio'];
        $parameters['data_fim'] = $query['data_fim'];
    }

    if (isset($query['categoria_id'])) {
        $categoryId = filter_var($query['categoria_id'], FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            return $respond($response, ['erro' => 'categoria_id deve ser um identificador válido.'], 422);
        }

        $conditions[] = 'categoria_id = :categoria_id';
        $parameters['categoria_id'] = $categoryId;
    }

    if (isset($query['tipo'])) {
        if (!in_array($query['tipo'], ['receita', 'despesa'], true)) {
            return $respond($response, ['erro' => 'tipo deve ser receita ou despesa.'], 422);
        }

        $conditions[] = 'tipo = :tipo';
        $parameters['tipo'] = $query['tipo'];
    }

    $sql = 'SELECT id, descricao, valor, data_lancamento AS data, tipo, categoria_id FROM lancamentos';
    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY data_lancamento, id';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $respond($response, $statement->fetchAll());
});

$app->post('/lancamentos', static function (ServerRequestInterface $request, ResponseInterface $response) use ($pdo, $respond, $parseJson, $validateTransaction) {
    $parsed = $parseJson($request);
    if (isset($parsed['error'])) {
        return $parsed['error'];
    }

    $transaction = $validateTransaction($parsed['data'], $pdo);
    if (isset($transaction['erro'])) {
        return $respond($response, $transaction, 422);
    }

    $statement = $pdo->prepare(
        'INSERT INTO lancamentos (descricao, valor, data_lancamento, tipo, categoria_id)
         VALUES (:descricao, :valor, :data, :tipo, :categoria_id)'
    );
    $statement->execute($transaction);
    $id = (int) $pdo->lastInsertId();

    return $respond($response, ['id' => $id] + $transaction, 201)
        ->withHeader('Location', '/lancamentos/' . $id);
});

$app->get('/lancamentos/{id:[0-9]+}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($pdo, $respond, $readTransaction) {
    $transaction = $readTransaction($pdo, (int) $args['id']);
    if ($transaction === false) {
        return $respond($response, ['erro' => 'Lançamento não encontrado.'], 404);
    }

    return $respond($response, $transaction);
});

$app->put('/lancamentos/{id:[0-9]+}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($pdo, $respond, $parseJson, $validateTransaction, $readTransaction) {
    $id = (int) $args['id'];
    $parsed = $parseJson($request);
    if (isset($parsed['error'])) {
        return $parsed['error'];
    }

    $transaction = $validateTransaction($parsed['data'], $pdo);
    if (isset($transaction['erro'])) {
        return $respond($response, $transaction, 422);
    }

    $exists = $readTransaction($pdo, $id);
    if ($exists === false) {
        return $respond($response, ['erro' => 'Lançamento não encontrado.'], 404);
    }

    $statement = $pdo->prepare(
        'UPDATE lancamentos
         SET descricao = :descricao, valor = :valor, data_lancamento = :data, tipo = :tipo, categoria_id = :categoria_id
         WHERE id = :id'
    );
    $statement->execute($transaction + ['id' => $id]);

    return $respond($response, ['id' => $id] + $transaction);
});

$app->delete('/lancamentos/{id:[0-9]+}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($pdo, $respond, $readTransaction) {
    $id = (int) $args['id'];
    if ($readTransaction($pdo, $id) === false) {
        return $respond($response, ['erro' => 'Lançamento não encontrado.'], 404);
    }

    $statement = $pdo->prepare('DELETE FROM lancamentos WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $response->withStatus(204);
});

$app->get('/resumo/saldo', static function (ServerRequestInterface $request, ResponseInterface $response) use ($pdo, $respond) {
    $summary = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END), 0) AS total_receitas,
            COALESCE(SUM(CASE WHEN tipo = 'despesa' THEN valor ELSE 0 END), 0) AS total_despesas
         FROM lancamentos"
    )->fetch();
    $income = (float) $summary['total_receitas'];
    $expenses = (float) $summary['total_despesas'];

    return $respond($response, [
        'total_receitas' => round($income, 2),
        'total_despesas' => round($expenses, 2),
        'saldo' => round($income - $expenses, 2),
    ]);
});

$app->get('/resumo/periodo', static function (ServerRequestInterface $request, ResponseInterface $response) use ($pdo, $respond, $validDate) {
    $query = $request->getQueryParams();
    $start = $query['data_inicio'] ?? null;
    $end = $query['data_fim'] ?? null;
    if (
        !is_string($start)
        || !is_string($end)
        || !$validDate($start)
        || !$validDate($end)
        || $start > $end
    ) {
        return $respond($response, ['erro' => 'Informe data_inicio e data_fim válidas (AAAA-MM-DD), com início anterior ao fim.'], 422);
    }

    $statement = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END), 0) AS total_receitas,
            COALESCE(SUM(CASE WHEN tipo = 'despesa' THEN valor ELSE 0 END), 0) AS total_despesas
         FROM lancamentos
         WHERE data_lancamento BETWEEN :data_inicio AND :data_fim"
    );
    $statement->execute(['data_inicio' => $start, 'data_fim' => $end]);
    $summary = $statement->fetch();
    $income = (float) $summary['total_receitas'];
    $expenses = (float) $summary['total_despesas'];

    return $respond($response, [
        'data_inicio' => $start,
        'data_fim' => $end,
        'total_receitas' => round($income, 2),
        'total_despesas' => round($expenses, 2),
        'saldo' => round($income - $expenses, 2),
    ]);
});

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$responseFactory = $app->getResponseFactory();
$errorMiddleware->setDefaultErrorHandler(
    static function (
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($responseFactory, $respond): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        if ($logErrors) {
            error_log((string) $exception);
        }

        $message = $status >= 500 ? 'Erro interno do servidor.' : $exception->getMessage();

        return $respond($responseFactory->createResponse(), ['erro' => $message], $status);
    }
);

return $app;
