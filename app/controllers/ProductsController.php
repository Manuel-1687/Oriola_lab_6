<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ProductsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->database();
    }

    public function create_account()
    {
        $this->api->rate_limit('create-account:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 300);
        $input = $this->request_data();
        $username = trim((string) ($input['username'] ?? ''));
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($input['password'] ?? '');

        if (strlen($username) < 3 || strlen($username) > 100 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            $this->api->respond_error('Username must be 3-100 characters and use only letters, numbers, dots, underscores, or hyphens.', 422);
        }

        if (!$email || strlen($email) > 255) {
            $this->api->respond_error('A valid email address is required.', 422);
        }

        if (strlen($password) < 8) {
            $this->api->respond_error('Password must be at least 8 characters.', 422);
        }

        $existing = $this->db->raw(
            'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$username, $email]
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $this->api->respond_error('Username or email is already registered.', 409);
        }

        $this->db->raw(
            'INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            [$username, $email, password_hash($password, PASSWORD_DEFAULT), 'user', 1]
        );

        $created = $this->db->raw(
            'SELECT id FROM users WHERE username = ? LIMIT 1',
            [$username]
        )->fetch(PDO::FETCH_ASSOC);

        $this->api->respond([
            'message' => 'Account created successfully.',
            'user' => [
                'id' => (int) ($created['id'] ?? 0),
                'username' => $username,
                'email' => $email,
                'role' => 'user',
            ],
        ], 201);
    }

    public function login()
    {
        $input = $this->request_data();
        $identifier = trim((string) ($input['identifier'] ?? $input['username'] ?? $input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $this->api->respond_error('Username/email and password are required.', 422);
        }

        $statement = $this->db->raw(
            'SELECT id, username, email, password, role, is_active FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$identifier, $identifier]
        );
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid username/email or password.', 401);
        }

        $tokens = $this->api->issue_tokens([
            'id' => (int) $user['id'],
            'role' => $user['role'],
            'scopes' => ['read', 'write'],
        ]);

        $this->api->respond([
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
            'tokens' => $tokens,
        ]);
    }

    public function logout()
    {
        $input = $this->request_data();
        $refresh_token = (string) ($input['refresh_token'] ?? '');

        if ($refresh_token === '') {
            $this->api->respond_error('Refresh token is required.', 422);
        }

        $this->api->revoke_refresh_token($refresh_token);
        $this->api->respond(['message' => 'Logged out successfully.']);
    }

    public function index()
    {
        $this->authenticated_user();
        $statement = $this->db->raw('SELECT id, product_name, description, price, quantity, created_at FROM products ORDER BY id DESC');
        $this->api->respond(['data' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function store()
    {
        $this->authenticated_user();
        $product = $this->validated_product($this->request_data());

        $this->db->raw(
            'INSERT INTO products (product_name, description, price, quantity) VALUES (?, ?, ?, ?)',
            [$product['product_name'], $product['description'], $product['price'], $product['quantity']]
        );

        $this->api->respond(['message' => 'Product created successfully.'], 201);
    }

    public function update($id)
    {
        $this->authenticated_user();
        $id = filter_var($id, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            $this->api->respond_error('Invalid product ID.', 422);
        }

        $statement = $this->db->raw(
            'SELECT product_name, description, price, quantity FROM products WHERE id = ? LIMIT 1',
            [$id]
        );
        $existing = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $this->api->respond_error('Product not found.', 404);
        }

        $product = $this->validated_product($this->request_data(), $existing);
        $this->db->raw(
            'UPDATE products SET product_name = ?, description = ?, price = ?, quantity = ? WHERE id = ?',
            [$product['product_name'], $product['description'], $product['price'], $product['quantity'], $id]
        );

        $this->api->respond(['message' => 'Product updated successfully.']);
    }

    public function delete($id)
    {
        $this->authenticated_user();
        $id = filter_var($id, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            $this->api->respond_error('Invalid product ID.', 422);
        }

        $this->db->raw('DELETE FROM products WHERE id = ?', [$id]);

        $this->api->respond(['message' => 'Product deleted successfully.']);
    }

    private function authenticated_user()
    {
        $payload = $this->api->require_jwt();
        $statement = $this->db->raw(
            'SELECT id, username, email, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
            [(int) $payload['sub']]
        );
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->api->respond_error('Unauthorized.', 401);
        }

        return $user;
    }

    private function request_data(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : $_POST;
    }

    private function validated_product(array $input, array $existing = []): array
    {
        $product_name = trim((string) ($input['product_name'] ?? $existing['product_name'] ?? ''));
        $description = (string) ($input['description'] ?? $existing['description'] ?? '');
        $price = $input['price'] ?? $existing['price'] ?? null;
        $quantity = filter_var($input['quantity'] ?? $existing['quantity'] ?? null, FILTER_VALIDATE_INT);

        if ($product_name === '' || strlen($product_name) > 100) {
            $this->api->respond_error('Product name is required and must be at most 100 characters.', 422);
        }

        if (!is_numeric($price) || (float) $price < 0) {
            $this->api->respond_error('Price must be a non-negative number.', 422);
        }

        if ($quantity === false || $quantity < 0) {
            $this->api->respond_error('Quantity must be a non-negative integer.', 422);
        }

        return [
            'product_name' => $product_name,
            'description' => $description,
            'price' => number_format((float) $price, 2, '.', ''),
            'quantity' => $quantity,
        ];
    }
}