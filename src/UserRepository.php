<?php

namespace App;
class UserRepository
{
    public function getAllUsers()
    {
        $usersJson = $_COOKIE['users'] ?? '[]';
        return json_decode($usersJson, true);
    }

    public function save($user): void
    {
        $users = $this->getAllUsers();
        $users[] = ['id' => uniqid(), ...$user];
        setcookie('users', json_encode($users), time()+3600, '/');
    }

    public function findUserById($userId)
    {
        $users = $this->getAllUsers();
        foreach ($users as $user) {
            if ($user['id'] == $userId) {
                return $user;
            }
        }
        return null;
    }

    public function filteredByName($term): array
    {
        $users = $this->getAllUsers();
        $result = [];
        foreach ($users as $user) {
            if (str_contains(strtolower($user['name']), strtolower($term))) {
                $result[] = $user;
            }
        }
        return $result;

    }

    public function findByEmail($email)
    {
        $users = $this->getAllUsers();
        foreach ($users as $user) {
            if ($user['email'] == $email) {
                return $user;
            }
        }
        return null;
    }

    public function editUser($userData, $userId): void
    {
        $allUsers = $this->getAllUsers();
        $updated = false;

        foreach ($allUsers as &$user) {
            if ($user['id'] === $userId) {
                $user = array_merge($user, $userData);
                $updated = true;
                break;
            }
        }

        if ($updated) {
            setcookie('users', json_encode($allUsers), time() + 3600, '/');
        }

    }

    public function destroy($userId): void
    {
        $users = $this->getAllUsers();
        $initialCount = count($users);

        $users = array_filter($users, function ($user) use ($userId) {
            return !(isset($user['id']) && $user['id'] === $userId);
        });

        if($initialCount != count($users)) {
            setcookie('users', json_encode($users), time()+3600, '/');
        }
    }
}
