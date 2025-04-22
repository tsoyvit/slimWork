<?php

namespace App;

require __DIR__ . '/../vendor/autoload.php';

session_start();

use PDO;
use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;
use Symfony\Component\VarDumper\VarDumper;

const ADMIN_EMAIL = 'admin@mail.com';

$container = new Container();

$container->set(\PDO::class, function () {
    $conn = new \PDO('sqlite:database.sqlite');
    $conn->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $conn;
});

$initFilePath = implode('/', [dirname(__DIR__), 'init.sql']);
$initSql = file_get_contents($initFilePath);
$container->get(\PDO::class)->exec($initSql);

$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add(MethodOverrideMiddleware::class);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();
$authMiddleware = function ($request, $handler) use ($router) {
    if (empty($_SESSION['authenticated'])) {
        $this->get('flash')->addMessage('error', 'Требуется авторизация');
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', $router->urlFor('index'))->withStatus(302);
    }

    return $handler->handle($request);
};
$app->get('/', function ($request, $response) {

    $messages = $this->get('flash')->getMessages();
    $params = [
        'email' => '',
        'flash' => $messages ?? []
    ];
    return $this->get('renderer')->render($response, 'home.phtml', $params);
})->setName('index');

$app->post('/login', function ($request, $response) use ($router) {
    $repo = new UserRepository();
    $email = $request->getParsedBodyParam('email');

    $user = $repo->findByEmail($email);

    if ($user || $email === ADMIN_EMAIL) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];

        $this->get('flash')->addMessage('success', 'Добро пожаловать!');
        $url = $router->urlFor('users');
        return $response->withRedirect($url);
    } else {
        $this->get('flash')->addMessage('error', 'Пользователь не найден');
        return $response->withRedirect($router->urlFor('users'));
    }
});

$app->post('/logout', function ($request, $response) use ($router) {
    $_SESSION = [];
    session_destroy();
    $this->get('flash')->addMessage('success', 'Вы вышли из системы');
    return $response->withRedirect($router->urlFor('index'));
});

