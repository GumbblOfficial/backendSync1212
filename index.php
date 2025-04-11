<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Libsql\Database;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// Configurar Turso
$db = new Database(
    url: getenv('TURSO_URL'),
    authToken: getenv('TURSO_TOKEN')
);
$conn = $db->connect();

// Middleware de autenticación
function authMiddleware($request, $handler) {
    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ', '', $token);
    if (!$token) {
        return jsonResponse(401, ['error' => 'Acceso denegado']);
    }
    try {
        $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(getenv('JWT_SECRET'), 'HS256'));
        $request = $request->withAttribute('user', $decoded);
        return $handler->handle($request);
    } catch (Exception $e) {
        return jsonResponse(401, ['error' => 'Token inválido']);
    }
}

// Helper para respuestas JSON
function jsonResponse($status, $data) {
    $response = new \Slim\Psr7\Response();
    $response->getBody()->write(json_encode($data));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
}

// Registro
$app->post('/api/register', function (Request $request, Response $response) use ($conn) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $name = $data['name'] ?? '';

    if (!$email || !$password || !$name) {
        return jsonResponse(400, ['error' => 'Faltan datos']);
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $conn->execute("INSERT INTO users (email, password, name) VALUES (?, ?, ?)", [$email, $hashedPassword, $name]);
    return jsonResponse(201, ['message' => 'Usuario registrado']);
});

// Login
$app->post('/api/login', function (Request $request, Response $response) use ($conn) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    $result = $conn->query("SELECT * FROM users WHERE email = ?", [$email])->fetchArray();
    if (!$result || !password_verify($password, $result['password'])) {
        return jsonResponse(401, ['error' => 'Credenciales inválidas']);
    }

    $token = \Firebase\JWT\JWT::encode(['userId' => $result['id'], 'name' => $result['name']], getenv('JWT_SECRET'), 'HS256');
    return jsonResponse(200, ['token' => $token, 'name' => $result['name']]);
});

// Disponibilidad
$app->post('/api/availability', function (Request $request, Response $response) use ($conn) {
    $data = $request->getParsedBody();
    $user = $request->getAttribute('user');
    $groupId = $data['groupId'] ?? '';
    $timezone = $data['timezone'] ?? '';
    $startTime = $data['startTime'] ?? '';
    $endTime = $data['endTime'] ?? '';

    $conn->execute("INSERT INTO availabilities (userId, groupId, timezone, startTime, endTime) VALUES (?, ?, ?, ?, ?)", 
        [$user->userId, $groupId, $timezone, $startTime, $endTime]);
    return jsonResponse(201, ['message' => 'Disponibilidad guardada']);
})->add('authMiddleware');

// Obtener disponibilidades
$app->get('/api/availabilities/{groupId}', function (Request $request, Response $response, $args) use ($conn) {
    $groupId = $args['groupId'];
    $result = $conn->query("SELECT a.*, u.name FROM availabilities a JOIN users u ON a.userId = u.id WHERE a.groupId = ?", [$groupId])->fetchArray(SQLITE3_ASSOC);
    return jsonResponse(200, $result);
})->add('authMiddleware');

// Crear grupo
$app->post('/api/groups', function (Request $request, Response $response) use ($conn) {
    $data = $request->getParsedBody();
    $user = $request->getAttribute('user');
    $name = $data['name'] ?? '';
    $description = $data['description'] ?? '';

    $conn->execute("INSERT INTO groups (name, description) VALUES (?, ?)", [$name, $description]);
    $groupId = $conn->lastInsertRowID();
    $conn->execute("INSERT INTO group_members (groupId, userId) VALUES (?, ?)", [$groupId, $user->userId]);
    return jsonResponse(201, ['id' => $groupId, 'name' => $name, 'description' => $description]);
})->add('authMiddleware');

// Obtener grupos
$app->get('/api/groups', function (Request $request, Response $response) use ($conn) {
    $user = $request->getAttribute('user');
    $result = $conn->query("SELECT g.* FROM groups g JOIN group_members gm ON g.id = gm.groupId WHERE gm.userId = ?", [$user->userId])->fetchArray(SQLITE3_ASSOC);
    return jsonResponse(200, $result);
})->add('authMiddleware');

// Unirse a grupo
$app->post('/api/groups/{groupId}/join', function (Request $request, Response $response, $args) use ($conn) {
    $groupId = $args['groupId'];
    $user = $request->getAttribute('user');
    $conn->execute("INSERT INTO group_members (groupId, userId) VALUES (?, ?)", [$groupId, $user->userId]);
    return jsonResponse(200, ['message' => 'Unido al grupo']);
})->add('authMiddleware');

$app->run();
