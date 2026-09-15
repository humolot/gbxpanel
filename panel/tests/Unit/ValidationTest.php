<?php

namespace Tests\Unit;

use App\Services\CronManager;
use App\Services\FileManager;
use App\Services\FirewallManager;
use App\Services\MysqlManager;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    public function test_paths_are_normalized_lexically(): void
    {
        $this->assertSame('/etc', FileManager::normalize('/www/wwwroot/../../etc'));
        $this->assertSame('/', FileManager::normalize('../../..'));
        $this->assertSame('/www/wwwroot/site', FileManager::normalize('www//wwwroot/./site/'));
    }

    public function test_file_names_cannot_escape_directory(): void
    {
        $this->assertFalse(FileManager::validName('../x'));
        $this->assertFalse(FileManager::validName('..'));
        $this->assertTrue(FileManager::validName('index.php'));
    }

    public function test_cron_expressions(): void
    {
        $this->assertTrue(CronManager::validSchedule('*/5 * * * *'));
        $this->assertTrue(CronManager::validSchedule('0 3 * * 1-5'));
        $this->assertTrue(CronManager::validSchedule('@daily'));
        $this->assertFalse(CronManager::validSchedule('* * * *'));
        $this->assertFalse(CronManager::validSchedule('* * * * * ; rm -rf /'));
    }

    public function test_mysql_identifiers_and_quoting(): void
    {
        $this->assertTrue(MysqlManager::validIdentifier('shop_db'));
        $this->assertFalse(MysqlManager::validIdentifier('shop`; DROP'));
        $this->assertSame("'it\\'s'", MysqlManager::quote("it's"));
    }

    public function test_firewall_sources(): void
    {
        $fw = new FirewallManager;
        $this->assertTrue($fw->validSource('203.0.113.10'));
        $this->assertTrue($fw->validSource('203.0.113.0/24'));
        $this->assertFalse($fw->validSource('203.0.113.0/33'));
        $this->assertFalse($fw->validSource('any; reboot'));
    }
    public function test_www_alias_is_suggested_only_for_apex_domains(): void
    {
        foreach (['example.com', 'goodbits.tech', 'example.com.br', 'example.co.uk'] as $apex) {
            $this->assertTrue(\App\Models\Website::isApexDomain($apex), $apex);
        }
        foreach (['pruebas.goodbits.tech', 'shop.example.com', 'api.v2.example.com', 'shop.example.com.br'] as $sub) {
            $this->assertFalse(\App\Models\Website::isApexDomain($sub), $sub);
        }
    }
}