$app->get('/users', function ($request, $response) use ($router) {

    $allUsers = new UserRepository();
    $allUsers = $allUsers->getAllUsers();
    $term = $request->getQueryParam('term', '');

    if ($term) {
        $filteredUsers = new UserRepository();
        $filteredUsers = $filteredUsers->filteredByName($term);
    } else {
        $filteredUsers = [];
    }
    $messages = $this->get('flash')->getMessages();

    $params = ['users' => $filteredUsers, 'term' => $term, 'allUsers' => $allUsers, 'flash' => $messages];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users')->add($authMiddleware);

$app->get('/users/new', function ($request, $response) {

    $params = [
        'user' => ['nickname' => '', 'email' => ''],
        'errors' => ''
    ];
    return $this->get('renderer')->render($response, 'users/new.phtml', $params);
})->setName('newUser')->add($authMiddleware);

$app->get('/users/{id}', function ($request, $response, $args) {
    $user = new UserRepository();
    $id = $args['id'];
    $user = $user->findUserById($id);

    if (!$user) {
        return $response->withStatus(404)->write("User not found");
    }

    $params = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']];
    return $this->get('renderer')->render($response, 'users/show.phtml', $params);
})->setName('user')->add($authMiddleware);

$app->post('/users', function ($request, $response) use ($router) {
    $repo = new UserRepository();
    $validator = new Validator();
    $user = $request->getParsedBodyParam('user');
    $errors = $validator->validate($user);

    if (count($errors) === 0) {
        $repo->save($user);
        $this->get('flash')->addMessage('success', 'New user created.');
        return $response->withRedirect($router->urlFor('users'), 302);
    }

    $params = ['user' => $user, 'errors' => $errors];
    return $this->get('renderer')->render($response, 'users/new.phtml', $params);

})->add($authMiddleware);

$app->get('/users/{id}/edit', function ($request, $response, $args) {
    $user = new UserRepository();
    $id = $args['id'];
    $user = $user->findUserById($id);

    $params = ['user' => $user, 'errors' => []];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
})->setName('editUser')->add($authMiddleware);

$app->patch('/users/{id}', function ($request, $response, $args) use ($router) {
    $repo = new UserRepository();
    $validator = new Validator();
    $id = $args['id'];
    $editUser = $request->getParsedBodyParam('user');

    $user = $repo->findUserById($id);
    if (!$user) {
        $this->get('flash')->addMessage('error', 'User not found.');
        return $response->withRedirect($router->urlFor('editUser', ['id' => $id]), 404);
    }

    if (!isset($editUser['name']) || !isset($editUser['email']) || !count($editUser) == 2) {
        $this->get('flash')->addMessage('error', 'Invalid user.');
        return $response->withRedirect($router->urlFor('editUser'), 404);
    }

    $errors = $validator->validate($editUser);
    if (count($errors) === 0) {
        $repo->editUser($editUser, $id);
        $this->get('flash')->addMessage('success', 'User updated.');
        $url = $router->urlFor('users');
        return $response->withRedirect($url);
    }
    $params = ['user' => $user, 'errors' => $errors];
    return $this->get('renderer')->render($response->withStatus(422), 'users/edit.phtml', $params);
})->add($authMiddleware);

$app->delete('/users/{id}', function ($request, $response, $args) use ($router) {
    $repo = new UserRepository();
    $id = $args['id'];
    $user = $repo->findUserById($id);
    if (!$user) {
        $this->get('flash')->addMessage('error', 'User not found.');
        return $response->withRedirect($router->urlFor('users'), 404);
    }
    $repo->destroy($id);
    $this->get('flash')->addMessage('success', 'User deleted.');
    $url = $router->urlFor('users');
    return $response->withRedirect($url);
})->add($authMiddleware);


//====================================================================

$app->get('/cars', function ($request, $response) {
    $carRepository = $this->get(CarRepository::class);
    $cars = $carRepository->getEntities();

    $messages = $this->get('flash')->getMessages();

    $params = [
        'cars' => $cars,
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'cars/index.phtml', $params);
})->setName('cars.index');

$app->post('/cars', function ($request, $response) use ($router) {
    $carRepository = $this->get(CarRepository::class);
    $carData = $request->getParsedBodyParam('car');

    $validator = new CarValidator();
    $errors = $validator->validate($carData);

    if (count($errors) === 0) {
        $car = Car::fromArray([$carData['make'], $carData['model']]);
        $carRepository->save($car);
        $this->get('flash')->addMessage('success', 'Car was added successfully');
        return $response->withRedirect($router->urlFor('cars.index'));
    }

    $params = [
        'car' => $carData,
        'errors' => $errors
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'cars/new.phtml', $params);
})->setName('cars.store');

$app->get('/cars/new', function ($request, $response) {
    $params = [
        'car' => new Car(),
        'errors' => []
    ];

    return $this->get('renderer')->render($response, 'cars/new.phtml', $params);
})->setName('cars.create');

$app->get('/cars/{id}', function ($request, $response, $args) {
    $carRepository = $this->get(CarRepository::class);
    $id = $args['id'];
    $car = $carRepository->find($id);

    if (is_null($car)) {
        return $response->write('Page not found')->withStatus(404);
    }

    $messages = $this->get('flash')->getMessages();

    $params = [
        'car' => $car,
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'cars/show.phtml', $params);
})->setName('cars.show');

$app->get('/cars/{id}/edit', function ($request, $response, $args) {
    $carRepository = $this->get(CarRepository::class);
    $messages = $this->get('flash')->getMessages();
    $id = $args['id'];
    $car = $carRepository->find($id);

    $params = [
        'car' => $car,
        'errors' => [],
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'cars/edit.phtml', $params);
})->setName('cars.edit');

$app->patch('/cars/{id}', function ($request, $response, $args) use ($router) {
    $carRepository = $this->get(CarRepository::class);
    $id = $args['id'];

    $car = $carRepository->find($id);

    if (is_null($car)) {
        return $response->write('Page not found')->withStatus(404);
    }

    $carData = $request->getParsedBodyParam('car');
    $validator = new CarValidator();
    $errors = $validator->validate($carData);

    if (count($errors) === 0) {
        $car->setMake($carData['make']);
        $car->setModel($carData['model']);
        $carRepository->save($car);
        $this->get('flash')->addMessage('success', "Car was updated successfully");
        return $response->withRedirect($router->urlFor('cars.show', $args));
    }

    $params = [
        'car' => $car,
        'errors' => $errors
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'cars/edit.phtml', $params);
});

$app->run();
