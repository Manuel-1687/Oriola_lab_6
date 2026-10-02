<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MigrationController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('migration');
    }

    public function before_action()
    {
        if (defined('IS_CLI') && IS_CLI) {
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        $api = $this->call->library('api');
        $payload = $api->require_jwt();

        if (($payload['type'] ?? '') !== 'access' || ($payload['role'] ?? '') !== 'admin') {
            $api->respond_error('Admin access required.', 403);
        }
    }

    public function create_migration($migration_class)
    {
        $this->migration->create_migration($migration_class);
    }

    public function migrate()
    {
        $this->migration->migrate();
    }

    public function rollback()
    {
        $this->migration->rollback();
    }

    public function rollback_all()
    {
        $this->migration->rollback_all();
    }

    public function refresh()
    {
        $this->migration->refresh();
    }

    public function status()
    {
        $this->migration->status();
    }
}