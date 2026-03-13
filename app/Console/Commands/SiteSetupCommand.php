<?php

namespace App\Console\Commands;

use App\Services\AaPanelService;
use App\Services\OnecloudService;
use Illuminate\Console\Command;
use RuntimeException;

class SiteSetupCommand extends Command
{
    protected $signature = 'site:setup
        {domain : Domain name for the new site (e.g. mysite.our24.ru)}
        {preset? : Preset name from config/presets.json (e.g. main_pg)}
        {schema? : PostgreSQL schema name (sets DB_SCHEMA and search_path)}
        {--php-version=82 : PHP version code for aaPanel (default: 82 for PHP 8.2)}
        {--skip-dns : Skip DNS A-record creation via 1cloud}
        {--skip-aapanel : Skip aaPanel site creation}
        {--skip-composer : Skip composer create-project}
        {--skip-ssl : Skip SSL certificate via aaPanel}
        {--skip-nginx : Skip nginx document root update}
        {--skip-cron : Skip crontab scheduler entry}';

    protected $description = 'Create a new Laravel site: DNS → aaPanel → Laravel install → .env → SSL → nginx → cron';

    public function handle(): int
    {
        // Step 0: Validate config and arguments
        $domain     = $this->argument('domain');
        $presetName = $this->argument('preset');
        $schema     = $this->argument('schema');
        $phpVersion = $this->option('php-version');

        $basePath = config('app.projects_base_path', '/www/wwwroot');
        $sitePath = rtrim($basePath, '/') . '/' . $domain;

        if (!$this->option('skip-dns') || !$this->option('skip-aapanel') || !$this->option('skip-ssl')) {
            if (!config('app.aapanel.url')) {
                $this->error("AAPANEL_URL is not set in .env");
                return 1;
            }
            if (!config('app.aapanel.key')) {
                $this->error("AAPANEL_KEY is not set in .env");
                return 1;
            }
        }

        if (!$this->option('skip-dns')) {
            if (!config('app.onecloud.token')) {
                $this->error("ONECLOUD_TOKEN is not set in .env");
                return 1;
            }
            if (!config('app.aapanel.server_ip')) {
                $this->error("AAPANEL_SERVER_IP is not set in .env");
                return 1;
            }
        }

        $presetConfig = null;
        if ($presetName) {
            $presetConfig = $this->loadPreset($presetName);
            if ($presetConfig === null) {
                return 1;
            }
        }

        $this->info("Setting up site: {$domain}");
        $this->line("Site path: {$sitePath}");
        if ($presetName) {
            $this->line("Preset: {$presetName}");
        }
        if ($schema) {
            $this->line("Schema: {$schema}");
        }
        $this->newLine();

        // Step 1: Create DNS A-record via 1cloud
        if (!$this->option('skip-dns')) {
            if ($this->createDnsRecord($domain) !== 0) {
                return 1;
            }
        }

        // Step 2: Create site in aaPanel
        if (!$this->option('skip-aapanel')) {
            if ($this->createAaPanelSite($domain, $sitePath, $phpVersion) !== 0) {
                return 1;
            }
        }

        // Step 3: Clear aaPanel placeholder files
        if (!$this->option('skip-composer')) {
            $this->info("Step 3: Clearing placeholder files in {$sitePath}...");
            exec("find " . escapeshellarg($sitePath) . " -mindepth 1 -delete 2>&1", $out, $code);
            if ($code !== 0) {
                $this->error("Failed to clear site directory: " . implode("\n", $out));
                return 1;
            }
            $this->info("Directory cleared.");
            $out = [];
        }

        // Step 4: Install fresh Laravel
        if (!$this->option('skip-composer')) {
            if ($this->installLaravel($sitePath) !== 0) {
                return 1;
            }
        }

        // Step 5: Configure .env
        if ($this->configureEnv($sitePath, $domain, $presetConfig, $schema) !== 0) {
            return 1;
        }

        // Step 6: Update database.php search_path (if schema given)
        if ($schema) {
            if ($this->updateSearchPath($sitePath, $schema) !== 0) {
                return 1;
            }
        }

        // Step 7: key:generate + migrate
        if ($this->runArtisanSetup($sitePath) !== 0) {
            return 1;
        }

        // Step 8: File permissions
        if ($this->setPermissions($sitePath) !== 0) {
            return 1;
        }

        // Step 9: Update nginx document root to /public
        if (!$this->option('skip-nginx')) {
            if ($this->updateNginxConfig($domain, $sitePath) !== 0) {
                return 1;
            }
        }

        // Step 10: Obtain SSL certificate via aaPanel
        if (!$this->option('skip-ssl')) {
            if ($this->applySsl($domain, $sitePath) !== 0) {
                return 1;
            }
        }

        // Step 11: Add scheduler to crontab
        if (!$this->option('skip-cron')) {
            if ($this->addCronEntry($sitePath) !== 0) {
                return 1;
            }
        }

        // Step 12: Summary
        $this->newLine();
        $this->info("=== Site setup complete! ===");
        $this->line("Domain:  {$domain}");
        $this->line("Path:    {$sitePath}");
        $sslApplied = !$this->option('skip-ssl');
        $this->line("URL:     " . ($sslApplied ? 'https' : 'http') . "://{$domain}");
        if ($presetName) {
            $this->line("Preset:  {$presetName}");
        }
        if ($schema) {
            $this->line("Schema:  {$schema}");
        }

        return 0;
    }

