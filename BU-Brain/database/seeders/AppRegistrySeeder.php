<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppRegistrySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('app_registry')->truncate();

        DB::table('app_registry')->insert([
            // [
            //     'name'              => 'app-one,
            //     'description'       => 'Describe what this app does in one sentence',
            //     'source_path'       => '/home/you/projects/app-two',
            //     'stack'             => 'node',
            //     'talks_to'          => json_encode(['app-one']),
            //     'file_filter_rules' => json_encode([
            //         'extensions'    => ['.js', '.ts', '.sql'],
            //         'include_paths' => ['src/', 'routes/', 'controllers/', 'services/'],
            //         'exclude_paths' => ['node_modules/', 'dist/', 'build/', '.git/'],
            //         'exclude_files' => ['package-lock.json', 'yarn.lock'],
            //     ]),
            //     'created_at'        => now(),
            //     'updated_at'        => now(),
            // ],
            // [
            //     'name'              => 'app-two',
            //     'description'       => 'Describe what this app does in one sentence',
            //     'source_path'       => '/home/you/projects/app-three',
            //     'stack'             => 'python',
            //     'talks_to'          => json_encode(['app-one']),
            //     'file_filter_rules' => json_encode([
            //         'extensions'    => ['.py', '.sql'],
            //         'include_paths' => ['src/', 'app/', 'api/', 'services/'],
            //         'exclude_paths' => ['.venv/', 'venv/', '__pycache__/', '.git/'],
            //         'exclude_files' => ['requirements.txt'],
            //     ]),
            //     'created_at'        => now(),
            //     'updated_at'        => now(),
            // ],
        ]);
    }
}
