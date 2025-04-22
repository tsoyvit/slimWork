<?php

namespace App;

class Validator
{
    public function validate(array $user): array
    {
        $errors = [];

        if (empty($user['name'])) {
            $errors['name'] = 'Имя не может быть пустым';
        }
        if (strlen($user['name']) < 4) {
            $errors['name'] = 'Nickname must be grater than 4 characters';
        }

        if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Некорректный email';
        }

        return $errors;
    }
}