    private function createDnsRecord(string $domain): int
    {
        $this->info("Step 1: Creating DNS A-record for {$domain}...");

        // Extract subdomain: "mysite.our24.ru" → "mysite", root zone "our24.ru"
        $parts     = explode('.', $domain);
        $tld       = implode('.', array_slice($parts, -2)); // e.g. "our24.ru"
        $subdomain = implode('.', array_slice($parts, 0, -2)); // e.g. "mysite"

        if (empty($subdomain)) {
            $this->error("Cannot determine subdomain from domain: {$domain}");
            return 1;
        }

        $serverIp = config('app.aapanel.server_ip');

        try {
            $service = new OnecloudService(config('app.onecloud.token'));
            $zone    = $service->findZone($tld);
            $zoneId  = $zone['ID'] ?? $zone['id'] ?? null;

            if (!$zoneId) {
                $this->error("Could not determine zone ID from 1cloud response");
                return 1;
            }

            $record = $service->addARecord((int) $zoneId, $subdomain, $serverIp);
            $this->info("DNS A-record created: {$subdomain}.{$tld} → {$serverIp}");
            $this->line("Record ID: " . ($record['ID'] ?? $record['id'] ?? 'unknown'));
        } catch (RuntimeException $e) {
            $this->error("DNS creation failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function createAaPanelSite(string $domain, string $sitePath, string $phpVersion): int
    {
        $this->info("Step 2: Creating site in aaPanel for {$domain}...");

        try {
            $service = new AaPanelService(config('app.aapanel.url'), config('app.aapanel.key'));
            $service->createSite($domain, $sitePath, $phpVersion);
            $this->info("aaPanel site created.");
        } catch (RuntimeException $e) {
            $this->error("aaPanel site creation failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function installLaravel(string $sitePath): int
    {
        $this->info("Step 4: Installing Laravel via composer create-project...");

        $cmd = "cd " . escapeshellarg($sitePath) . " && composer create-project laravel/laravel . 2>&1";
        exec($cmd, $out, $code);

        $this->line(implode("\n", $out));

        if ($code !== 0) {
            $this->error("composer create-project failed.");
            return 1;
        }

        $this->info("Laravel installed.");
        return 0;
    }

    private function configureEnv(string $sitePath, string $domain, ?array $presetConfig, ?string $schema): int
    {
        $this->info("Step 5: Configuring .env...");

        $envPath = $sitePath . '/.env';

        if (!file_exists($envPath)) {
            $this->error(".env file not found at: {$envPath}");
            return 1;
        }

        if ($presetConfig) {
            $dbConfig = $presetConfig;
            $this->line("Using preset DB configuration.");
        } else {
            $dbConnection = $this->choice('Database driver?', ['pgsql', 'mysql', 'sqlite'], 0);
            $dbHost       = $this->ask('DB_HOST', '127.0.0.1');
            $dbPort       = $this->ask('DB_PORT', $dbConnection === 'pgsql' ? '5432' : '3306');
            $dbName       = $this->ask('DB_DATABASE');
            $dbUser       = $this->ask('DB_USERNAME');
            $dbPassword   = $this->secret('DB_PASSWORD') ?? '';

            $dbConfig = [
                'DB_CONNECTION' => $dbConnection,
                'DB_HOST'       => $dbHost,
                'DB_PORT'       => $dbPort,
                'DB_DATABASE'   => $dbName,
                'DB_USERNAME'   => $dbUser,
                'DB_PASSWORD'   => $dbPassword,
            ];
        }

        $envContent = file_get_contents($envPath);

        // Set APP_URL
        $envContent = preg_replace('/^APP_URL=.*/m', "APP_URL=http://{$domain}", $envContent);

        // Set DB values (wrap password in quotes to handle special chars)
        foreach ($dbConfig as $key => $value) {
            if ($key === 'DB_PASSWORD') {
                $value = '"' . $value . '"';
            }
            $pattern    = '/^' . preg_quote($key, '/') . '=.*/m';
            $envContent = preg_replace($pattern, "{$key}={$value}", $envContent);
        }

        // Add DB_SCHEMA if schema provided
        if ($schema) {
            if (preg_match('/^DB_SCHEMA=.*/m', $envContent)) {
                $envContent = preg_replace('/^DB_SCHEMA=.*/m', "DB_SCHEMA={$schema}", $envContent);
            } else {
                $envContent .= "\nDB_SCHEMA={$schema}\n";
            }
        }

        file_put_contents($envPath, $envContent);
        $this->info(".env configured.");
        return 0;
    }

    private function updateSearchPath(string $sitePath, string $schema): int
    {
        $this->info("Step 6: Setting PostgreSQL search_path to '{$schema}'...");

        $dbConfigPath = $sitePath . '/config/database.php';

        if (!file_exists($dbConfigPath)) {
            $this->error("config/database.php not found at: {$dbConfigPath}");
            return 1;
        }

        $content = file_get_contents($dbConfigPath);
        $updated = str_replace("'search_path' => 'public',", "'search_path' => env('DB_SCHEMA', 'public'),", $content);

        if ($updated === $content) {
            // Try double-quoted variant
            $updated = str_replace('"search_path" => "public",', "'search_path' => env('DB_SCHEMA', 'public'),", $content);
        }

        if ($updated === $content) {
            $this->warn("Could not find 'search_path' in config/database.php. Manual update required.");
            return 0;
        }

        file_put_contents($dbConfigPath, $updated);
        $this->info("search_path updated to use DB_SCHEMA env variable.");
        return 0;
    }

    private function runArtisanSetup(string $sitePath): int
    {
        $this->info("Step 7: Running key:generate and migrate...");

        $commands = [
            "php artisan key:generate",
            "php artisan migrate --force",
        ];

        foreach ($commands as $cmd) {
            $this->line("Running: {$cmd}");
            $fullCmd = "cd " . escapeshellarg($sitePath) . " && {$cmd} 2>&1";
            exec($fullCmd, $out, $code);
            $this->line(implode("\n", $out));
            $out = [];

            if ($code !== 0) {
                $this->error("Command failed: {$cmd}");
                return 1;
            }
        }

        return 0;
    }

    private function setPermissions(string $sitePath): int
    {
        $this->info("Step 8: Setting file permissions...");

        $commands = [
            "chmod -R 755 " . escapeshellarg($sitePath . '/storage'),
            "chmod -R 755 " . escapeshellarg($sitePath . '/bootstrap/cache'),
            "chown -R www:www " . escapeshellarg($sitePath),
        ];

        foreach ($commands as $cmd) {
            exec($cmd . " 2>&1", $out, $code);
            if ($code !== 0) {
                $this->error("Permission command failed: {$cmd}\n" . implode("\n", $out));
                return 1;
            }
            $out = [];
        }

        $this->info("Permissions set.");
        return 0;
    }

    private function updateNginxConfig(string $domain, string $sitePath): int
    {
        $this->info("Step 9: Updating nginx document root to /public...");

        $nginxConf = "/www/server/panel/vhost/nginx/{$domain}.conf";

        if (!file_exists($nginxConf)) {
            $this->error("nginx config not found: {$nginxConf}");
            return 1;
        }

        $content = file_get_contents($nginxConf);
        $updated = str_replace("root {$sitePath};", "root {$sitePath}/public;", $content);

        if ($updated === $content) {
            // Fallback: regex for varying whitespace
            $updated = preg_replace(
                '#(root\s+' . preg_quote($sitePath, '#') . ')(;)#',
                '$1/public$2',
                $content
            );
        }

        if ($updated === $content) {
            $this->error("Could not find root directive in nginx config.");
            $this->line("Expected: root {$sitePath};");
            $this->line("File: {$nginxConf}");
            return 1;
        }

        file_put_contents($nginxConf, $updated);
        $this->info("nginx config updated.");

        exec("nginx -s reload 2>&1", $out, $code);
        if ($code !== 0) {
            $this->error("nginx reload failed: " . implode("\n", $out));
            return 1;
        }

        $this->info("nginx reloaded.");
        return 0;
    }

    private function applySsl(string $domain, string $sitePath): int
    {
        $this->info("Step 10: Obtaining SSL certificate for {$domain}...");

        try {
            $service = new AaPanelService(config('app.aapanel.url'), config('app.aapanel.key'));
            $service->applySsl($domain);
            $this->info("SSL certificate obtained.");
        } catch (RuntimeException $e) {
            $this->error("SSL certificate failed: " . $e->getMessage());
            return 1;
        }

        // Update APP_URL to https
        $envPath = $sitePath . '/.env';
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
            $envContent = preg_replace('/^APP_URL=http:\/\//m', "APP_URL=https://", $envContent);
            file_put_contents($envPath, $envContent);
            $this->line("APP_URL updated to https://");
        }

        return 0;
    }

    private function addCronEntry(string $sitePath): int
    {
        $this->info("Step 11: Adding Laravel scheduler to crontab...");

        $cronEntry = "* * * * * cd {$sitePath} && php artisan schedule:run >> /dev/null 2>&1";

        exec("crontab -u www -l 2>/dev/null", $existing, $code);
        $existingCron = implode("\n", $existing);

        if (str_contains($existingCron, $sitePath . ' && php artisan schedule:run')) {
            $this->line("Crontab entry already exists, skipping.");
            return 0;
        }

        $newCron  = trim($existingCron) . "\n" . $cronEntry . "\n";
        $tmpFile  = sys_get_temp_dir() . '/cron_' . md5($sitePath) . '_' . time();

        file_put_contents($tmpFile, $newCron);
        exec("crontab -u www " . escapeshellarg($tmpFile) . " 2>&1", $out, $code);
        unlink($tmpFile);

        if ($code !== 0) {
            $this->error("Failed to update crontab: " . implode("\n", $out));
            return 1;
        }

        $this->info("Crontab entry added.");
        return 0;
    }

    private function loadPreset(string $name): ?array
    {
        $presetsFile = base_path('config/presets.json');

        if (!file_exists($presetsFile)) {
            $this->error("Presets file not found: {$presetsFile}");
            return null;
        }

        $presets = json_decode(file_get_contents($presetsFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON in config/presets.json");
            return null;
        }

        if (!isset($presets[$name])) {
            $available = implode(', ', array_keys($presets));
            $this->error("Preset '{$name}' not found. Available: {$available}");
            return null;
        }

        return $presets[$name];
    }
}
