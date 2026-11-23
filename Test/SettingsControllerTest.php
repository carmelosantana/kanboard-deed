<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Core\Controller\Runner;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Middleware\ApplicationAuthorizationMiddleware;
use Kanboard\Middleware\ProjectAuthorizationMiddleware;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\HttpResponse;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use RuntimeException;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

abstract class DeedPageTestCase extends Base
{
    use ReleasesConnection;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();

        $this->container['configModel']->save(['deed_last_sweep' => date('Y-m-d')]);
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->never())->method('fetch');
        $this->container['deedHttp'] = $fetcher;
    }

    protected function dispatch(string $path, string $role, ?array $post = null): array
    {
        $user = $this->container['userModel']->getById(1);
        $user['role'] = $role;
        $this->container['userSession']->initialize($user);
        if ($post !== null && !array_key_exists('csrf_token', $post)) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        $this->container['request'] = new Request($this->container, [
            'REQUEST_URI' => '/'.$path,
            'REQUEST_METHOD' => $post === null ? 'GET' : 'POST',
            'SCRIPT_NAME' => '/index.php',
        ], [], $post ?? []);
        $this->container['response'] = $response = new class($this->container) extends Response {
            public int $code = 200;
            public string $body = '';

            public function withStatusCode($statusCode)
            {
                $this->code = $statusCode;
                return parent::withStatusCode($statusCode);
            }

            public function withBody($body)
            {
                $this->body = $body;
                return parent::withBody($body);
            }

            public function send()
            {
            }
        };

        $router = $this->container['router'];
        $router->dispatch();
        $this->assertSame('Deed', $router->getPlugin());

        (new class($this->container) extends Runner {
            protected function executeMiddleware()
            {
                $application = new ApplicationAuthorizationMiddleware($this->container);
                $application->setNextMiddleware(new ProjectAuthorizationMiddleware($this->container));
                $application->execute();
            }
        })->execute();

        return [$response->code, $response->body];
    }
}

class SettingsControllerTest extends DeedPageTestCase
{
    private const VALUES = [
        'deed_default_project_id' => '0',
        'deed_thresholds' => '30,14,7',
        'deed_refresh_hour' => '0',
        'deed_batch_size' => '200',
    ];

    public function testAdminCanOpenSettings(): void
    {
        [$status, $body] = $this->dispatch('deed/settings', Role::APP_ADMIN);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('>Deed</a>', $body);
        $this->assertStringContainsString('value="30,14,7"', $body);
        $this->assertStringContainsString('Refresh bootstrap', $body);
    }

    /** @dataProvider restrictedRoutes */
    public function testSettingsAreAdminOnly(string $role, string $path, ?array $post): void
    {
        [$status] = $this->dispatch($path, $role, $post);
        $this->assertSame(403, $status);
    }

    public static function restrictedRoutes(): iterable
    {
        foreach ([Role::APP_MANAGER, Role::APP_USER] as $role) {
            yield [$role, 'deed/settings', null];
            yield [$role, 'deed/settings/save', self::VALUES];
            yield [$role, 'deed/settings/refresh-bootstrap', []];
        }
    }

    /** @dataProvider writeRoutes */
    public function testWritesRequirePostAndCsrf(string $path, ?array $post, int $expected): void
    {
        [$status] = $this->dispatch($path, Role::APP_ADMIN, $post);
        $this->assertSame($expected, $status);
    }

    public static function writeRoutes(): iterable
    {
        foreach (['save', 'refresh-bootstrap'] as $action) {
            yield ['deed/settings/'.$action, null, 405];
            yield ['deed/settings/'.$action, ['csrf_token' => null], 403];
            yield ['deed/settings/'.$action, ['csrf_token' => 'invalid'], 403];
        }
    }

    public function testValidSettingsAreSaved(): void
    {
        $projectId = $this->container['projectModel']->create(['name' => 'Renewals']);
        $values = ['deed_default_project_id' => (string) $projectId] + self::VALUES;
        [$status] = $this->dispatch('deed/settings/save', Role::APP_ADMIN, $values + ['application_url' => 'ignored']);
        $this->assertSame(303, $status);
        foreach ($values as $key => $value) {
            $this->assertSame($value, (string) $this->container['configModel']->getOption($key));
        }
        $this->assertNotSame('ignored/', $this->container['configModel']->getOption('application_url'));
    }

    /** @dataProvider invalidSettings */
    public function testInvalidSettingsAreRejectedWithoutSaving(string $field, mixed $value): void
    {
        [$status] = $this->dispatch('deed/settings/save', Role::APP_ADMIN, [$field => $value] + self::VALUES);
        $this->assertSame(400, $status);
        foreach (array_keys(self::VALUES) as $key) {
            $this->assertFalse($this->container['configModel']->exists($key));
        }
    }

    public static function invalidSettings(): iterable
    {
        foreach (['7,30', '30,30', '30,0', '30,-1', '30,1.5', '30,', '', ['30', '7']] as $value) {
            yield ['deed_thresholds', $value];
        }
        yield 'threshold list exceeds settings column' => ['deed_thresholds', implode(',', range(100, 1))];
        yield ['deed_refresh_hour', '-1'];
        yield ['deed_refresh_hour', '24'];
        yield ['deed_batch_size', '0'];
        yield ['deed_batch_size', '201'];
        yield ['deed_default_project_id', '999999'];
    }

    public function testRefreshRetriesPinnedFailureAndRevalidatesFreshCache(): void
    {
        $requests = [];
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->exactly(5))->method('fetch')
            ->willReturnCallback(static function (string $url, array $headers) use (&$requests): HttpResponse {
                $file = basename($url);
                $requests[$file][] = $headers;
                if ($file === 'dns.json' && count($requests[$file]) === 1) {
                    return new HttpResponse(503, '');
                }
                if ($file === 'ipv4.json') {
                    return new HttpResponse(304, '');
                }
                return new HttpResponse(200, file_get_contents(__DIR__.'/fixtures/iana-'.$file));
            });
        $this->container['deedHttp'] = $fetcher;
        $previousBody = file_get_contents(__DIR__.'/fixtures/iana-ipv4.json');
        $this->container['deedCache']->save([
            'cache_key' => 'bootstrap:ipv4',
            'url' => 'https://data.iana.org/rdap/ipv4.json',
            'body' => $previousBody,
            'etag' => '"ipv4"',
            'last_modified' => null,
            'fetched_at' => time(),
            'expires_at' => time() + 86400,
            'http_status' => 200,
        ], null);

        try {
            $this->container['deedBootstrap']->baseUrlsForDomain('example.com');
            $this->fail('The first lookup should fail with an empty cache.');
        } catch (RuntimeException $exception) {
            $this->assertSame('bootstrap_unavailable', $exception->getMessage());
        }

        [$status] = $this->dispatch('deed/settings/refresh-bootstrap', Role::APP_ADMIN, []);
        $this->assertSame(303, $status);
        $this->assertCount(2, $requests['dns.json']);
        $this->assertSame([['If-None-Match' => '"ipv4"']], $requests['ipv4.json']);
        $this->assertSame($previousBody, $this->container['deedCache']->get('bootstrap:ipv4')['body']);
        $this->assertNotNull($this->container['deedCache']->get('bootstrap:dns'));
        $this->assertNotNull($this->container['deedCache']->get('bootstrap:ipv6'));
        $this->assertNotNull($this->container['deedCache']->get('bootstrap:asn'));
    }
}
