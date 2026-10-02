<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Products extends Controller
{
    private function services()
    {
        header('Content-Type: application/json; charset=utf-8');

        return [
            $this->call->library('api'),
            $this->call->database(),
        ];
    }

    private function product_input($api)
    {
        $input = $api->body();
        $name = trim((string) ($input['product_name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $price = $input['price'] ?? null;
        $quantity = $input['quantity'] ?? null;

        if ($name === '' || strlen($name) > 100) {
            $api->respond_error('Product name is required and must be at most 100 characters.', 422);
        }
        if (!is_numeric($price) || (float) $price < 0) {
            $api->respond_error('Price must be a non-negative number.', 422);
        }
        if (filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 0) {
            $api->respond_error('Quantity must be a non-negative integer.', 422);
        }

        return [
            'product_name' => $name,
            'description' => $description,
            'price' => number_format((float) $price, 2, '.', ''),
            'quantity' => (int) $quantity,
        ];
    }

    private function require_user($api)
    {
        $payload = $api->require_jwt();
        if (($payload['type'] ?? '') !== 'access') {
            $api->respond_error('Unauthorized', 401);
        }

        return $payload;
    }

    private function respond_with_token($api, $user)
    {
        $token = $api->encode_jwt([
            'sub' => (int) $user['id'],
            'type' => 'access',
            'role' => $user['role'],
            'username' => $user['username'],
        ]);

        $api->respond([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => (int) (getenv('JWT_TTL') ?: 28800),
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
            ],
        ]);
    }

    public function setup_status()
    {
        [$api, $db] = $this->services();
        $api->require_method('GET');

        $count = (int) $db->raw('SELECT COUNT(*) FROM users')->fetchColumn();
        $api->respond(['setup_required' => $count === 0]);
    }

    public function bootstrap()
    {
        [$api, $db] = $this->services();
        $api->require_method('POST');
        $api->rate_limit('bootstrap', 5, 300);

        $input = $api->body();
        $username = trim((string) ($input['username'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || strlen($username) > 100) {
            $api->respond_error('Username is required and must be at most 100 characters.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            $api->respond_error('A valid email address is required.', 422);
        }
        if (strlen($password) < 12) {
            $api->respond_error('Password must be at least 12 characters.', 422);
        }

        $count = (int) $db->raw('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count !== 0) {
            $api->respond_error('Initial administrator setup is already complete.', 409);
        }

        $db->raw(
            'INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)',
            [$username, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']
        );
        $user = $db->raw(
            'SELECT id, username, email, role FROM users WHERE id = ?',
            [(int) $db->last_id()]
        )->fetch(PDO::FETCH_ASSOC);

        $this->respond_with_token($api, $user);
    }

    public function login()
    {
        [$api, $db] = $this->services();
        $api->require_method('POST');
        $input = $api->body();
        $login = trim((string) ($input['login'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($login === '' || $password === '') {
            $api->respond_error('Email/username and password are required.', 422);
        }

        $statement = $db->raw(
            'SELECT id, username, email, password, role, is_active FROM users WHERE email = ? OR username = ? LIMIT 1',
            [$login, $login]
        );
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password'])) {
            $api->respond_error('Invalid credentials.', 401);
        }

        $this->respond_with_token($api, $user);
    }

    public function index()
    {
        [$api, $db] = $this->services();
        $api->require_method('GET');
        $this->require_user($api);

        $statement = $db->raw('SELECT id, product_name, description, price, quantity, created_at FROM products ORDER BY created_at DESC, id DESC');
        $api->respond(['data' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function show($id)
    {
        [$api, $db] = $this->services();
        $api->require_method('GET');
        $this->require_user($api);

        $statement = $db->raw('SELECT id, product_name, description, price, quantity, created_at FROM products WHERE id = ? LIMIT 1', [(int) $id]);
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            $api->respond_error('Product not found.', 404);
        }

        $api->respond(['data' => $product]);
    }

    public function store()
    {
        [$api, $db] = $this->services();
        $api->require_method('POST');
        $this->require_user($api);
        $product = $this->product_input($api);

        $db->raw(
            'INSERT INTO products (product_name, description, price, quantity) VALUES (?, ?, ?, ?)',
            array_values($product)
        );
        $id = (int) $db->last_id();
        $statement = $db->raw('SELECT id, product_name, description, price, quantity, created_at FROM products WHERE id = ?', [$id]);

        $api->respond(['message' => 'Product created.', 'data' => $statement->fetch(PDO::FETCH_ASSOC)], 201);
    }

    public function update($id)
    {
        [$api, $db] = $this->services();
        $api->require_method($_SERVER['REQUEST_METHOD']);
        $this->require_user($api);
        $product = $this->product_input($api);

        $existing = $db->raw('SELECT id FROM products WHERE id = ? LIMIT 1', [(int) $id])->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $api->respond_error('Product not found.', 404);
        }

        $db->raw(
            'UPDATE products SET product_name = ?, description = ?, price = ?, quantity = ? WHERE id = ?',
            [...array_values($product), (int) $id]
        );
        $statement = $db->raw('SELECT id, product_name, description, price, quantity, created_at FROM products WHERE id = ?', [(int) $id]);

        $api->respond(['message' => 'Product updated.', 'data' => $statement->fetch(PDO::FETCH_ASSOC)]);
    }

    public function destroy($id)
    {
        [$api, $db] = $this->services();
        $api->require_method('DELETE');
        $this->require_user($api);

        $statement = $db->raw('DELETE FROM products WHERE id = ?', [(int) $id]);
        if ($statement->rowCount() === 0) {
            $api->respond_error('Product not found.', 404);
        }

        $api->respond(['message' => 'Product deleted.']);
    }
}